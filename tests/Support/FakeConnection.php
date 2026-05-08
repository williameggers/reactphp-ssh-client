<?php declare(strict_types=1);

namespace WilliamEggers\Tests\React\SSHClient\Support;

use Evenement\EventEmitterTrait;
use React\Socket\ConnectionInterface;
use React\Stream\WritableStreamInterface;
use WilliamEggers\React\SSHClient\Enums\MessageType;
use WilliamEggers\React\SSHClient\Packet;
use WilliamEggers\React\SSHClient\PacketHandler;

final class FakeConnection implements ConnectionInterface
{
    use EventEmitterTrait;

    private bool $readable = true;
    private bool $writable = true;

    /** @var list<string> */
    private array $writes = [];

    public function __construct(
        private readonly ?string $remoteAddress = 'tcp://127.0.0.1:22',
        private readonly ?string $localAddress = 'tcp://127.0.0.1:54321',
    ) {}

    public function getRemoteAddress(): ?string
    {
        return $this->remoteAddress;
    }

    public function getLocalAddress(): ?string
    {
        return $this->localAddress;
    }

    public function isReadable(): bool
    {
        return $this->readable;
    }

    public function pause(): void {}

    public function resume(): void {}

    /**
     * @param array<string, mixed> $options
     */
    public function pipe(WritableStreamInterface $dest, array $options = []): WritableStreamInterface
    {
        return $dest;
    }

    public function isWritable(): bool
    {
        return $this->writable;
    }

    public function write($data): bool
    {
        if (!$this->writable) {
            return false;
        }

        if (!is_scalar($data) && !(is_object($data) && method_exists($data, '__toString'))) {
            throw new \InvalidArgumentException('Data must be stringable');
        }

        $this->writes[] = (string) $data;

        return true;
    }

    public function end($data = null): void
    {
        if (null !== $data) {
            $this->write($data);
        }

        $this->close();
    }

    public function close(): void
    {
        if (!$this->readable && !$this->writable) {
            return;
        }

        $this->readable = false;
        $this->writable = false;
        $this->emit('close');
    }

    /**
     * @return list<Packet>
     */
    public function writtenPackets(): array
    {
        return array_map(static fn (string $packet): Packet => Packet::fromData($packet), $this->writes);
    }

    /**
     * @param list<mixed> $values
     */
    public function pushPacket(int $type, array $values = []): void
    {
        $packetHandler = new PacketHandler($this);
        $payload = $packetHandler->packValues(MessageType::from($type), $values);
        $packet = $packetHandler->constructPacket($payload);
        if (!is_string($packet)) {
            throw new \RuntimeException('Failed to construct fake SSH packet');
        }

        $this->emit('data', [$packet]);
    }
}
