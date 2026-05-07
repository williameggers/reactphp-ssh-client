<?php declare(strict_types=1);

namespace WilliamEggers\React\SSHClient\Values;

final readonly class KeyboardInteractivePrompt
{
    public function __construct(
        public string $prompt,
        public bool $echo,
    ) {}
}
