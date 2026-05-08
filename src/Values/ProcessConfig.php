<?php declare(strict_types=1);

namespace WilliamEggers\React\SSHClient\Values;

final readonly class ProcessConfig
{
    /**
     * Configure a remote command execution request.
     *
     * @param array<string, string> $environment
     */
    public function __construct(
        public array $environment = [],
    ) {}
}
