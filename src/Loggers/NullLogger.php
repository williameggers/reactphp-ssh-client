<?php declare(strict_types=1);

namespace WilliamEggers\React\SSHClient\Loggers;

use Psr\Log\AbstractLogger;

final class NullLogger extends AbstractLogger
{
    public function log(mixed $level, string|\Stringable $message, array $context = []): void {}
}
