<?php declare(strict_types=1);

namespace WilliamEggers\React\SSHClient;

use phpseclib4\Crypt\Common\PrivateKey;
use phpseclib4\Crypt\Common\PublicKey;
use phpseclib4\Crypt\EC;
use phpseclib4\Crypt\EC\PrivateKey as EcPrivateKey;
use phpseclib4\Crypt\PublicKeyLoader;
use phpseclib4\Crypt\RSA;
use phpseclib4\Crypt\RSA\PrivateKey as RsaPrivateKey;
use phpseclib4\Math\BigInteger;

final class PublicKeyHelper
{
    /**
     * @return array{algorithm: string, blob: string}
     */
    public function exportPublicKey(PrivateKey|PublicKey $key): array
    {
        $publicKey = $key instanceof PrivateKey ? $key->getPublicKey() : $key;

        if ($publicKey instanceof RSA) {
            return [
                'algorithm' => 'ssh-rsa',
                'blob' => $publicKey->toString('OpenSSH', ['binary' => true]),
            ];
        }

        if ($publicKey instanceof EC && 'Ed25519' === $publicKey->getCurve()) {
            return [
                'algorithm' => 'ssh-ed25519',
                'blob' => $publicKey->toString('OpenSSH', ['binary' => true]),
            ];
        }

        throw new \RuntimeException('Unsupported public key type');
    }

    public function getOpenSshKey(string $keyBlob): string
    {
        $offset = 0;
        [$keyType] = $this->extractString($keyBlob, $offset);

        if ('ssh-rsa' === $keyType) {
            $components = $this->extractRsaComponents($keyBlob);

            return 'ssh-rsa ' . base64_encode(
                $this->packString('ssh-rsa')
                . $this->packString($components['exponent'])
                . $this->packString($components['modulus'])
            );
        }

        if ('ssh-ed25519' === $keyType) {
            return 'ssh-ed25519 ' . base64_encode($keyBlob);
        }

        return $keyType . ' ' . base64_encode($keyBlob);
    }

    public function verifyServerHostKey(string $algorithm, string $hostKeyBlob, string $signatureBlob, string $exchangeHash): bool
    {
        [$signatureAlgorithm, $signatureData] = $this->parseSignatureBlob($signatureBlob);

        if ('ssh-ed25519' === $algorithm) {
            if ('ssh-ed25519' !== $signatureAlgorithm) {
                return false;
            }

            $publicKey = $this->extractEd25519PublicKey($hostKeyBlob);

            if ('' === $signatureData || '' === $publicKey) {
                return false;
            }

            return sodium_crypto_sign_verify_detached($signatureData, $exchangeHash, $publicKey);
        }

        if (in_array($algorithm, ['rsa-sha2-256', 'rsa-sha2-512'], true)) {
            $components = $this->extractRsaComponents($hostKeyBlob);
            $publicKeyPem = $this->createPemFromComponents($components['modulus'], $components['exponent']);
            $opensslKey = openssl_pkey_get_public($publicKeyPem);
            if (false === $opensslKey) {
                return false;
            }

            $opensslAlgorithm = match ($signatureAlgorithm) {
                'rsa-sha2-256' => OPENSSL_ALGO_SHA256,
                'rsa-sha2-512' => OPENSSL_ALGO_SHA512,
                default => null,
            };

            if (null === $opensslAlgorithm) {
                return false;
            }

            return 1 === openssl_verify($exchangeHash, $signatureData, $opensslKey, $opensslAlgorithm);
        }

        return false;
    }

    public function constructSignedUserAuthRequest(
        string $sessionId,
        string $username,
        string $service,
        string $signatureAlgorithm,
        string $keyBlob,
    ): string {
        return $this->packString($sessionId)
            . chr(50)
            . $this->packString($username)
            . $this->packString($service)
            . $this->packString('publickey')
            . chr(1)
            . $this->packString($signatureAlgorithm)
            . $this->packString($keyBlob);
    }

    /**
     * @return array{algorithm: string, signature: string}
     */
    public function createSignature(PrivateKey $privateKey, string $signedData): array
    {
        if ($privateKey instanceof RsaPrivateKey) {
            $algorithm = 'rsa-sha2-256';
            $signatureKey = $privateKey->withPadding(RSA::SIGNATURE_PKCS1)->withHash('sha256');

            $signature = $signatureKey->sign($signedData);
            if (!is_string($signature) || '' === $signature) {
                throw new \UnexpectedValueException('Unexpected RSA signature type');
            }

            return [
                'algorithm' => $algorithm,
                'signature' => $this->packString($algorithm) . $this->packString($signature),
            ];
        }

        if ($privateKey instanceof EcPrivateKey && 'Ed25519' === $privateKey->getCurve()) {
            $algorithm = 'ssh-ed25519';

            $signature = $privateKey->sign($signedData);
            if (!is_string($signature) || '' === $signature) {
                throw new \UnexpectedValueException('Unexpected Ed25519 signature type');
            }

            return [
                'algorithm' => $algorithm,
                'signature' => $this->packString($signature),
            ];
        }

        throw new \RuntimeException('Unsupported private key type');
    }

    /**
     * @return array{0: string, 1: string}
     */
    public function parseSignatureBlob(string $signatureBlob): array
    {
        $offset = 0;
        [$algorithm, $offset] = $this->extractString($signatureBlob, $offset);
        [$signature] = $this->extractString($signatureBlob, $offset);

        return [$algorithm, $signature];
    }

    /**
     * @return array{0: string, 1: int}
     */
    public function extractString(string $data, int &$offset): array
    {
        if ($offset + 4 > strlen($data)) {
            throw new \LengthException('Not enough data to read length');
        }

        $length = (unpack('N', substr($data, $offset, 4)) ?: [])[1] ?? null;
        if (!is_int($length)) {
            throw new \UnexpectedValueException('Expected int-castable value at offset 1');
        }

        $offset += 4;
        $string = substr($data, $offset, $length);
        $offset += $length;

        return [$string, $offset];
    }

    /**
     * @return array{type: string, exponent: string, modulus: string}
     */
    private function extractRsaComponents(string $keyBlob): array
    {
        $offset = 0;
        [$keyType, $offset] = $this->extractString($keyBlob, $offset);
        [$exponent, $offset] = $this->extractString($keyBlob, $offset);
        [$modulus] = $this->extractString($keyBlob, $offset);

        return [
            'type' => $keyType,
            'exponent' => $exponent,
            'modulus' => $modulus,
        ];
    }

    private function createPemFromComponents(string $modulus, string $exponent): string
    {
        return PublicKeyLoader::load([
            'n' => new BigInteger($modulus, 256),
            'e' => new BigInteger($exponent, 256),
        ])->toString('PKCS8');
    }

    private function extractEd25519PublicKey(string $keyBlob): string
    {
        $offset = 0;
        [$keyType, $offset] = $this->extractString($keyBlob, $offset);
        if ('ssh-ed25519' !== $keyType) {
            throw new \UnexpectedValueException('Expected ssh-ed25519 host key');
        }

        [$publicKey] = $this->extractString($keyBlob, $offset);

        return $publicKey;
    }

    private function packString(string $value): string
    {
        return pack('N', strlen($value)) . $value;
    }
}
