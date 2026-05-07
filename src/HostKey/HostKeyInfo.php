<?php declare(strict_types=1);

namespace WilliamEggers\React\SSHClient\HostKey;

final readonly class HostKeyInfo
{
    public function __construct(
        public string $algorithm,
        public string $blob,
        public string $opensshKey,
    ) {}

    public function getFingerprintSha256(): string
    {
        return 'SHA256:' . base64_encode(hash('sha256', $this->blob, true));
    }
}
