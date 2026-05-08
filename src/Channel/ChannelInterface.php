<?php declare(strict_types=1);

namespace WilliamEggers\React\SSHClient\Channel;

use React\Stream\DuplexStreamInterface;

interface ChannelInterface extends DuplexStreamInterface
{
    public function getLocalChannel(): int;

    public function getRemoteChannel(): int;

    public function writeData(string $data): void;

    public function writeExtendedData(int $dataTypeCode, string $data): void;

    public function receiveEof(): void;

    public function receiveClose(): void;

    public function increaseRemoteWindow(int $bytes): void;

    public function consumeRemoteWindow(int $bytes): void;

    public function getRemoteWindow(): int;

    public function getMaxPacketSize(): int;

    public function consumeLocalWindow(int $bytes): void;
}
