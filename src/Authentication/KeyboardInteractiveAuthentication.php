<?php declare(strict_types=1);

namespace WilliamEggers\React\SSHClient\Authentication;

use WilliamEggers\React\SSHClient\Values\KeyboardInteractiveChallenge;

final class KeyboardInteractiveAuthentication implements AuthenticationInterface
{
    /**
     * @var \Closure(KeyboardInteractiveChallenge): list<string>
     */
    private readonly \Closure $responder;

    /**
     * @param callable(KeyboardInteractiveChallenge): list<string> $responder
     */
    public function __construct(
        private readonly string $username,
        callable $responder,
    ) {
        $this->responder = \Closure::fromCallable($responder);
    }

    public function getUsername(): string
    {
        return $this->username;
    }

    /**
     * @return list<string>
     */
    public function respond(KeyboardInteractiveChallenge $challenge): array
    {
        $responses = ($this->responder)($challenge);

        return array_map(static fn (mixed $response): string => (string) $response, $responses);
    }
}
