<?php declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use React\EventLoop\Loop;
use WilliamEggers\React\SSHClient\Authentication\PasswordAuthentication;
use WilliamEggers\React\SSHClient\Channel\ForwardedTcpipChannel;
use WilliamEggers\React\SSHClient\Client;
use WilliamEggers\React\SSHClient\Connector;
use WilliamEggers\React\SSHClient\HostKey\CallbackHostKeyVerifier;

$host = getenv('SSH_HOST') ?: '127.0.0.1';
$port = (int) (getenv('SSH_PORT') ?: '2222');
$username = getenv('SSH_USERNAME') ?: 'test';
$password = getenv('SSH_PASSWORD') ?: 'abc123';
$bindAddress = getenv('SSH_FORWARD_BIND_IP') ?: '127.0.0.1';
$bindPort = (int) (getenv('SSH_FORWARD_BIND_PORT') ?: '8000');

$connector = new Connector();
$connector->connect(
    sprintf('%s:%d', $host, $port),
    new PasswordAuthentication($username, $password),
    new CallbackHostKeyVerifier(static fn (): bool => true),
)->then(
    function (Client $client) use ($bindAddress, $bindPort): void {
        $client->on('tcp connection', function (array $info, Closure $accept, Closure $reject): void {
            fwrite(STDOUT, 'Incoming TCP connection: ' . json_encode($info, JSON_THROW_ON_ERROR) . PHP_EOL);

            /** @var ForwardedTcpipChannel $stream */
            $stream = $accept();
            $stream->on('data', static function (string $data): void {
                fwrite(STDOUT, $data);
            });
            $stream->on('close', static function (): void {
                fwrite(STDOUT, 'Forwarded TCP connection closed' . PHP_EOL);
            });

            $stream->end(implode("\r\n", [
                'HTTP/1.1 404 Not Found',
                'Server: reactphp-ssh-client',
                'Content-Length: 0',
                'Connection: close',
                '',
                '',
            ]));
        });

        $client->forwardIn($bindAddress, $bindPort)->then(
            static function (int $listeningPort) use ($bindAddress): void {
                fwrite(STDOUT, 'Listening for forwarded TCP connections on port ' . $listeningPort . PHP_EOL);
                fwrite(STDOUT, 'Try from another terminal: curl -i http://' . $bindAddress . ':' . $listeningPort . PHP_EOL);
            },
            static function (Throwable $throwable) use ($client): void {
                fwrite(STDERR, 'Failed to request remote port forwarding: ' . $throwable->getMessage() . PHP_EOL);
                $client->close();
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
