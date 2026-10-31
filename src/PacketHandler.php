<?php declare(strict_types=1);

namespace WilliamEggers\React\SSHClient;

use phpseclib4\Crypt\AES;
use phpseclib4\Crypt\Hash;
use React\Socket\ConnectionInterface;
use WilliamEggers\React\SSHClient\Concerns\WritesLogs;
use WilliamEggers\React\SSHClient\Enums\MessageType;

final class PacketHandler
{
    use WritesLogs;

    private bool $encryptionActive = false;
    private int $packetSeq_CtoS = 0;
    private int $packetSeq_StoC = 0;
    private string $encryptionMethod_CtoS;
    private string $encryptIV_CtoS;
    private string $encryptKey_CtoS;
    private string $macMethod_CtoS;
    private ?string $macKey_CtoS = null;
    private string $encryptionMethod_StoC;
    private string $encryptIV_StoC;
    private string $encryptKey_StoC;
    private string $macMethod_StoC;
    private ?string $macKey_StoC = null;
    private Kex $kex;
    private ?AES $encryptor = null;
    private ?AES $decryptor = null;
    private ?Hash $hash_StoC = null;
    private ?Hash $hash_CtoS = null;

    public function __construct(public ConnectionInterface $connection)
    {
        if (!sodium_crypto_aead_aes256gcm_is_available() && !in_array('aes-256-gcm', openssl_get_cipher_methods(), true)) {
            throw new \RuntimeException('AES-256-GCM not available');
        }
    }

    public function setKex(Kex $kex): self
    {
        $this->kex = $kex;

        return $this;
    }

    public function isEncryptionActive(): bool
    {
        return $this->encryptionActive;
    }

    public function setEncryptionMethods(string $encryptionMethod_CtoS, string $encryptionMethod_StoC): self
    {
        $this->encryptionMethod_CtoS = $encryptionMethod_CtoS;
        $this->encryptionMethod_StoC = $encryptionMethod_StoC;

        return $this;
    }

    public function setMacMethods(string $macMethod_CtoS, string $macMethod_StoC): self
    {
        $this->macMethod_CtoS = $macMethod_CtoS;
        $this->macMethod_StoC = $macMethod_StoC;

        return $this;
    }

    public function deriveKeys(): void
    {
        $K = $this->packMpint($this->kex->getSharedSecret());
        $H = $this->kex->getExchangeHash();
        $sessionId = $this->kex->getSessionId() ?? throw new \RuntimeException('Session ID missing');

        $kdfMac = static function (string $letter, int $neededLength) use ($K, $H, $sessionId): string {
            $output = '';
            $input = $K . $H . $letter . $sessionId;
            $output = hash('sha256', $input, true);
            while (strlen($output) < $neededLength) {
                $input = $K . $H . $output;
                $output .= hash('sha256', $input, true);
            }

            return substr($output, 0, $neededLength);
        };

        $kdf = static function (string $letter, int $neededLength) use ($K, $H, $sessionId): string {
            $output = '';
            $prevBlock = '';

            while (strlen($output) < $neededLength) {
                $input = $K . $H . ($prevBlock ?: $letter) . $sessionId;
                $hash = hash('sha256', $input, true);
                $output .= $hash;
                $prevBlock = $hash;
            }

            return substr($output, 0, $neededLength);
        };

        [$this->hash_CtoS, $CtoSKeyLength] = $this->macAlgorithmToHashInstance($this->macMethod_CtoS);
        [$this->hash_StoC, $StoCKeyLength] = $this->macAlgorithmToHashInstance($this->macMethod_StoC);

        [$this->encryptIV_CtoS, $this->encryptKey_CtoS, $this->macKey_CtoS] = $this->deriveDirectionalKeys(
            $this->encryptionMethod_CtoS,
            'A',
            'C',
            'E',
            $CtoSKeyLength,
            $kdf,
            $kdfMac,
        );

        [$this->encryptIV_StoC, $this->encryptKey_StoC, $this->macKey_StoC] = $this->deriveDirectionalKeys(
            $this->encryptionMethod_StoC,
            'B',
            'D',
            'F',
            $StoCKeyLength,
            $kdf,
            $kdfMac,
        );

        if (null !== $this->macKey_CtoS) {
            $this->hash_CtoS->setKey($this->macKey_CtoS);
        }

        if (null !== $this->macKey_StoC) {
            $this->hash_StoC->setKey($this->macKey_StoC);
        }

        $this->initializeCiphers();
        $this->encryptionActive = true;
    }

    public function constructPacket(string $payload): false|string
    {
        if ($this->encryptionActive) {
            return match ($this->encryptionMethod_CtoS) {
                'aes256-gcm@openssh.com', 'aes128-gcm@openssh.com' => $this->constructEncryptedPacketAESGCM($payload),
                'aes256-ctr', 'aes192-ctr', 'aes128-ctr' => $this->constructEncryptedPacketAESCTR($payload),
                default => false,
            };
        }

        $packetLen = strlen($payload);
        $paddingLen = 8 - (($packetLen + 5) % 8);
        if ($paddingLen < 4) {
            $paddingLen += 8;
        }

        ++$this->packetSeq_CtoS;

        return pack('N', $packetLen + $paddingLen + 1)
            . chr($paddingLen)
            . $payload
            . random_bytes($paddingLen);
    }

    /**
     * @return array{0: ?Packet, 1: int}
     */
    public function fromData(string $data): array
    {
        if ($this->encryptionActive) {
            return match ($this->encryptionMethod_StoC) {
                'aes128-gcm@openssh.com', 'aes256-gcm@openssh.com' => $this->fromEncryptedGcmData($data),
                'aes128-ctr', 'aes192-ctr', 'aes256-ctr' => $this->fromEncryptedCtrData($data),
                default => [null, 0],
            };
        }

        if (strlen($data) < 4) {
            return [null, 0];
        }

        $packetLength = (unpack('N', substr($data, 0, 4)) ?: [])[1] ?? null;
        if (!is_int($packetLength)) {
            throw new \UnexpectedValueException('Expected int-castable value at offset 1');
        }

        if (strlen($data) < 4 + $packetLength) {
            return [null, 0];
        }

        ++$this->packetSeq_StoC;

        return [Packet::fromData($data), 4 + $packetLength];
    }

    public function packString(string $str): string
    {
        return pack('N', strlen($str)) . $str;
    }

    public function packValue(MessageType $type, mixed $value): string
    {
        return $this->packValues($type, [$value]);
    }

    /**
     * @param list<mixed> $values
     */
    public function packValues(MessageType $type, array $values = []): string
    {
        $packed = MessageType::chr($type);
        foreach ($values as $value) {
            if (is_string($value)) {
                $packed .= $this->packString($value);

                continue;
            }

            if (is_int($value)) {
                $packed .= $this->packInteger($value);

                continue;
            }

            if (is_bool($value)) {
                $packed .= $this->packBool($value);

                continue;
            }

            if (null === $value) {
                continue;
            }

            throw new \InvalidArgumentException('Unsupported packet value type');
        }

        return $packed;
    }

    /**
     * @param callable(string, int): string $kdf
     * @param callable(string, int): string $kdfMac
     *
     * @return array{0: string, 1: string, 2: ?string}
     */
    private function deriveDirectionalKeys(
        string $encryptionMethod,
        string $ivLetter,
        string $keyLetter,
        string $macLetter,
        int $macLength,
        callable $kdf,
        callable $kdfMac,
    ): array {
        return match ($encryptionMethod) {
            'aes128-gcm@openssh.com' => [$kdf($ivLetter, 12), $kdf($keyLetter, 16), null],
            'aes256-gcm@openssh.com' => [$kdf($ivLetter, 12), $kdf($keyLetter, 32), null],
            'aes128-ctr' => [$kdf($ivLetter, 16), $kdf($keyLetter, 16), $kdfMac($macLetter, $macLength)],
            'aes192-ctr' => [$kdf($ivLetter, 16), $kdf($keyLetter, 24), $kdfMac($macLetter, $macLength)],
            'aes256-ctr' => [$kdf($ivLetter, 16), $kdf($keyLetter, 32), $kdfMac($macLetter, $macLength)],
            default => throw new \RuntimeException('Unhandled encryption method'),
        };
    }

    private function initializeCiphers(): void
    {
        switch ($this->encryptionMethod_CtoS) {
            case 'aes256-gcm@openssh.com':
            case 'aes128-gcm@openssh.com':
                $this->encryptor = new AES('gcm');
                $this->encryptor->setKey($this->encryptKey_CtoS);
                $this->packetSeq_CtoS = 0;

                break;

            case 'aes256-ctr':
            case 'aes192-ctr':
            case 'aes128-ctr':
                $this->encryptor = new AES('ctr');
                $this->encryptor->setKey($this->encryptKey_CtoS);
                $this->encryptor->setIV($this->encryptIV_CtoS);
                $this->encryptor->disablePadding();
                $this->encryptor->enableContinuousBuffer();

                break;
        }

        switch ($this->encryptionMethod_StoC) {
            case 'aes256-gcm@openssh.com':
            case 'aes128-gcm@openssh.com':
                $this->decryptor = new AES('gcm');
                $this->decryptor->setKey($this->encryptKey_StoC);
                $this->packetSeq_StoC = 0;

                break;

            case 'aes256-ctr':
            case 'aes192-ctr':
            case 'aes128-ctr':
                $this->decryptor = new AES('ctr');
                $this->decryptor->setKey($this->encryptKey_StoC);
                $this->decryptor->setIV($this->encryptIV_StoC);
                $this->decryptor->disablePadding();
                $this->decryptor->enableContinuousBuffer();

                break;
        }
    }

    /**
     * @return array{0: ?Packet, 1: int}
     */
    private function fromEncryptedGcmData(string $data): array
    {
        if (strlen($data) < 4) {
            return [null, 0];
        }

        $packetLength = (unpack('N', substr($data, 0, 4)) ?: [])[1] ?? null;
        if (!is_int($packetLength)) {
            throw new \UnexpectedValueException('Expected int-castable value at offset 1');
        }

        $bytesUsed = 4 + $packetLength + 16;
        if (strlen($data) < $bytesUsed) {
            return [null, 0];
        }

        $lengthBytes = substr($data, 0, 4);
        $ciphertext = substr($data, 4, $packetLength);
        $tag = substr($data, 4 + $packetLength, 16);
        $nonce = $this->getNonce($this->encryptIV_StoC, $this->packetSeq_StoC);

        $decryptor = $this->decryptor ?? throw new \RuntimeException('Decryptor not initialized');
        $decryptor->setNonce($nonce);
        $decryptor->setAAD($lengthBytes);
        $decryptor->setTag($tag);
        $plaintext = $decryptor->decrypt($ciphertext);

        ++$this->packetSeq_StoC;
        $paddingLength = ord($plaintext[0]);

        return [new Packet(substr($plaintext, 1, strlen($plaintext) - $paddingLength - 1)), $bytesUsed];
    }

    /**
     * @return array{0: ?Packet, 1: int}
     */
    private function fromEncryptedCtrData(string $data): array
    {
        $hash = $this->hash_StoC ?? throw new \RuntimeException('Hash instance not initialized');
        $macLength = $hash->getLengthInBytes();
        if (strlen($data) < 4) {
            return [null, 0];
        }

        $probeDecryptor = clone ($this->decryptor ?? throw new \RuntimeException('Decryptor not initialized'));
        $packetLengthBytes = $probeDecryptor->decrypt(substr($data, 0, 4));
        $packetLength = (unpack('N', $packetLengthBytes) ?: [])[1] ?? null;
        if (!is_int($packetLength)) {
            throw new \RuntimeException('Failure obtaining packet length');
        }

        $bytesUsed = 4 + $packetLength + $macLength;
        if (strlen($data) < $bytesUsed) {
            return [null, 0];
        }

        $ciphertext = substr($data, 0, 4 + $packetLength);
        $decryptor = $this->decryptor ?? throw new \RuntimeException('Decryptor not initialized');
        $plaintext = $decryptor->decrypt($ciphertext);

        $mac = substr($data, 4 + $packetLength, $macLength);
        $macData = $this->packInteger($this->packetSeq_StoC) . $plaintext;
        $calculatedMac = substr($hash->hash($macData), 0, $macLength);
        if (!hash_equals($calculatedMac, $mac)) {
            throw new \RuntimeException('Invalid HMAC');
        }

        ++$this->packetSeq_StoC;
        $paddingLength = ord($plaintext[4]);
        $payloadLength = $packetLength - $paddingLength - 1;

        return [new Packet(substr($plaintext, 5, $payloadLength)), $bytesUsed];
    }

    private function constructEncryptedPacketAESCTR(string $payload): string
    {
        $blockSize = 16;
        $payloadLength = strlen($payload);
        $paddingLength = $blockSize - ((1 + $payloadLength + 4) % $blockSize);
        if ($paddingLength < 4) {
            $paddingLength += $blockSize;
        }

        $packetLength = 1 + $payloadLength + 4 + $paddingLength;
        $packetLengthBytes = pack('N', $packetLength - 4);
        $plaintextPacket = $packetLengthBytes . chr($paddingLength) . $payload . random_bytes($paddingLength);
        $encryptor = $this->encryptor ?? throw new \RuntimeException('Encryptor not initialized');
        $ciphertext = $encryptor->encrypt($plaintextPacket);

        $macValue = pack('Na*', $this->packetSeq_CtoS, substr($plaintextPacket, 0, $packetLength));
        $hash = $this->hash_CtoS ?? throw new \RuntimeException('Hash instance not initialized');
        $mac = $hash->hash($macValue);
        ++$this->packetSeq_CtoS;

        return $ciphertext . substr($mac, 0, $hash->getLengthInBytes());
    }

    private function constructEncryptedPacketAESGCM(string $payload): string
    {
        $blockSize = 16;
        $paddingLength = $blockSize - ((1 + strlen($payload)) % $blockSize);
        if ($paddingLength < 4) {
            $paddingLength += $blockSize;
        }

        $packetLength = 1 + strlen($payload) + $paddingLength;
        $lengthBytes = pack('N', $packetLength);
        $packet = chr($paddingLength) . $payload . random_bytes($paddingLength);
        $nonce = $this->getNonce($this->encryptIV_CtoS, $this->packetSeq_CtoS);

        $encryptor = $this->encryptor ?? throw new \RuntimeException('Encryptor not initialized');
        $encryptor->setNonce($nonce);
        $encryptor->setAAD($lengthBytes);
        $ciphertext = $encryptor->encrypt($packet);
        $tag = $encryptor->getTag();

        ++$this->packetSeq_CtoS;

        return $lengthBytes . $ciphertext . $tag;
    }

    private function getNonce(string $baseIV, int $sequenceNumber): string
    {
        $nonce = $baseIV;
        $counter = (unpack('N', substr($baseIV, 8, 4)) ?: [])[1] ?? null;
        if (!is_int($counter)) {
            throw new \UnexpectedValueException('Expected int-castable value at offset 1');
        }

        $counter = ($counter + $sequenceNumber) & 0xFFFFFFFF;
        $nonce[8] = chr(($counter >> 24) & 0xFF);
        $nonce[9] = chr(($counter >> 16) & 0xFF);
        $nonce[10] = chr(($counter >> 8) & 0xFF);
        $nonce[11] = chr($counter & 0xFF);

        return $nonce;
    }

    private function packInteger(int $uint): string
    {
        return pack('N', $uint);
    }

    private function packBool(bool $bool): string
    {
        return $bool ? chr(1) : chr(0);
    }

    private function packMpint(string $bignum): string
    {
        $bignum = ltrim($bignum, "\0");
        if ('' !== $bignum && (ord($bignum[0]) & 0x80)) {
            $bignum = "\0" . $bignum;
        }

        return pack('N', strlen($bignum)) . $bignum;
    }

    /**
     * @return array{Hash, int}
     */
    private function macAlgorithmToHashInstance(string $algorithm): array
    {
        return match ($algorithm) {
            'hmac-sha2-512' => [new Hash('sha512'), 64],
            'hmac-sha2-256' => [new Hash('sha256'), 32],
            'hmac-sha1' => [new Hash('sha1'), 20],
            default => throw new \RuntimeException('Unhandled hash algorithm'),
        };
    }
}
