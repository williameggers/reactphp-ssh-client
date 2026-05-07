<?php declare(strict_types=1);

namespace WilliamEggers\React\SSHClient;

use WilliamEggers\React\SSHClient\Enums\MessageType;

final class Packet
{
    public readonly MessageType $type;
    public readonly int $typeValue;
    public readonly string $message;
    public int $offset = 0;

    public function __construct(string $payload)
    {
        $this->typeValue = ord($payload[0]);
        $this->type = MessageType::from($this->typeValue);
        $this->message = substr($payload, 1);
    }

    /**
     * @return array{0: string, 1: int}
     */
    public function extractString(string $data, int &$offset): array
    {
        $unpackResult = unpack('N', substr($data, $offset, 4));
        if (false === $unpackResult) {
            throw new \RuntimeException('Failure determining packet string length');
        }

        $length = $unpackResult[1];
        if (!is_int($length) && !is_float($length) && !is_string($length)) {
            throw new \UnexpectedValueException('Expected int-castable value at offset 1');
        }

        $length = (int) $length;
        $string = substr($data, $offset + 4, $length);
        $offset += 4 + $length;

        return [$string, $offset];
    }

    /**
     * @return array<int, bool|int|string>
     */
    public function extractFormat(string $format): array
    {
        $values = [];

        if ('' === $format) {
            throw new \InvalidArgumentException('Empty format specifier');
        }

        preg_match_all('/%([^sub])/', $format, $invalidMatches);
        if (!empty($invalidMatches[1])) {
            throw new \InvalidArgumentException('Unknown format specifier: ' . $invalidMatches[1][0]);
        }

        preg_match_all('/%([sub])/', $format, $matches);
        $specifiers = $matches[1];

        foreach ($specifiers as $spec) {
            if ($this->offset >= strlen($this->message)) {
                return $values;
            }

            switch ($spec) {
                case 's':
                    [$values[], $this->offset] = $this->extractString($this->message, $this->offset);

                    break;

                case 'u':
                    $value = (unpack('N', substr($this->message, $this->offset, 4)) ?: [0])[1] ?? 0;
                    if (!is_int($value)) {
                        throw new \RuntimeException('Unable to unpack 4-byte unsigned int.');
                    }

                    $values[] = (int) $value;
                    $this->offset += 4;

                    break;

                case 'b':
                    $values[] = (bool) ord(substr($this->message, $this->offset, 1));
                    ++$this->offset;

                    break;
            }
        }

        return $values;
    }

    public static function fromData(string $data): self
    {
        $unpackResult = unpack('N', substr($data, 0, 4));
        if (false === $unpackResult) {
            throw new \RuntimeException('Failure determining packetLength');
        }

        $packetLength = $unpackResult[1];
        if (!is_int($packetLength) && !is_float($packetLength) && !is_string($packetLength)) {
            throw new \UnexpectedValueException('Expected int-castable value at offset 1');
        }

        $packetLength = (int) $packetLength;
        $payload = substr($data, 4, $packetLength - 1);
        $paddingLength = ord($payload[0]);
        $payload = substr($payload, 1, $packetLength - $paddingLength - 1);

        return new self($payload);
    }
}
