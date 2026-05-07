<?php declare(strict_types=1);

namespace WilliamEggers\React\SSHClient\Values;

final readonly class ProcessConfig
{
    /**
     * @param array<string, string> $environment
     */
    public function __construct(
        public array $environment = [],
    ) {}
}
