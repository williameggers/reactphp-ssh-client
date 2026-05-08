<?php declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use React\EventLoop\Loop;
use React\Promise\Deferred;
use React\Socket\ConnectionInterface as TcpConnectionInterface;
use React\Socket\Connector;
use React\Socket\TcpServer;
use WilliamEggers\React\SSH\Channel;
use WilliamEggers\React\SSH\Connection;
use WilliamEggers\React\SSH\Server;
use WilliamEggers\React\SSH\Values\KeyboardInteractiveConfig;

$host = getenv('SSH_SERVER_HOST') ?: '127.0.0.1';
$port = (int) (getenv('SSH_SERVER_PORT') ?: '2222');
$username = getenv('SSH_SERVER_USERNAME') ?: 'test';
$password = getenv('SSH_SERVER_PASSWORD') ?: 'abc123';
$keyboardResponse = getenv('SSH_SERVER_KEYBOARD_RESPONSE') ?: $password;
$authorizedKey = trim(getenv('SSH_AUTHORIZED_KEY') ?: '');
$hostKeyPath = getenv('SSH_SERVER_HOST_KEY_PATH') ?: '/tmp';
$httpTargetServer = new TcpServer('127.0.0.1:0', Loop::get());
$httpTargetAddress = $httpTargetServer->getAddress();
if (!is_string($httpTargetAddress)) {
    throw new RuntimeException('HTTP target address unavailable');
}

$httpTargetServer->on('connection', static function (TcpConnectionInterface $connection): void {
    $buffer = '';

    $connection->on('data', static function (string $data) use ($connection, &$buffer): void {
        $buffer .= $data;
        if (!str_contains($buffer, "\r\n\r\n")) {
            return;
        }

        $connection->end("HTTP/1.1 200 OK\r\nConnection: close\r\nContent-Length: 5\r\n\r\nhello");
    });
});

$tcpConnector = new Connector(loop: Loop::get());

$server = new Server(
    sprintf('%s:%d', $host, $port),
    60,
    Loop::get(),
    [],
    $hostKeyPath,
);

$server->enableAuthentication();
$server->on('connection', static function (Connection $connection) use ($authorizedKey, $httpTargetAddress, $keyboardResponse, $password, $tcpConnector, $username): void {
    $connection->setKeyboardInteractiveConfig(new KeyboardInteractiveConfig(
        title: 'Example test server',
        instruction: 'Enter the configured password',
        prompts: [['prompt' => 'Password:', 'echo' => false]],
    ));

    $connection->on('authenticate', static function (string $attemptedUsername, string $method, array $credentials, Deferred $authenticated) use ($authorizedKey, $keyboardResponse, $password, $username): void {
        if ($attemptedUsername !== $username) {
            $authenticated->resolve(false);

            return;
        }

        if ('password' === $method) {
            $authenticated->resolve(($credentials[0] ?? null) === $password);

            return;
        }

        if ('keyboard-interactive' === $method) {
            $authenticated->resolve(($credentials[0] ?? null) === $keyboardResponse);

            return;
        }

        if ('publickey' === $method) {
            $presentedKey = $credentials[0] ?? null;
            $authenticated->resolve(is_string($presentedKey) && '' !== $authorizedKey && hash_equals($authorizedKey, trim($presentedKey)));

            return;
        }

        $authenticated->resolve(false);
    });

    $connection->on('channel.open', static function (Channel $channel) use ($httpTargetAddress, $tcpConnector): void {
        if ('direct-tcpip' === $channel->getChannelType()) {
            $tcpConnector->connect($httpTargetAddress)->then(static function (TcpConnectionInterface $target) use ($channel): void {
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
            }, static function (Throwable $throwable) use ($channel): void {
                $channel->write('Failed to connect forward target: ' . $throwable->getMessage() . "\n");
                $channel->close();
            });

            return;
        }

        $channel->on('exec-request', static function (string $command, Deferred $started) use ($channel): void {
            $started->resolve(true);
            $channel->write('executed:' . $command . "\n");
            $channel->end();
        });

        $channel->on('shell-request', static function (Deferred $started) use ($channel): void {
            $started->resolve(true);
            $channel->write("Interactive echo shell ready. Type anything and it will be echoed back.\r\n");
            $channel->on('data', static function (string $data) use ($channel): void {
                $channel->write($data);
            });
        });
    });
});

$serverAddress = $server->getAddress();
if (!is_string($serverAddress)) {
    throw new RuntimeException('Server address unavailable');
}

fwrite(STDOUT, "SSH example server started.\n");
fwrite(STDOUT, 'Listening on ' . $serverAddress . "\n");
fwrite(STDOUT, 'Username: ' . $username . "\n");
fwrite(STDOUT, 'Password: ' . $password . "\n");
fwrite(STDOUT, 'Keyboard-interactive response: ' . $keyboardResponse . "\n");
fwrite(STDOUT, 'Host key path: ' . $hostKeyPath . "\n");
fwrite(STDOUT, 'forwardOut target: ' . $httpTargetAddress . "\n");

if ('' === $authorizedKey) {
    fwrite(STDOUT, "Public key auth: disabled (set SSH_AUTHORIZED_KEY to enable it)\n");
} else {
    fwrite(STDOUT, "Public key auth: enabled\n");
}

fwrite(STDOUT, "\n");
fwrite(STDOUT, "Client example commands:\n");
fwrite(STDOUT, sprintf("  php examples/quickstart.php\n"));
fwrite(STDOUT, sprintf("  php examples/exec.php\n"));
fwrite(STDOUT, sprintf("  php examples/shell.php\n"));
fwrite(STDOUT, sprintf("  php examples/forward-out.php\n"));
fwrite(STDOUT, sprintf("  php examples/forward-out-pipe.php\n"));
fwrite(STDOUT, sprintf("  php examples/keyboard-interactive.php\n"));

if ('' !== $authorizedKey) {
    fwrite(STDOUT, sprintf("  SSH_PRIVATE_KEY=/path/to/id_ed25519 php examples/public-key-authentication.php\n"));
}

fwrite(STDOUT, "\n");
fwrite(STDOUT, "Press Ctrl-C to stop the server.\n");

Loop::run();
