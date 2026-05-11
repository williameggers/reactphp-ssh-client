<?php declare(strict_types=1);

use WilliamEggers\React\SSHClient\Authentication\PasswordAuthentication;
use WilliamEggers\React\SSHClient\Connection;
use WilliamEggers\React\SSHClient\Connector;
use WilliamEggers\React\SSHClient\HostKey\CallbackHostKeyVerifier;
use WilliamEggers\Tests\React\SSHClient\Support\FakeConnection;

test('connector rejects non-positive timeout values', function (): void {
    expect(fn (): Connector => new Connector(timeout: 0.0))
        ->toThrow(InvalidArgumentException::class, 'Connector timeout must be greater than 0 seconds or null to disable it')
    ;

    expect(fn (): Connector => new Connector(timeout: -1.0))
        ->toThrow(InvalidArgumentException::class, 'Connector timeout must be greater than 0 seconds or null to disable it')
    ;
});

test('connector accepts null timeout to disable it', function (): void {
    expect(new Connector(timeout: null))->toBeInstanceOf(Connector::class);
});

test('connection advertises connector version during handshake', function (): void {
    $socket = new FakeConnection();
    $connection = new Connection($socket);

    $connection->start(
        new PasswordAuthentication('test', 'secret'),
        new CallbackHostKeyVerifier(static fn (): bool => true),
    );

    expect($socket->writtenData())->toBe([
        'SSH-2.0-ReactPHP-SSHClient_' . Connector::VERSION . "\r\n",
    ]);
});
