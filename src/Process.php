<?php declare(strict_types=1);

namespace WilliamEggers\React\SSHClient;

use React\Promise\Deferred;
use React\Promise\PromiseInterface;
use React\Stream\ReadableStreamInterface;
use React\Stream\WritableStreamInterface;
use WilliamEggers\React\SSHClient\Channel\SessionChannel;

final class Process
{
    /**
     * Wrap a session channel running a remote command.
     */
    public function __construct(private readonly SessionChannel $channel) {}

    /**
     * Return the writable stdin stream for the remote process.
     */
    public function stdin(): WritableStreamInterface
    {
        return $this->channel;
    }

    /**
     * Return the readable stdout stream for the remote process.
     */
    public function stdout(): ReadableStreamInterface
    {
        return $this->channel->stdout();
    }

    /**
     * Return the readable stderr stream for the remote process.
     */
    public function stderr(): ReadableStreamInterface
    {
        return $this->channel->stderr();
    }

    /**
     * Resolve once the remote process exits.
     *
     * @return PromiseInterface<int>
     */
    public function join(): PromiseInterface
    {
        /** @var Deferred<int> $deferred */
        $deferred = new Deferred();
        $this->channel->once('exit', function (int $exitCode) use ($deferred): void {
            $deferred->resolve($exitCode);
        });

        return $deferred->promise();
    }

    /**
     * Close the remote process channel.
     */
    public function close(): void
    {
        $this->channel->close();
    }
}
