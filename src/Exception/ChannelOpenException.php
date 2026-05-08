<?php declare(strict_types=1);

namespace WilliamEggers\React\SSHClient\Exception;

final class ChannelOpenException extends \RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $reasonCode,
        public readonly string $description,
    ) {
        parent::__construct($message);
    }
}
