<?php declare(strict_types=1);

namespace WilliamEggers\React\SSHClient;

use phpseclib3\Crypt\DH;
use phpseclib3\Crypt\DH\PrivateKey;

final class Kex
{
    private ?string $sessionId = null;
    private string $exchangeHash;
    private string $sharedSecret;
    private string $clientEphemeralPublic;
    private string $clientEphemeralPrivate;
    private ?Packet $replyPacket = null;

    public function __construct(
        private readonly KexNegotiator $kexNegotiator,
        private readonly Packet $packet,
    ) {}

    public function createRequest(): string
    {
        $kexAlgorithm = $this->kexNegotiator->getNegotiatedAlgorithm('kex');

        switch ($kexAlgorithm) {
            case 'curve25519-sha256':
            case 'curve25519-sha256@libssh.org':
                $curveKeyPair = sodium_crypto_box_keypair();
                $this->clientEphemeralPrivate = sodium_crypto_box_secretkey($curveKeyPair);
                $this->clientEphemeralPublic = sodium_crypto_box_publickey($curveKeyPair);

                return chr(30) . pack('N', strlen($this->clientEphemeralPublic)) . $this->clientEphemeralPublic;

            case 'diffie-hellman-group14-sha256':
                $dhParameters = DH::createParameters($kexAlgorithm);
                $dhPrivate = DH::createKey($dhParameters);
                $dhPublic = $dhPrivate->getPublicKey()->toBigInteger()->toBytes(true);
                $this->clientEphemeralPrivate = serialize($dhPrivate);
                $this->clientEphemeralPublic = $dhPublic;

                return chr(30) . $this->packMpint($dhPublic);
        }

        throw new \RuntimeException('Unhandled KEX algorithm');
    }

    public function acceptReply(): void
    {
        $packet = $this->replyPacket ?? $this->packet;
        [$hostKeyBlob, $serverEphemeralPublic, $signatureBlob] = $packet->extractFormat('%s%s%s');
        if (!is_string($hostKeyBlob) || !is_string($serverEphemeralPublic) || !is_string($signatureBlob)) {
            throw new \UnexpectedValueException('Invalid KEX reply payload');
        }

        $kexAlgorithm = $this->kexNegotiator->getNegotiatedAlgorithm('kex');

        switch ($kexAlgorithm) {
            case 'curve25519-sha256':
            case 'curve25519-sha256@libssh.org':
                $this->sharedSecret = sodium_crypto_scalarmult($this->clientEphemeralPrivate, $serverEphemeralPublic);

                break;

            case 'diffie-hellman-group14-sha256':
                $dhPrivate = unserialize($this->clientEphemeralPrivate, ['allowed_classes' => true]);
                if (!$dhPrivate instanceof PrivateKey) {
                    throw new \RuntimeException('Invalid DH private key state');
                }

                $sharedSecret = DH::computeSecret($dhPrivate, $serverEphemeralPublic);
                if (!is_string($sharedSecret)) {
                    throw new \RuntimeException('Invalid DH shared secret');
                }

                $this->sharedSecret = $sharedSecret;

                break;

            default:
                throw new \RuntimeException('Unhandled KEX algorithm');
        }

        $this->exchangeHash = hash('sha256', implode('', [
            $this->packString($this->kexNegotiator->clientVersion),
            $this->packString($this->kexNegotiator->serverVersion),
            $this->packString((string) $this->kexNegotiator->clientKexInit),
            $this->packString((string) $this->kexNegotiator->serverKexInit),
            $this->packString($hostKeyBlob),
            $this->packString($this->clientEphemeralPublic),
            $this->packString($serverEphemeralPublic),
            $this->packMpint($this->sharedSecret),
        ]), true);

        if (null === $this->sessionId) {
            $this->sessionId = $this->exchangeHash;
        }

        $this->replyPacket = null;
    }

    public function setReplyPacket(Packet $packet): self
    {
        $this->replyPacket = $packet;

        return $this;
    }

    public function getExchangeHash(): string
    {
        return $this->exchangeHash;
    }

    public function getSharedSecret(): string
    {
        return $this->sharedSecret;
    }

    public function getSessionId(): ?string
    {
        return $this->sessionId;
    }

    public function setSessionId(string $sessionId): self
    {
        if (null !== $this->sessionId) {
            throw new \RuntimeException('Session Id already set');
        }

        $this->sessionId = $sessionId;

        return $this;
    }

    private function packString(string $value): string
    {
        return pack('N', strlen($value)) . $value;
    }

    private function packMpint(string $bignum): string
    {
        $bignum = ltrim($bignum, "\0");

        if ('' !== $bignum && (ord($bignum[0]) & 0x80)) {
            $bignum = "\0" . $bignum;
        }

        return pack('N', strlen($bignum)) . $bignum;
    }
}
