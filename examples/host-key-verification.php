<?php declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use React\EventLoop\Loop;
use WilliamEggers\React\SSHClient\Authentication\PasswordAuthentication;
use WilliamEggers\React\SSHClient\Client;
use WilliamEggers\React\SSHClient\Connector;
use WilliamEggers\React\SSHClient\HostKey\CallbackHostKeyVerifier;
use WilliamEggers\React\SSHClient\HostKey\HostKeyInfo;

$host = getenv('SSH_HOST') ?: '127.0.0.1';
$port = (int) (getenv('SSH_PORT') ?: '2222');
$username = getenv('SSH_USERNAME') ?: 'test';
$password = getenv('SSH_PASSWORD') ?: 'abc123';
$expectedFingerprint = getenv('SSH_HOST_FINGERPRINT') ?: '';

if ('' === $expectedFingerprint) {
    fwrite(STDERR, "Set SSH_HOST_FINGERPRINT to a SHA256 host key fingerprint.\n");
    exit(1);
}

$connector = new Connector();
$connector->connect(
    sprintf('%s:%d', $host, $port),
    new PasswordAuthentication($username, $password),
    new CallbackHostKeyVerifier(static function (HostKeyInfo $hostKey, string $address) use ($expectedFingerprint): bool {
        fwrite(STDERR, sprintf("Server %s presented %s (%s)\n", $address, $hostKey->algorithm, $hostKey->getFingerprintSha256()));

        return hash_equals($expectedFingerprint, $hostKey->getFingerprintSha256());
    }),
)->then(
    static function (Client $client): void {
        fwrite(STDOUT, "Host key verified successfully.\n");
        $client->close();
        Loop::stop();
    },
    static function (Throwable $throwable): void {
        fwrite(STDERR, 'SSH connection failed: ' . $throwable->getMessage() . PHP_EOL);
        Loop::stop();
    }
);

Loop::run();
