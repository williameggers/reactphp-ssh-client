<?php declare(strict_types=1);

namespace WilliamEggers\React\SSHClient\Values;

final readonly class KeyboardInteractiveChallenge
{
    /**
     * @param list<KeyboardInteractivePrompt> $prompts
     */
    public function __construct(
        public string $name,
        public string $instruction,
        public string $languageTag,
        public array $prompts,
    ) {}
}
