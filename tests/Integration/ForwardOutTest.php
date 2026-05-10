<?php declare(strict_types=1);

use React\EventLoop\Loop;
use React\Promise\PromiseInterface;
use WilliamEggers\React\SSHClient\Authentication\PasswordAuthentication;
use WilliamEggers\React\SSHClient\Channel\DirectTcpipChannel;
use WilliamEggers\React\SSHClient\Client;
use WilliamEggers\React\SSHClient\Connection;
use WilliamEggers\React\SSHClient\Connector;
use WilliamEggers\React\SSHClient\HostKey\CallbackHostKeyVerifier;
use WilliamEggers\Tests\React\SSHClient\Support\TestSshServer;

test('forwardOut opens a direct-tcpip stream and reads the response after end', function (): void {
    $server = null;
    TestSshServer::forwardOutServer()->then(static function (TestSshServer $resolved) use (&$server): void {
        $server = $resolved;
    });

    Loop::futureTick(function () use (&$server): void {
        if (!$server instanceof TestSshServer) {
            return;
        }

        $address = $server->getAddress();
        $forwardTargetPort = parse_url($server->getForwardTargetAddress(), PHP_URL_PORT);
        if (!is_int($forwardTargetPort)) {
            throw new RuntimeException('Forward target port unavailable');
        }

        $connector = new Connector();
        $connector->connect(
            $address,
            new PasswordAuthentication('user', 'secret'),
            new CallbackHostKeyVerifier(static fn (): bool => true),
        )->then(function (Client $client) use (&$server): void {
            $forwardTargetPort = parse_url($server->getForwardTargetAddress(), PHP_URL_PORT);
            if (!is_int($forwardTargetPort)) {
                throw new RuntimeException('Forward target port unavailable');
            }

            $client->forwardOut('127.0.0.1', 8000, '127.0.0.1', $forwardTargetPort)->then(function (DirectTcpipChannel $stream) use ($client, &$server): void {
                $response = '';

                $stream->on('data', static function (string $data) use (&$response): void {
                    $response .= $data;
                });
                $stream->on('close', static function () use (&$response, $client, &$server): void {
                    expect($response)->toContain('HTTP/1.1 200 OK');
                    expect($response)->toContain('hello');
                    $client->close();
                    $server->close();
                    Loop::stop();
                });

                $stream->end(implode("\r\n", [
                    'HEAD / HTTP/1.1',
                    'Host: 127.0.0.1',
                    'Connection: close',
                    '',
                    '',
                ]));
            }, static function (Throwable $throwable): void {
                throw $throwable;
            });
        }, static function (Throwable $throwable): void {
            throw $throwable;
        });
    });

    Loop::run();
});

test('forwardOut rejects invalid ports', function (): void {
    $connection = (new ReflectionClass(Connection::class))->newInstanceWithoutConstructor();

    expect(fn (): PromiseInterface => $connection->openDirectTcpip('127.0.0.1', -1, '127.0.0.1', 80))
        ->toThrow(InvalidArgumentException::class, 'Source port must be between 0 and 65535')
    ;

    expect(fn (): PromiseInterface => $connection->openDirectTcpip('127.0.0.1', 1234, '127.0.0.1', 70000))
        ->toThrow(InvalidArgumentException::class, 'Destination port must be between 0 and 65535')
    ;
});
