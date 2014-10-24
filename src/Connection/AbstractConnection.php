<?php

/*
 * This file is part of the Predis\Async package.
 *
 * (c) Daniele Alessandri <suppakilla@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Predis\Async\Connection;

use InvalidArgumentException;
use SplQueue;
use Predis\Command\CommandInterface;
use Predis\Connection\ParametersInterface;
use Predis\Async\Buffer\StringBuffer;
use React\EventLoop\LoopInterface;

abstract class AbstractConnection implements ConnectionInterface
{
    protected $parameters;
    protected $loop;
    protected $stream;
    protected $buffer;
    protected $commands;
    protected $state;
    protected $timeout = null;
    protected $errorCallback = null;
    protected $readableCallback = null;
    protected $writableCallback = null;

    /**
     * @param ParametersInterface $parameters
     * @param LoopInterface       $loop
     */
    public function __construct(ParametersInterface $parameters, LoopInterface $loop)
    {
        $this->parameters = $parameters;
        $this->loop = $loop;

        $this->buffer = new StringBuffer();
        $this->commands = new SplQueue();
        $this->readableCallback = array($this, 'read');
        $this->writableCallback = array($this, 'write');

        $this->state = new State();
        $this->state->setProcessCallback($this->getProcessCallback());
    }

    /**
     * Disconnects from the server and destroys the underlying resource when
     * PHP's garbage collector kicks in.
     */
    public function __destruct()
    {
        if ($this->isConnected()) {
           $this->disconnect();
        }
    }

    /**
     * Returns the callback used to handle commands and firing callbacks depending
     * on the current state of the connection to Redis.
     *
     * @return mixed
     */
    protected function getProcessCallback()
    {
        $connection = $this;
        $commands = $this->commands;
        $streamingWrapper = $this->getStreamingWrapperCreator();

        return function ($state, $response) use ($commands, $connection, $streamingWrapper) {
            list($command, $callback) = $commands->dequeue();

            switch ($command->getId()) {
                case 'SUBSCRIBE':
                case 'PSUBSCRIBE':
                    $callback = $streamingWrapper($connection, $callback);
                    $state->setStreamingContext(State::PUBSUB, $callback);
                    break;

                case 'MONITOR':
                    $callback = $streamingWrapper($connection, $callback);
                    $state->setStreamingContext(State::MONITOR, $callback);
                    break;

                case 'MULTI':
                    $state->setState(State::MULTIEXEC);
                    goto process;

                case 'EXEC':
                case 'DISCARD':
                    $state->setState(State::CONNECTED);
                    goto process;

                default:
                process:
                    call_user_func($callback, $response, $connection, $command);
                    break;
            }
        };
    }

    /**
     * Returns a wrapper to the user-provided callback passed to handle response chunks
     * streamed down by replies to commands such as MONITOR, SUBSCRIBE and PSUBSCRIBE.
     *
     * @return mixed
     */
    protected function getStreamingWrapperCreator()
    {
        return function ($connection, $callback) {
            return function ($state, $response) use ($connection, $callback) {
                call_user_func($callback, $response, $connection, null);
            };
        };
    }

    /**
     * Creates the underlying resource used to communicate with Redis.
     *
     * @return mixed
     */
    protected function createResource($connectCallback = null)
    {
        $connection = $this;
        $parameters = $this->parameters;

        $uri = "$parameters->scheme://".($parameters->scheme === 'unix' ? $parameters->path : "$parameters->host:$parameters->port");
        $flags = STREAM_CLIENT_CONNECT | STREAM_CLIENT_ASYNC_CONNECT;

        if (!$stream = @stream_socket_client($uri, $errno, $errstr, 0, $flags)) {
            return $this->onError(new ConnectionException($this, trim($errstr), $errno));
        }

        stream_set_blocking($stream, 0);

        $this->state->setState(State::CONNECTING);

        $this->loop->addWriteStream($stream, function ($stream) use ($connection, $connectCallback) {
            if ($connection->onConnect()) {
                if (isset($connectCallback)) {
                    call_user_func($connectCallback, $connection);
                }

                $connection->write();
            }
        });

        $this->timeout = $this->armTimeoutMonitor($this->parameters->timeout, $this->errorCallback);

        return $stream;
    }

    /**
     * Creates the underlying resource used to communicate with Redis.
     *
     * @param int   $timeout  Timeout in seconds
     * @param mixed $callback Callback invoked on timeout.
     */
    protected function armTimeoutMonitor($timeout, $callback)
    {
        $timer = $this->loop->addTimer($timeout, function ($timer) {
            list($connection, $callback) = $timer->getData();

            $connection->disconnect();

            if (isset($callback)) {
                call_user_func($callback, $connection, new ConnectionException($connection, 'Connection timed out'));
            }
        });

        $timer->setData(array($this, $callback));

        return $timer;
    }

    /**
     * Disarm the timer used to monitor a connect() timeout is set.
     */
    protected function disarmTimeoutMonitor()
    {
        if (isset($this->timeout)) {
            $this->timeout->cancel();
            $this->timeout = null;
        }
    }

    /**
     * {@inheritdoc}
     */
    public function isConnected()
    {
        return isset($this->stream) && stream_socket_get_name($this->stream, true) !== false;
    }

    /**
     * {@inheritdoc}
     */
    public function connect($callback)
    {
        if (!$this->isConnected()) {
            $this->stream = $this->createResource($callback);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function disconnect()
    {
        $this->disarmTimeoutMonitor();

        if (isset($this->stream)) {
            $this->loop->removeStream($this->stream);
            $this->state->setState(State::DISCONNECTED);
            $this->buffer->reset();

            unset($this->stream);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function getResource()
    {
        if (isset($this->stream)) {
            return $this->stream;
        }

        $this->stream = $this->createResource();

        return $this->stream;
    }

    /**
     * {@inheritdoc}
     */
    public function setErrorCallback($callback)
    {
        if (!is_callable($callback)) {
            throw new InvalidArgumentException('The specified callback must be a callable object');
        }

        $this->errorCallback = $callback;
    }

    /**
     * {@inheritdoc}
     */
    public function onConnect()
    {
        $stream = $this->getResource();

        // The following one is a terrible hack but it looks like this is the only way to
        // detect connection refused errors with PHP's stream sockets. Blame PHP as usual.
        if (stream_socket_get_name($stream, true) === false) {
            return $this->onError(new ConnectionException($this, "Connection refused"));
        }

        $this->state->setState(State::CONNECTED);
        $this->disarmTimeoutMonitor();

        $this->loop->removeWriteStream($stream);
        $this->loop->addReadStream($stream, $this->readableCallback);

        return true;
    }

    /**
     * {@inheritdoc}
     */
    protected function onError(\Exception $exception)
    {
        $this->disconnect();

        if (isset($this->errorCallback)) {
            call_user_func($this->errorCallback, $this, $exception);
        }

        return false;
    }

    /**
     * {@inheritdoc}
     */
    public function getParameters()
    {
        return $this->parameters;
    }

    /**
     * {@inheritdoc}
     */
    public function getEventLoop()
    {
        return $this->loop;
    }

    /**
     * Gets an identifier for the connection.
     *
     * @return string
     */
    protected function getIdentifier()
    {
        if ($this->parameters->scheme === 'unix') {
            return $this->parameters->path;
        }

        return "{$this->parameters->host}:{$this->parameters->port}";
    }

    /**
     * {@inheritdoc}
     */
    public function write()
    {
        $stream = $this->getResource();

        if ($this->buffer->isEmpty()) {
            $this->loop->removeWriteStream($stream);

            return;
        }

        $buffer = $this->buffer->read(4096);

        if (-1 === $ret = @stream_socket_sendto($stream, $buffer)) {
            return $this->onError(new ConnectionException($this, 'Error while writing bytes to the server'));
        }

        $this->buffer->discard(min($ret, strlen($buffer)));
    }

    /**
     * {@inheritdoc}
     */
    abstract public function read();

    /**
     * {@inheritdoc}
     */
    abstract public function executeCommand(CommandInterface $command, $callback);

    /**
     * {@inheritdoc}
     */
    public function __toString()
    {
        return $this->getIdentifier();
    }
}