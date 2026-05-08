<?php declare(strict_types=1);

namespace WilliamEggers\React\SSHClient\Values;

final readonly class KeyboardInteractivePrompt
{
    /**
     * Describe a single keyboard-interactive prompt.
     *
     * @param string $prompt prompt text shown to the user
     * @param bool   $echo   whether the response may be echoed locally
     */
    public function __construct(
        public string $prompt,
        public bool $echo,
    ) {}
}
