<?php declare(strict_types=1);

namespace WilliamEggers\React\SSHClient\HostKey;

interface HostKeyVerifierInterface
{
    public function verify(HostKeyInfo $hostKey, string $address): bool;
}
