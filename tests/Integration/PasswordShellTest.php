<?php declare(strict_types=1);

use React\EventLoop\Loop;
use React\Socket\ConnectionInterface;
use React\Socket\TcpServer;
use WilliamEggers\React\SSHClient\Authentication\PasswordAuthentication;
use WilliamEggers\React\SSHClient\Client;
use WilliamEggers\React\SSHClient\Connector;
use WilliamEggers\React\SSHClient\HostKey\CallbackHostKeyVerifier;
use WilliamEggers\Tests\React\SSHClient\Support\TestSshServer;

test('connects with password auth and opens shell', function (): void {
    $address = null;
    TestSshServer::shellEchoServer()->then(static function (string $resolvedAddress) use (&$address): void {
        $address = $resolvedAddress;
    });

    Loop::futureTick(function () use (&$address): void {
        if (!is_string($address)) {
            return;
        }

        $connector = new Connector();
        $connector->connect(
            $address,
            new PasswordAuthentication('user', 'secret'),
            new CallbackHostKeyVerifier(static fn (): bool => true),
        )->then(function (Client $client): void {
            expect($client)->toBeInstanceOf(Client::class);
            Loop::stop();
        }, function (Throwable $throwable): void {
            throw $throwable;
        });
    });

    Loop::run();
});

test('connect rejects when SSH handshake exceeds timeout', function (): void {
    $server = new TcpServer('127.0.0.1:0');
    $server->on('connection', static function (ConnectionInterface $connection): void {
        $connection->on('data', static function (): void {});
    });
    $address = $server->getAddress() ?? throw new RuntimeException('Server address unavailable');

    Loop::futureTick(function () use ($address, $server): void {
        $connector = new Connector(timeout: 0.05);
        $connector->connect(
            $address,
            new PasswordAuthentication('user', 'secret'),
            new CallbackHostKeyVerifier(static fn (): bool => true),
        )->then(
            static function (): void {
                throw new RuntimeException('Expected connection timeout');
            },
            function (Throwable $throwable) use ($server): void {
                expect($throwable)->toBeInstanceOf(RuntimeException::class);
                expect($throwable->getMessage())->toContain('timed out after 0.05 seconds');
                $server->close();
                Loop::stop();
            }
        );
    });

    Loop::run();
});

test('connect allows disabling the full SSH timeout', function (): void {
    $stillPending = false;
    $server = new TcpServer('127.0.0.1:0');
    $server->on('connection', static function (ConnectionInterface $connection): void {
        $connection->on('data', static function (): void {});
    });
    $address = $server->getAddress() ?? throw new RuntimeException('Server address unavailable');

    Loop::futureTick(function () use ($address, $server, &$stillPending): void {
        $connector = new Connector(timeout: null);
        $connector->connect(
            $address,
            new PasswordAuthentication('user', 'secret'),
            new CallbackHostKeyVerifier(static fn (): bool => true),
        )->then(
            static function (): void {
                throw new RuntimeException('Expected pending connection');
            },
            static function (Throwable $throwable): void {
                throw $throwable;
            }
        );

        Loop::addTimer(0.05, function () use (&$stillPending, $server): void {
            $stillPending = true;
            $server->close();
            Loop::stop();
        });
    });

    Loop::run();

    expect($stillPending)->toBeTrue();
});
