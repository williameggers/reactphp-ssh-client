<?php declare(strict_types=1);

namespace WilliamEggers\Tests\React\SSHClient\Support;

use React\EventLoop\Loop;
use React\EventLoop\LoopInterface;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;
use WilliamEggers\React\SSH\Channel;
use WilliamEggers\React\SSH\Connection;
use WilliamEggers\React\SSH\Server;
use WilliamEggers\React\SSH\Values\KeyboardInteractiveConfig;

final class TestSshServer
{
    private readonly Server $server;

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
        return $this->server->getAddress() ?? throw new \RuntimeException('Server address unavailable');
    }

    public function close(): void
    {
        $this->server->close();
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
}
