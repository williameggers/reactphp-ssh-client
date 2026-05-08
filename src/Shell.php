<?php declare(strict_types=1);

namespace WilliamEggers\React\SSHClient;

use React\Promise\Deferred;
use React\Promise\PromiseInterface;
use React\Stream\ReadableStreamInterface;
use React\Stream\WritableStreamInterface;
use WilliamEggers\React\SSHClient\Channel\SessionChannel;
use WilliamEggers\React\SSHClient\Values\WinSize;

final class Shell
{
    /**
     * Wrap a PTY-backed interactive shell session.
     */
    public function __construct(private readonly SessionChannel $channel) {}

    /**
     * Return the writable stdin stream for the shell session.
     */
    public function stdin(): WritableStreamInterface
    {
        return $this->channel;
    }

    /**
     * Return the readable stdout stream for the shell session.
     */
    public function stdout(): ReadableStreamInterface
    {
        return $this->channel->stdout();
    }

    /**
     * Return the readable stderr stream for the shell session.
     */
    public function stderr(): ReadableStreamInterface
    {
        return $this->channel->stderr();
    }

    /**
     * Request an updated terminal window size for the remote PTY.
     */
    public function resize(WinSize $size): void
    {
        $this->channel->resize($size);
    }

    /**
     * Resolve once the remote shell exits.
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
     * Close the remote shell channel.
     */
    public function close(): void
    {
        $this->channel->close();
    }
}
