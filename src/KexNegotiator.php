<?php declare(strict_types=1);

namespace WilliamEggers\React\SSHClient;

use WilliamEggers\React\SSHClient\Enums\MessageType;

final class KexNegotiator
{
    public ?string $clientKexInit = null;
    public ?string $serverKexInit = null;

    /**
     * @var ?array{kex: string, hostkey: string, encryption_ctos: string, encryption_stoc: string, mac_ctos: string, mac_stoc: string, compression_ctos: string, compression_stoc: string}
     */
    private ?array $negotiatedAlgorithms = null;

    /** @var list<string> */
    private array $kexAlgorithms = [
        'curve25519-sha256',
        'curve25519-sha256@libssh.org',
        'diffie-hellman-group14-sha256',
    ];

    /** @var list<string> */
    private array $serverHostKeyAlgorithms = [
        'ssh-ed25519',
        'rsa-sha2-256',
        'rsa-sha2-512',
    ];

    /** @var list<string> */
    private array $encryptionAlgorithms = [
        'aes256-gcm@openssh.com',
        'aes128-gcm@openssh.com',
        'aes256-ctr',
        'aes192-ctr',
        'aes128-ctr',
    ];

    /** @var list<string> */
    private array $macAlgorithms = [
        'hmac-sha2-512',
        'hmac-sha2-256',
        'hmac-sha1',
    ];

    /** @var list<string> */
    private array $compressionAlgorithms = ['none'];

    public function __construct(
        public string $clientVersion,
        public string $serverVersion,
    ) {}

    public function createClientKexInit(): string
    {
        $payload = chr(MessageType::KEXINIT->value)
            . random_bytes(16)
            . $this->packString(implode(',', $this->kexAlgorithms))
            . $this->packString(implode(',', $this->serverHostKeyAlgorithms))
            . $this->packString(implode(',', $this->encryptionAlgorithms))
            . $this->packString(implode(',', $this->encryptionAlgorithms))
            . $this->packString(implode(',', $this->macAlgorithms))
            . $this->packString(implode(',', $this->macAlgorithms))
            . $this->packString(implode(',', $this->compressionAlgorithms))
            . $this->packString(implode(',', $this->compressionAlgorithms))
            . $this->packString('')
            . $this->packString('')
            . "\0"
            . pack('N', 0);

        $this->clientKexInit = $payload;

        return $payload;
    }

    public function acceptServerKexInit(Packet $packet): void
    {
        $this->serverKexInit = chr($packet->type->value) . $packet->message;
    }

    /**
     * @return array{kex: string, hostkey: string, encryption_ctos: string, encryption_stoc: string, mac_ctos: string, mac_stoc: string, compression_ctos: string, compression_stoc: string}
     */
    public function negotiateAlgorithms(): array
    {
        $serverPacket = substr((string) $this->serverKexInit, 1);
        $offset = 16;
        $fields = [];

        for ($i = 0; $i < 10; ++$i) {
            $length = (unpack('N', substr($serverPacket, $offset, 4)) ?: [])[1] ?? null;
            if (!is_int($length)) {
                throw new \UnexpectedValueException('Failure unpacking KEXINIT data');
            }

            $offset += 4;
            $fields[] = explode(',', substr($serverPacket, $offset, $length));
            $offset += $length;
        }

        [
            $serverKexAlgs,
            $serverHostKeyAlgs,
            $serverEncCtoS,
            $serverEncStoC,
            $serverMacCtoS,
            $serverMacStoC,
            $serverCompCtoS,
            $serverCompStoC,
        ] = array_slice($fields, 0, 8);

        $this->negotiatedAlgorithms = [
            'kex' => $this->selectAlgorithm($this->kexAlgorithms, $serverKexAlgs, 'kex'),
            'hostkey' => $this->selectAlgorithm($this->serverHostKeyAlgorithms, $serverHostKeyAlgs, 'hostkey'),
            'encryption_ctos' => $this->selectAlgorithm($this->encryptionAlgorithms, $serverEncCtoS, 'encryption_ctos'),
            'encryption_stoc' => $this->selectAlgorithm($this->encryptionAlgorithms, $serverEncStoC, 'encryption_stoc'),
            'mac_ctos' => $this->selectAlgorithm($this->macAlgorithms, $serverMacCtoS, 'mac_ctos'),
            'mac_stoc' => $this->selectAlgorithm($this->macAlgorithms, $serverMacStoC, 'mac_stoc'),
            'compression_ctos' => $this->selectAlgorithm($this->compressionAlgorithms, $serverCompCtoS, 'compression_ctos'),
            'compression_stoc' => $this->selectAlgorithm($this->compressionAlgorithms, $serverCompStoC, 'compression_stoc'),
        ];

        return $this->negotiatedAlgorithms;
    }

    /**
     * @return null|array{kex: string, hostkey: string, encryption_ctos: string, encryption_stoc: string, mac_ctos: string, mac_stoc: string, compression_ctos: string, compression_stoc: string}
     */
    public function getNegotiatedAlgorithms(): ?array
    {
        return $this->negotiatedAlgorithms;
    }

    public function getNegotiatedAlgorithm(string $algorithmType): ?string
    {
        return $this->negotiatedAlgorithms[$algorithmType] ?? null;
    }

    /**
     * @param list<string> $preferred
     * @param list<string> $available
     */
    private function selectAlgorithm(array $preferred, array $available, string $type): string
    {
        foreach ($preferred as $algorithm) {
            if (in_array($algorithm, $available, true)) {
                return $algorithm;
            }
        }

        throw new \RuntimeException('Unable to negotiate ' . $type);
    }

    private function packString(string $value): string
    {
        return pack('N', strlen($value)) . $value;
    }
}
