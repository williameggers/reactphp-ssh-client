<?php declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use React\EventLoop\Loop;
use WilliamEggers\React\SSHClient\Authentication\PasswordAuthentication;
use WilliamEggers\React\SSHClient\Channel\DirectTcpipChannel;
use WilliamEggers\React\SSHClient\Client;
use WilliamEggers\React\SSHClient\Connector;
use WilliamEggers\React\SSHClient\HostKey\CallbackHostKeyVerifier;

$host = getenv('SSH_HOST') ?: '127.0.0.1';
$port = (int) (getenv('SSH_PORT') ?: '2222');
$username = getenv('SSH_USERNAME') ?: 'test';
$password = getenv('SSH_PASSWORD') ?: 'abc123';
$sourceIp = getenv('SSH_FORWARD_SRC_IP') ?: '127.0.0.1';
$sourcePort = (int) (getenv('SSH_FORWARD_SRC_PORT') ?: '8000');
$destinationIp = getenv('SSH_FORWARD_DST_IP') ?: '127.0.0.1';
$destinationPort = (int) (getenv('SSH_FORWARD_DST_PORT') ?: '80');

$connector = new Connector();
$connector->connect(
    sprintf('%s:%d', $host, $port),
    new PasswordAuthentication($username, $password),
    new CallbackHostKeyVerifier(static fn (): bool => true),
)->then(
    function (Client $client) use ($sourceIp, $sourcePort, $destinationIp, $destinationPort): void {
        $client->forwardOut($sourceIp, $sourcePort, $destinationIp, $destinationPort)->then(
            function (DirectTcpipChannel $stream) use ($client, $destinationIp): void {
                $stream->on('data', static function (string $data): void {
                    fwrite(STDOUT, $data);
                });
                $stream->on('close', static function () use ($client): void {
                    $client->close();
                    Loop::stop();
                });

                $stream->end(implode("\r\n", [
                    'HEAD / HTTP/1.1',
                    'User-Agent: reactphp-ssh-client',
                    'Host: ' . $destinationIp,
                    'Accept: */*',
                    'Connection: close',
                    '',
                    '',
                ]));
            },
            static function (Throwable $throwable): void {
                fwrite(STDERR, 'Failed to open direct-tcpip channel: ' . $throwable->getMessage() . PHP_EOL);
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
