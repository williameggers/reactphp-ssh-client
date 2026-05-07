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
    public function __construct(private readonly SessionChannel $channel) {}

    public function stdin(): WritableStreamInterface
    {
        return $this->channel;
    }

    public function stdout(): ReadableStreamInterface
    {
        return $this->channel->stdout();
    }

    public function stderr(): ReadableStreamInterface
    {
        return $this->channel->stderr();
    }

    public function resize(WinSize $size): void
    {
        $this->channel->resize($size);
    }

    /**
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

    public function close(): void
    {
        $this->channel->close();
    }
}
