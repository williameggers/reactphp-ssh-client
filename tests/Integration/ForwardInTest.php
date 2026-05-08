<?php declare(strict_types=1);

use React\Promise\PromiseInterface;
use WilliamEggers\React\SSHClient\Channel\ForwardedTcpipChannel;
use WilliamEggers\React\SSHClient\Client;
use WilliamEggers\React\SSHClient\Connection;
use WilliamEggers\React\SSHClient\Enums\MessageType;
use WilliamEggers\Tests\React\SSHClient\Support\FakeConnection;

function markConnectionReadyForPackets(Connection $connection): void
{
    $reflection = new ReflectionClass($connection);
    $serverVersion = $reflection->getProperty('serverVersion');
    $serverVersion->setValue($connection, 'SSH-2.0-test');
}

test('forwardIn sends a tcpip-forward request and resolves the requested port', function (): void {
    $socket = new FakeConnection();
    $connection = new Connection($socket);
    markConnectionReadyForPackets($connection);

    $resolvedPort = null;
    $connection->forwardIn('127.0.0.1', 8000)->then(function (int $port) use (&$resolvedPort): void {
        $resolvedPort = $port;
    });

    $packets = $socket->writtenPackets();
    expect($packets)->toHaveCount(1);

    [$requestType, $wantReply, $bindAddress, $bindPort] = $packets[0]->extractFormat('%s%b%s%u');
    expect($packets[0]->type)->toBe(MessageType::GLOBAL_REQUEST);
    expect($requestType)->toBe('tcpip-forward');
    expect($wantReply)->toBeTrue();
    expect($bindAddress)->toBe('127.0.0.1');
    expect($bindPort)->toBe(8000);

    $socket->pushPacket(MessageType::REQUEST_SUCCESS->value);

    expect($resolvedPort)->toBe(8000);
});

test('forwardIn resolves the allocated port when requesting port zero', function (): void {
    $socket = new FakeConnection();
    $connection = new Connection($socket);
    markConnectionReadyForPackets($connection);

    $resolvedPort = null;
    $connection->forwardIn('127.0.0.1', 0)->then(function (int $port) use (&$resolvedPort): void {
        $resolvedPort = $port;
    });

    $socket->pushPacket(MessageType::REQUEST_SUCCESS->value, [49152]);

    expect($resolvedPort)->toBe(49152);
});

test('forwardIn rejects when the server denies the request', function (): void {
    $socket = new FakeConnection();
    $connection = new Connection($socket);
    markConnectionReadyForPackets($connection);

    $rejection = null;
    $connection->forwardIn('127.0.0.1', 8000)->then(null, function (Throwable $throwable) use (&$rejection): void {
        $rejection = $throwable;
    });

    $socket->pushPacket(MessageType::REQUEST_FAILURE->value);

    expect($rejection)->toBeInstanceOf(RuntimeException::class);
    expect($rejection?->getMessage())->toBe('SSH global request failed: tcpip-forward');
});

test('forwardIn rejects invalid bind ports', function (): void {
    $connection = (new ReflectionClass(Connection::class))->newInstanceWithoutConstructor();

    expect(fn (): PromiseInterface => $connection->forwardIn('127.0.0.1', -1))
        ->toThrow(InvalidArgumentException::class, 'Bind port must be between 0 and 65535')
    ;
});

test('client forwards tcp connection events and accepts forwarded-tcpip channels', function (): void {
    $socket = new FakeConnection();
    $connection = new Connection($socket);
    markConnectionReadyForPackets($connection);
    $client = new Client($connection);

    $receivedInfo = null;

    /** @var null|ForwardedTcpipChannel $acceptedChannel */
    $acceptedChannel = null;
    $receivedData = '';
    $closed = false;

    $client->on('tcp connection', function (array $info, Closure $accept, Closure $reject) use (&$receivedInfo, &$acceptedChannel, &$receivedData, &$closed): void {
        $receivedInfo = $info;
        $channel = $accept();
        if (!$channel instanceof ForwardedTcpipChannel) {
            throw new RuntimeException('Expected forwarded-tcpip channel');
        }

        $acceptedChannel = $channel;
        $channel->on('data', static function (string $data) use (&$receivedData): void {
            $receivedData .= $data;
        });
        $channel->on('close', static function () use (&$closed): void {
            $closed = true;
        });
    });

    $socket->pushPacket(MessageType::CHANNEL_OPEN->value, ['forwarded-tcpip', 7, 65535, 32768, '127.0.0.1', 8000, '127.0.0.1', 42000]);

    expect($receivedInfo)->toBe([
        'destIP' => '127.0.0.1',
        'destPort' => 8000,
        'srcIP' => '127.0.0.1',
        'srcPort' => 42000,
    ]);
    expect($acceptedChannel)->toBeInstanceOf(ForwardedTcpipChannel::class);

    $packets = $socket->writtenPackets();
    expect($packets)->toHaveCount(1);
    expect($packets[0]->type)->toBe(MessageType::CHANNEL_OPEN_CONFIRMATION);
    [$recipientChannel, $senderChannel, $window, $maxPacket] = $packets[0]->extractFormat('%u%u%u%u');
    expect($recipientChannel)->toBe(7);
    expect($senderChannel)->toBe(0);
    expect($window)->toBe(1024 * 1024);
    expect($maxPacket)->toBe(32768);

    $socket->pushPacket(MessageType::CHANNEL_DATA->value, [0, 'hello']);
    $socket->pushPacket(MessageType::CHANNEL_CLOSE->value, [0]);

    expect($receivedData)->toBe('hello');
    expect($closed)->toBeTrue();
});

test('tcp connection listener can reject forwarded-tcpip channels', function (): void {
    $socket = new FakeConnection();
    $connection = new Connection($socket);
    markConnectionReadyForPackets($connection);
    $client = new Client($connection);

    $client->on('tcp connection', static function (array $info, Closure $accept, Closure $reject): void {
        $reject();
    });

    $socket->pushPacket(MessageType::CHANNEL_OPEN->value, ['forwarded-tcpip', 5, 65535, 32768, '127.0.0.1', 8000, '127.0.0.1', 42000]);

    $packets = $socket->writtenPackets();
    expect($packets)->toHaveCount(1);
    expect($packets[0]->type)->toBe(MessageType::CHANNEL_OPEN_FAILURE);
    [$recipientChannel, $reasonCode, $description] = $packets[0]->extractFormat('%u%u%s%s');
    expect($recipientChannel)->toBe(5);
    expect($reasonCode)->toBe(1);
    expect($description)->toBe('Forwarded TCP connection rejected');
});
