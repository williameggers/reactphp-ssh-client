<?php declare(strict_types=1);

namespace WilliamEggers\React\SSHClient;

use React\Promise\Deferred;
use React\Promise\PromiseInterface;
use WilliamEggers\React\SSHClient\Channel\SessionChannel;
use WilliamEggers\React\SSHClient\Values\ProcessConfig;
use WilliamEggers\React\SSHClient\Values\ShellConfig;

final readonly class Client
{
    public function __construct(private Connection $connection) {}

    /**
     * @return PromiseInterface<Process>
     */
    public function exec(string $command, ?ProcessConfig $config = null): PromiseInterface
    {
        $config ??= new ProcessConfig();
        /** @var Deferred<Process> $deferred */
        $deferred = new Deferred();

        $this->connection->openSession()->then(function (SessionChannel $channel) use ($command, $config, $deferred): void {
            foreach ($config->environment as $name => $value) {
                $channel->requestEnvironmentVariable($name, $value)->then(null, static function (): void {});
            }

            $channel->requestExec($command)->then(function () use ($channel, $deferred): void {
                $deferred->resolve(new Process($channel));
            }, function (\Throwable $throwable) use ($deferred): void {
                $deferred->reject($throwable);
            });
        }, function (\Throwable $throwable) use ($deferred): void {
            $deferred->reject($throwable);
        });

        return $deferred->promise();
    }

    /**
     * @return PromiseInterface<Shell>
     */
    public function shell(?ShellConfig $config = null): PromiseInterface
    {
        $config ??= new ShellConfig();
        /** @var Deferred<Shell> $deferred */
        $deferred = new Deferred();

        $this->connection->openSession()->then(function (SessionChannel $channel) use ($config, $deferred): void {
            foreach ($config->environment as $name => $value) {
                $channel->requestEnvironmentVariable($name, $value)->then(null, static function (): void {});
            }

            $channel->requestPty($config->term, $config->size)->then(function () use ($channel, $deferred): void {
                $channel->requestShell()->then(function () use ($channel, $deferred): void {
                    $deferred->resolve(new Shell($channel));
                }, function (\Throwable $throwable) use ($deferred): void {
                    $deferred->reject($throwable);
                });
            }, function (\Throwable $throwable) use ($deferred): void {
                $deferred->reject($throwable);
            });
        }, function (\Throwable $throwable) use ($deferred): void {
            $deferred->reject($throwable);
        });

        return $deferred->promise();
    }

    public function close(): void
    {
        $this->connection->close();
    }
}
