<?php declare(strict_types=1);

namespace WilliamEggers\React\SSHClient\Authentication;

use phpseclib3\Crypt\Common\PrivateKey;
use phpseclib3\Crypt\PublicKeyLoader;

final class PublicKeyAuthentication implements AuthenticationInterface
{
    private readonly PrivateKey $privateKey;

    public function __construct(
        private readonly string $username,
        string $privateKey,
        ?string $passphrase = null,
    ) {
        $this->privateKey = PublicKeyLoader::loadPrivateKey($privateKey, $passphrase ?? '');
    }

    public static function fromPath(string $username, string $path, ?string $passphrase = null): self
    {
        $privateKey = file_get_contents($path);
        if (!is_string($privateKey) || '' === $privateKey) {
            throw new \RuntimeException('Unable to read private key: ' . $path);
        }

        return new self($username, $privateKey, $passphrase);
    }

    public function getUsername(): string
    {
        return $this->username;
    }

    public function getPrivateKey(): PrivateKey
    {
        return $this->privateKey;
    }
}
