<?php declare(strict_types=1);

namespace WilliamEggers\React\SSHClient\HostKey;

interface HostKeyVerifierInterface
{
    /**
     * Decide whether the server host key should be trusted.
     *
     * @param HostKeyInfo $hostKey host key details extracted during the handshake
     * @param string      $address remote address supplied to the connector
     */
    public function verify(HostKeyInfo $hostKey, string $address): bool;
}
