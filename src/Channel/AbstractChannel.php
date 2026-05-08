<?php declare(strict_types=1);

namespace WilliamEggers\React\SSHClient\Channel;

use Evenement\EventEmitterTrait;
use React\Stream;
use React\Stream\DuplexStreamInterface;
use React\Stream\Util;
use React\Stream\WritableStreamInterface;
use WilliamEggers\React\SSHClient\Connection;

abstract class AbstractChannel implements ChannelInterface
{
    use EventEmitterTrait;

    private DuplexStreamInterface $stream;
    private bool $inputClosed = false;
    private bool $outputClosed = false;
    private bool $eofSent = false;
    private bool $closeSent = false;
    private bool $closeReceived = false;
    private int $localWindow;
    private int $remoteWindow;

    /** @var list<string> */
    private array $pendingWrites = [];

    public function __construct(
        private readonly Connection $connection,
        private readonly int $localChannel,
        private readonly int $remoteChannel,
        int $initialWindow,
        private readonly int $maxPacketSize,
    ) {
        $this->stream = new Stream\ThroughStream();
        $this->localWindow = $initialWindow;
        $this->remoteWindow = $initialWindow;

        Util::forwardEvents($this->stream, $this, ['data', 'end', 'error', 'close', 'pipe', 'drain']);
    }

    public function getLocalChannel(): int
    {
        return $this->localChannel;
    }

    public function getRemoteChannel(): int
    {
        return $this->remoteChannel;
    }

    public function writeData(string $data): void
    {
        if ($this->inputClosed || '' === $data) {
            return;
        }

        $this->consumeLocalWindow(strlen($data));
        $this->stream->write($data);
        $this->connection->adjustChannelWindow($this, strlen($data));
    }

    public function writeExtendedData(int $dataTypeCode, string $data): void {}

    public function receiveEof(): void
    {
        if ($this->inputClosed) {
            return;
        }

        $this->inputClosed = true;
        $this->stream->end();
        $this->emit('eof');
    }

    public function receiveClose(): void
    {
        if ($this->closeReceived) {
            return;
        }

        $this->closeReceived = true;
        $this->stream->close();
        $this->emit('close');
        $this->removeAllListeners();
    }

    public function increaseRemoteWindow(int $bytes): void
    {
        $this->remoteWindow += $bytes;
        $this->flushPendingWrites();
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

    public function consumeLocalWindow(int $bytes): void
    {
        $this->localWindow = max(0, $this->localWindow - $bytes);
    }

    public function write(mixed $data): bool
    {
        if (!is_scalar($data) && !(is_object($data) && method_exists($data, '__toString'))) {
            throw new \InvalidArgumentException('Data must be stringable');
        }

        $string = (string) $data;
        if ('' === $string) {
            return true;
        }

        $written = $this->connection->writeChannelData($this, $string);
        if ($written < strlen($string)) {
            $remaining = substr($string, $written);
            if ('' !== $remaining) {
                $this->pendingWrites[] = $remaining;
            }
        }

        return $written > 0 || [] !== $this->pendingWrites;
    }

    public function end(mixed $data = null): void
    {
        if (null !== $data) {
            $this->write($data);
        }

        if ($this->outputClosed) {
            return;
        }

        $this->outputClosed = true;
        if ([] === $this->pendingWrites) {
            $this->sendEof();
        }
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
        $this->stream->pause();
    }

    public function resume(): void
    {
        $this->stream->resume();
    }

    public function isReadable(): bool
    {
        return $this->stream->isReadable();
    }

    public function isWritable(): bool
    {
        return !$this->outputClosed && !$this->closeSent;
    }

    /**
     * @param array<string, mixed> $options
     */
    public function pipe(WritableStreamInterface $dest, array $options = []): WritableStreamInterface
    {
        return $this->stream->pipe($dest, $options);
    }

    protected function getConnection(): Connection
    {
        return $this->connection;
    }

    private function flushPendingWrites(): void
    {
        while ([] !== $this->pendingWrites && $this->remoteWindow > 0) {
            $chunk = array_shift($this->pendingWrites);
            if ('' === $chunk) {
                continue;
            }

            $written = $this->connection->writeChannelData($this, $chunk);
            if ($written < strlen($chunk)) {
                $remaining = substr($chunk, $written);
                if ('' !== $remaining) {
                    array_unshift($this->pendingWrites, $remaining);
                }

                break;
            }
        }

        if ($this->outputClosed && [] === $this->pendingWrites) {
            $this->sendEof();
        }
    }

    private function sendEof(): void
    {
        if ($this->eofSent) {
            return;
        }

        $this->eofSent = true;
        $this->connection->sendChannelEof($this);
    }
}
