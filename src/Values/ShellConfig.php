<?php declare(strict_types=1);

namespace WilliamEggers\React\SSHClient\Values;

final readonly class ShellConfig
{
    /**
     * @param array<string, string> $environment
     */
    public function __construct(
        public string $term = 'xterm',
        public WinSize $size = new WinSize(24, 80, 0, 0),
        public array $environment = [],
    ) {}
}
