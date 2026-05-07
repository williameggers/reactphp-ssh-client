<?php declare(strict_types=1);

namespace WilliamEggers\React\SSHClient\Concerns;

use Psr\Log\LoggerInterface;
use Psr\Log\LoggerTrait;

trait WritesLogs
{
    use LoggerTrait;

    private LoggerInterface $logger;

    public function setLogger(LoggerInterface $logger): self
    {
        $this->logger = $logger;

        return $this;
    }

    /**
     * @param array<mixed> $context
     */
    public function log(mixed $level, string|\Stringable $message, array $context = []): void
    {
        if (!isset($this->logger)) {
            return;
        }

        $classPrepend = strtoupper(sprintf('[%s]', str_replace('WilliamEggers\React\SSHClient\\', '', $this::class)));
        $this->logger->log($level, $classPrepend . ' ' . $message, $context);
    }
}
