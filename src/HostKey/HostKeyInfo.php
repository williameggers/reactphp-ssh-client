<?php declare(strict_types=1);

namespace WilliamEggers\React\SSHClient\HostKey;

final readonly class HostKeyInfo
{
    /**
     * Describe the server host key presented during handshake.
     *
     * @param string $algorithm  SSH host key algorithm name
     * @param string $blob       raw binary host key blob
     * @param string $opensshKey openSSH-formatted public key line
     */
    public function __construct(
        public string $algorithm,
        public string $blob,
        public string $opensshKey,
    ) {}

    /**
     * Return the host key fingerprint in OpenSSH SHA256 format.
     */
    public function getFingerprintSha256(): string
    {
        return 'SHA256:' . base64_encode(hash('sha256', $this->blob, true));
    }
}
