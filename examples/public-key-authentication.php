<?php declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use React\EventLoop\Loop;
use WilliamEggers\React\SSHClient\Authentication\PublicKeyAuthentication;
use WilliamEggers\React\SSHClient\Client;
use WilliamEggers\React\SSHClient\Connector;
use WilliamEggers\React\SSHClient\HostKey\CallbackHostKeyVerifier;
use WilliamEggers\React\SSHClient\Process;

$host = getenv('SSH_HOST') ?: '127.0.0.1';
$port = (int) (getenv('SSH_PORT') ?: '2222');
$username = getenv('SSH_USERNAME') ?: 'test';
$privateKey = getenv('SSH_PRIVATE_KEY') ?: '';
$passphrase = getenv('SSH_PRIVATE_KEY_PASSPHRASE') ?: null;
$command = getenv('SSH_COMMAND') ?: 'whoami';

if ('' === $privateKey) {
    fwrite(STDERR, "Set SSH_PRIVATE_KEY to the path of a private key file.\n");
    exit(1);
}

$connector = new Connector();
$connector->connect(
    sprintf('%s:%d', $host, $port),
    PublicKeyAuthentication::fromPath($username, $privateKey, $passphrase),
    new CallbackHostKeyVerifier(static fn (): bool => true),
)->then(
    function (Client $client) use ($command): void {
        $client->exec($command)->then(
            function (Process $process) use ($client): void {
                $process->stdout()->on('data', static function (string $data): void {
                    fwrite(STDOUT, $data);
                });

                $process->stderr()->on('data', static function (string $data): void {
                    fwrite(STDERR, $data);
                });

                $process->join()->then(static function (int $exitCode) use ($client): void {
                    fwrite(STDERR, sprintf("\nProcess exited with code %d\n", $exitCode));
                    $client->close();
                    Loop::stop();
                });
            },
            static function (Throwable $throwable): void {
                fwrite(STDERR, 'Failed to start process: ' . $throwable->getMessage() . PHP_EOL);
                Loop::stop();
            }
        );
    },
    static function (Throwable $throwable): void {
        fwrite(STDERR, 'SSH connection failed: ' . $throwable->getMessage() . PHP_EOL);
        Loop::stop();
    }
);

Loop::run();
