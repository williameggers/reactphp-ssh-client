<?php declare(strict_types=1);

namespace WilliamEggers\Tests\React\SSHClient\Support;

use React\EventLoop\Loop;
use React\EventLoop\LoopInterface;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;
use React\Socket\ConnectionInterface as TcpConnectionInterface;
use React\Socket\Connector;
use React\Socket\TcpServer;
use WilliamEggers\React\SSH\Channel;
use WilliamEggers\React\SSH\Connection;
use WilliamEggers\React\SSH\Server;
use WilliamEggers\React\SSH\Values\KeyboardInteractiveConfig;

final class TestSshServer
{
    private ?Server $server = null;
    private ?string $address = null;
    private ?TcpServer $tcpServer = null;
    private ?TcpServer $forwardTargetServer = null;
    private ?string $forwardTargetAddress = null;

    /** @var list<TcpConnectionInterface> */
    private array $tcpConnections = [];

    /**
     * @param callable(Connection): void $configure
     */
    public function __construct(
        LoopInterface $loop,
        callable $configure,
    ) {
        $this->server = new Server('127.0.0.1:0', 60, $loop, [], sys_get_temp_dir() . '/reactphp-ssh-client-tests');
        $configure = \Closure::fromCallable($configure);
        $this->server->enableAuthentication();
        $this->server->on('connection', static function (Connection $connection) use ($configure): void {
            $configure($connection);
        });
    }

    public function getAddress(): string
    {
        return $this->address
            ?? $this->tcpServer?->getAddress()
            ?? $this->server?->getAddress()
            ?? throw new \RuntimeException('Server address unavailable');
    }

    public function close(): void
    {
        $this->server?->close();
        foreach ($this->tcpConnections as $connection) {
            $connection->close();
        }

        $this->tcpServer?->close();
        $this->forwardTargetServer?->close();
    }

    public function getForwardTargetAddress(): string
    {
        return $this->forwardTargetAddress ?? throw new \RuntimeException('Forward target address unavailable');
    }

    /**
     * @return PromiseInterface<string>
     */
    public static function shellEchoServer(string $password = 'secret'): PromiseInterface
    {
        /** @var Deferred<string> $deferred */
        $deferred = new Deferred();
        $loop = Loop::get();

        $server = new self($loop, function (Connection $connection) use ($password): void {
            $connection->on('authenticate', function (string $username, string $method, array $credentials, Deferred $authDeferred) use ($password): void {
                if ('password' === $method) {
                    $authDeferred->resolve(($credentials[0] ?? null) === $password);

                    return;
                }

                $authDeferred->resolve(false);
            });

            $connection->on('channel.open', function (Channel $channel): void {
                $channel->on('shell-request', function (Deferred $started) use ($channel): void {
                    $started->resolve(true);
                    $channel->on('data', function (string $data) use ($channel): void {
                        $channel->write($data);
                    });
                });
            });
        });

        $deferred->resolve($server->getAddress());

        return $deferred->promise();
    }

    /**
     * @return PromiseInterface<string>
     */
    public static function execServer(string $password = 'secret'): PromiseInterface
    {
        /** @var Deferred<string> $deferred */
        $deferred = new Deferred();
        $loop = Loop::get();

        $server = new self($loop, function (Connection $connection) use ($password): void {
            $connection->on('authenticate', function (string $username, string $method, array $credentials, Deferred $authDeferred) use ($password): void {
                if ('password' === $method) {
                    $authDeferred->resolve(($credentials[0] ?? null) === $password);

                    return;
                }

                $authDeferred->resolve(false);
            });

            $connection->on('channel.open', function (Channel $channel): void {
                $channel->on('exec-request', function (string $command, Deferred $started) use ($channel): void {
                    $started->resolve(true);
                    $channel->write('executed:' . $command);
                    $channel->end();
                });
            });
        });

        $deferred->resolve($server->getAddress());

        return $deferred->promise();
    }

    /**
     * @return PromiseInterface<string>
     */
    public static function keyboardInteractiveServer(string $response = 'secret'): PromiseInterface
    {
        /** @var Deferred<string> $deferred */
        $deferred = new Deferred();
        $loop = Loop::get();

        $server = new self($loop, function (Connection $connection) use ($response): void {
            $connection->setKeyboardInteractiveConfig(new KeyboardInteractiveConfig(
                title: 'Login',
                instruction: 'Enter password',
                prompts: [['prompt' => 'Password:', 'echo' => false]],
            ));

            $connection->on('authenticate', function (string $username, string $method, array $credentials, Deferred $authDeferred) use ($response): void {
                if ('keyboard-interactive' === $method) {
                    $authDeferred->resolve(($credentials[0] ?? null) === $response);

                    return;
                }

                $authDeferred->resolve(false);
            });
        });

        $deferred->resolve($server->getAddress());

        return $deferred->promise();
    }

    /**
     * @return PromiseInterface<self>
     */
    public static function silentTcpServer(): PromiseInterface
    {
        /** @var Deferred<self> $deferred */
        $deferred = new Deferred();
        $loop = Loop::get();
        $server = new self($loop, static function (): void {});
        $server->server?->close();
        $server->server = null;
        $server->tcpServer = new TcpServer('127.0.0.1:0', $loop);
        $server->address = $server->tcpServer->getAddress();
        $server->tcpServer->on('connection', function (TcpConnectionInterface $connection) use ($server): void {
            $server->tcpConnections[] = $connection;
            $connection->on('close', function () use ($server, $connection): void {
                $server->tcpConnections = array_values(array_filter(
                    $server->tcpConnections,
                    static fn (TcpConnectionInterface $existing): bool => $existing !== $connection,
                ));
            });
            $connection->on('data', static function (): void {});
        });

        $deferred->resolve($server);

        return $deferred->promise();
    }

    /**
     * @return PromiseInterface<self>
     */
    public static function forwardOutServer(string $password = 'secret'): PromiseInterface
    {
        /** @var Deferred<self> $deferred */
        $deferred = new Deferred();
        $loop = Loop::get();
        $connector = new Connector(loop: $loop);

        $server = null;
        $server = new self($loop, function (Connection $connection) use ($password, $connector, &$server): void {
            $connection->on('authenticate', function (string $username, string $method, array $credentials, Deferred $authDeferred) use ($password): void {
                if ('password' === $method) {
                    $authDeferred->resolve(($credentials[0] ?? null) === $password);

                    return;
                }

                $authDeferred->resolve(false);
            });

            $connection->on('channel.open', function (Channel $channel) use ($connector, &$server): void {
                if ('direct-tcpip' !== $channel->getChannelType()) {
                    return;
                }

                $fixture = $server ?? throw new \RuntimeException('Forward server fixture unavailable');

                $connector->connect($fixture->getForwardTargetAddress())->then(function (TcpConnectionInterface $target) use ($channel, $fixture): void {
                    $fixture->tcpConnections[] = $target;

                    $target->on('data', static function (string $data) use ($channel): void {
                        $channel->write($data);
                    });
                    $target->on('close', static function () use ($channel): void {
                        $channel->close();
                    });
                    $channel->on('data', static function (string $data) use ($target): void {
                        $target->write($data);
                    });
                    $channel->on('close', static function () use ($target): void {
                        $target->close();
                    });
                }, static function (\Throwable $throwable) use ($channel): void {
                    $channel->write('connect failed:' . $throwable->getMessage());
                    $channel->close();
                });
            });
        });

        $server->forwardTargetServer = new TcpServer('127.0.0.1:0', $loop);
        $server->forwardTargetAddress = $server->forwardTargetServer->getAddress();
        $server->forwardTargetServer->on('connection', function (TcpConnectionInterface $connection) use ($server): void {
            $server->tcpConnections[] = $connection;

            $buffer = '';
            $connection->on('data', function (string $data) use ($connection, &$buffer): void {
                $buffer .= $data;
                if (!str_contains($buffer, "\r\n\r\n")) {
                    return;
                }

                $connection->end("HTTP/1.1 200 OK\r\nConnection: close\r\nContent-Length: 5\r\n\r\nhello");
            });
        });

        $deferred->resolve($server);

        return $deferred->promise();
    }
}
