<?php declare(strict_types=1);

namespace WilliamEggers\React\SSHClient\HostKey;

final class CallbackHostKeyVerifier implements HostKeyVerifierInterface
{
    /**
     * @var \Closure(HostKeyInfo, string): bool
     */
    private readonly \Closure $verifier;

    /**
     * Create a host key verifier from a callback.
     *
     * @param callable(HostKeyInfo, string): bool $verifier
     */
    public function __construct(callable $verifier)
    {
        $this->verifier = \Closure::fromCallable($verifier);
    }

    /**
     * Invoke the configured callback with the host key details and remote address.
     */
    public function verify(HostKeyInfo $hostKey, string $address): bool
    {
        return (bool) ($this->verifier)($hostKey, $address);
    }
}
