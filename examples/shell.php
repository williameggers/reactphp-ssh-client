<?php declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use React\EventLoop\Loop;
use WilliamEggers\React\SSHClient\Authentication\PasswordAuthentication;
use WilliamEggers\React\SSHClient\Client;
use WilliamEggers\React\SSHClient\Connector;
use WilliamEggers\React\SSHClient\HostKey\CallbackHostKeyVerifier;
use WilliamEggers\React\SSHClient\Shell;
use WilliamEggers\React\SSHClient\Values\ShellConfig;
use WilliamEggers\React\SSHClient\Values\WinSize;

$host = getenv('SSH_HOST') ?: '127.0.0.1';
$port = (int) (getenv('SSH_PORT') ?: '2222');
$username = getenv('SSH_USERNAME') ?: 'test';
$password = getenv('SSH_PASSWORD') ?: 'abc123';
$term = getenv('SSH_TERM') ?: 'xterm-256color';
$rows = (int) (getenv('SSH_ROWS') ?: '24');
$cols = (int) (getenv('SSH_COLS') ?: '80');

$restoreTerminal = static function (): void {};

if (stream_isatty(STDIN)) {
    $sttyMode = null;
    exec('stty -g', $sttyOutput, $sttyExitCode);

    if (0 === $sttyExitCode && isset($sttyOutput[0]) && '' !== $sttyOutput[0]) {
        $sttyMode = $sttyOutput[0];
        exec('stty raw -echo', result_code: $rawModeExitCode);

        if (0 === $rawModeExitCode) {
            $restoreTerminal = static function () use ($sttyMode): void {
                exec('stty ' . escapeshellarg($sttyMode));
            };
            register_shutdown_function($restoreTerminal);
        }
    }
}

$connector = new Connector(timeout: 30.0);
$connector->connect(
    sprintf('%s:%d', $host, $port),
    new PasswordAuthentication($username, $password),
    new CallbackHostKeyVerifier(static fn (): bool => true),
)->then(
    function (Client $client) use ($term, $rows, $cols, $restoreTerminal): void {
        $client->shell(new ShellConfig($term, new WinSize($rows, $cols)))->then(
            function (Shell $shell) use ($client, $restoreTerminal): void {
                fwrite(STDERR, "Interactive shell started. Press Ctrl-D to send EOF.\n");

                stream_set_blocking(STDIN, false);

                $shell->stdout()->on('data', static function (string $data): void {
                    fwrite(STDOUT, $data);
                });

                $shell->stderr()->on('data', static function (string $data): void {
                    fwrite(STDERR, $data);
                });

                Loop::addReadStream(STDIN, static function ($stdin) use ($shell): void {
                    if (!is_resource($stdin)) {
                        return;
                    }

                    $data = fread($stdin, 8192);

                    if (false === $data) {
                        return;
                    }

                    if ('' === $data && feof($stdin)) {
                        Loop::removeReadStream($stdin);
                        $shell->stdin()->end();

                        return;
                    }

                    if ('' !== $data) {
                        $shell->stdin()->write($data);
                    }
                });

                $shell->join()->then(static function (int $exitCode) use ($client, $restoreTerminal): void {
                    $restoreTerminal();
                    Loop::removeReadStream(STDIN);
                    fwrite(STDERR, sprintf("\nShell exited with code %d\n", $exitCode));
                    $client->close();
                    Loop::stop();
                });
            },
            static function (Throwable $throwable) use ($restoreTerminal): void {
                $restoreTerminal();
                fwrite(STDERR, 'Failed to start shell: ' . $throwable->getMessage() . PHP_EOL);
                Loop::stop();
            }
        );
    },
    static function (Throwable $throwable) use ($restoreTerminal): void {
        $restoreTerminal();
        fwrite(STDERR, 'SSH connection failed: ' . $throwable->getMessage() . PHP_EOL);
        Loop::stop();
    }
);

Loop::run();
