<?php declare(strict_types=1);

namespace WilliamEggers\React\SSHClient\Channel;

use Evenement\EventEmitterInterface;
use Evenement\EventEmitterTrait;
use React\Promise\PromiseInterface;
use React\Stream;
use React\Stream\DuplexStreamInterface;
use React\Stream\ReadableStreamInterface;
use React\Stream\Util;
use React\Stream\WritableStreamInterface;
use WilliamEggers\React\SSHClient\Connection;
use WilliamEggers\React\SSHClient\Values\WinSize;

final class SessionChannel implements EventEmitterInterface, ReadableStreamInterface, WritableStreamInterface
{
    use EventEmitterTrait;

    private DuplexStreamInterface $stdout;
    private DuplexStreamInterface $stderr;
    private bool $inputClosed = false;
    private bool $outputClosed = false;
    private bool $closeSent = false;
    private bool $closeReceived = false;
    private int $exitCode = -1;

    public function __construct(
        private readonly Connection $connection,
        private readonly int $localChannel,
        private readonly int $remoteChannel,
        private int $remoteWindow,
        private readonly int $maxPacketSize,
    ) {
        $this->stdout = new Stream\ThroughStream();
        $this->stderr = new Stream\ThroughStream();

        Util::forwardEvents($this->stdout, $this, ['data', 'end', 'error', 'close', 'pipe', 'drain']);
    }

    public function getLocalChannel(): int
    {
        return $this->localChannel;
    }

    public function getRemoteChannel(): int
    {
        return $this->remoteChannel;
    }

    public function getExitCode(): int
    {
        return $this->exitCode;
    }

    public function setExitCode(int $exitCode): void
    {
        $this->exitCode = $exitCode;
    }

    public function stdout(): ReadableStreamInterface
    {
        return $this->stdout;
    }

    public function stderr(): ReadableStreamInterface
    {
        return $this->stderr;
    }

    public function writeStdout(string $data): void
    {
        $this->stdout->write($data);
    }

    public function writeStderr(string $data): void
    {
        $this->stderr->write($data);
    }

    public function receiveEof(): void
    {
        if ($this->inputClosed) {
            return;
        }

        $this->inputClosed = true;
        $this->stdout->end();
        $this->stderr->end();
        $this->emit('eof');
    }

    public function receiveClose(): void
    {
        if ($this->closeReceived) {
            return;
        }

        $this->closeReceived = true;
        $this->stdout->close();
        $this->stderr->close();
        $this->emit('exit', [$this->exitCode]);
        $this->emit('close');
        $this->removeAllListeners();
    }

    public function increaseRemoteWindow(int $bytes): void
    {
        $this->remoteWindow += $bytes;
    }

    /**
     * @return PromiseInterface<bool>
     */
    public function requestEnvironmentVariable(string $name, string $value): PromiseInterface
    {
        return $this->connection->sendChannelRequest($this, 'env', true, [$name, $value]);
    }

    /**
     * @return PromiseInterface<bool>
     */
    public function requestPty(string $term, WinSize $size): PromiseInterface
    {
        return $this->connection->sendChannelRequest($this, 'pty-req', true, [
            $term,
            $size->cols,
            $size->rows,
            $size->widthPixels,
            $size->heightPixels,
            '',
        ]);
    }

    /**
     * @return PromiseInterface<bool>
     */
    public function requestShell(): PromiseInterface
    {
        return $this->connection->sendChannelRequest($this, 'shell', true);
    }

    /**
     * @return PromiseInterface<bool>
     */
    public function requestExec(string $command): PromiseInterface
    {
        return $this->connection->sendChannelRequest($this, 'exec', true, [$command]);
    }

    public function resize(WinSize $size): void
    {
        $this->connection->sendChannelRequest($this, 'window-change', false, [
            $size->cols,
            $size->rows,
            $size->widthPixels,
            $size->heightPixels,
        ]);
    }

    public function write(mixed $data): bool
    {
        if (!is_scalar($data) && !(
            is_object($data)
            && method_exists($data, '__toString')
        )) {
            throw new \InvalidArgumentException('Data must be stringable');
        }

        return $this->connection->writeChannelData($this, (string) $data) > 0;
    }

    public function end(mixed $data = null): void
    {
        if (!is_null($data)) {
            $this->write($data);
        }

        if (!$this->outputClosed) {
            $this->outputClosed = true;
            $this->connection->sendChannelEof($this);
        }

        $this->close();
    }

    public function close(): void
    {
        if ($this->closeSent) {
            return;
        }

        $this->closeSent = true;
        $this->connection->closeChannel($this);
    }

    public function pause(): void
    {
        $this->stdout->pause();
    }

    public function resume(): void
    {
        $this->stdout->resume();
    }

    public function isReadable(): bool
    {
        return $this->stdout->isReadable();
    }

    public function isWritable(): bool
    {
        return !$this->outputClosed;
    }

    /**
     * @param array<string, mixed> $options
     */
    public function pipe(WritableStreamInterface $dest, array $options = []): WritableStreamInterface
    {
        return $this->stdout->pipe($dest, $options);
    }

    public function consumeRemoteWindow(int $bytes): void
    {
        $this->remoteWindow = max(0, $this->remoteWindow - $bytes);
    }

    public function getRemoteWindow(): int
    {
        return $this->remoteWindow;
    }

    public function getMaxPacketSize(): int
    {
        return $this->maxPacketSize;
    }
}
