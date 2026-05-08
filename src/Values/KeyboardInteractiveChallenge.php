<?php declare(strict_types=1);

namespace WilliamEggers\React\SSHClient\Values;

final readonly class KeyboardInteractiveChallenge
{
    /**
     * Describe a keyboard-interactive challenge sent by the server.
     *
     * @param list<KeyboardInteractivePrompt> $prompts
     */
    public function __construct(
        public string $name,
        public string $instruction,
        public string $languageTag,
        public array $prompts,
    ) {}
}
