<?php declare(strict_types=1);

use React\EventLoop\Loop;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;
use React\Socket\ConnectionInterface;
use React\Socket\ConnectorInterface as SocketConnectorInterface;
use React\Socket\TcpServer;
use WilliamEggers\React\SSHClient\Authentication\PasswordAuthentication;
use WilliamEggers\React\SSHClient\Client;
use WilliamEggers\React\SSHClient\Connector;
use WilliamEggers\React\SSHClient\Exception\AuthenticationException;
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

test('connect rejects when password authentication fails', function (): void {
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
            new PasswordAuthentication('user', 'wrong-password'),
            new CallbackHostKeyVerifier(static fn (): bool => true),
        )->then(
            static function (): void {
                throw new RuntimeException('Expected password authentication failure');
            },
            static function (Throwable $throwable): void {
                expect($throwable)->toBeInstanceOf(AuthenticationException::class);
                expect($throwable->getMessage())->toBe('Password authentication failed');
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

test('connect uses injected socket connector for TCP dial step', function (): void {
    $address = null;
    TestSshServer::shellEchoServer()->then(static function (string $resolvedAddress) use (&$address): void {
        $address = $resolvedAddress;
    });

    Loop::futureTick(function () use (&$address): void {
        if (!is_string($address)) {
            return;
        }

        $socketConnector = new class implements SocketConnectorInterface {
            public ?string $connectedUri = null;

            public function connect($uri): PromiseInterface
            {
                $this->connectedUri = $uri;

                /** @var Deferred<ConnectionInterface> $deferred */
                $deferred = new Deferred();

                Loop::addTimer(0.001, function () use ($uri, $deferred): void {
                    (new React\Socket\Connector())->connect($uri)->then(
                        static function (ConnectionInterface $connection) use ($deferred): void {
                            $deferred->resolve($connection);
                        },
                        static function (Throwable $throwable) use ($deferred): void {
                            $deferred->reject($throwable);
                        }
                    );
                });

                return $deferred->promise();
            }
        };

        $connector = new Connector(socketConnector: $socketConnector);
        $connector->connect(
            $address,
            new PasswordAuthentication('user', 'secret'),
            new CallbackHostKeyVerifier(static fn (): bool => true),
        )->then(function (Client $client) use ($socketConnector, $address): void {
            expect($client)->toBeInstanceOf(Client::class);
            expect($socketConnector->connectedUri)->toBe($address);
            Loop::stop();
        }, function (Throwable $throwable): void {
            throw $throwable;
        });
    });

    Loop::run();
});
