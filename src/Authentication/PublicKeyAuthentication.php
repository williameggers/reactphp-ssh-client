<?php declare(strict_types=1);

namespace WilliamEggers\React\SSHClient\Authentication;

use phpseclib3\Crypt\Common\PrivateKey;
use phpseclib3\Crypt\PublicKeyLoader;

final class PublicKeyAuthentication implements AuthenticationInterface
{
    private readonly PrivateKey $privateKey;

    /**
     * Create public key authentication from an in-memory private key.
     *
     * @param string      $username   remote username
     * @param string      $privateKey private key contents in a format supported by phpseclib
     * @param null|string $passphrase optional passphrase used to unlock the private key
     *
     * @throws \RuntimeException if the key cannot be parsed
     */
    public function __construct(
        private readonly string $username,
        string $privateKey,
        ?string $passphrase = null,
    ) {
        $this->privateKey = PublicKeyLoader::loadPrivateKey($privateKey, $passphrase ?? '');
    }

    /**
     * Load public key authentication from a private key file on disk.
     *
     * @param string      $username   remote username
     * @param string      $path       path to the private key file
     * @param null|string $passphrase optional passphrase used to unlock the private key
     *
     * @throws \RuntimeException if the private key cannot be read or parsed
     */
    public static function fromPath(string $username, string $path, ?string $passphrase = null): self
    {
        $privateKey = file_get_contents($path);
        if (!is_string($privateKey) || '' === $privateKey) {
            throw new \RuntimeException('Unable to read private key: ' . $path);
        }

        return new self($username, $privateKey, $passphrase);
    }

    /**
     * Return the username to authenticate as.
     */
    public function getUsername(): string
    {
        return $this->username;
    }

    /**
     * Return the parsed private key used for request signing.
     */
    public function getPrivateKey(): PrivateKey
    {
        return $this->privateKey;
    }
}
