<?php declare(strict_types=1);

use React\EventLoop\Loop;
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
