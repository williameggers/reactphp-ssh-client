<?php declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use React\EventLoop\Loop;
use WilliamEggers\React\SSHClient\Authentication\KeyboardInteractiveAuthentication;
use WilliamEggers\React\SSHClient\Client;
use WilliamEggers\React\SSHClient\Connector;
use WilliamEggers\React\SSHClient\HostKey\CallbackHostKeyVerifier;
use WilliamEggers\React\SSHClient\Values\KeyboardInteractiveChallenge;

$host = getenv('SSH_HOST') ?: '127.0.0.1';
$port = (int) (getenv('SSH_PORT') ?: '2222');
$username = getenv('SSH_USERNAME') ?: 'test';
$responses = array_map('trim', explode(',', getenv('SSH_KEYBOARD_RESPONSES') ?: 'abc123'));

$connector = new Connector();
$connector->connect(
    sprintf('%s:%d', $host, $port),
    new KeyboardInteractiveAuthentication(
        $username,
        static function (KeyboardInteractiveChallenge $challenge) use ($responses): array {
            fwrite(STDERR, $challenge->name . PHP_EOL);
            fwrite(STDERR, $challenge->instruction . PHP_EOL);

            foreach ($challenge->prompts as $index => $prompt) {
                fwrite(STDERR, sprintf("Prompt %d: %s\n", $index + 1, $prompt->prompt));
            }

            return $responses;
        }
    ),
    new CallbackHostKeyVerifier(static fn (): bool => true),
)->then(
    static function (Client $client): void {
        fwrite(STDOUT, "Authenticated successfully with keyboard-interactive auth.\n");
        $client->close();
        Loop::stop();
    },
    static function (Throwable $throwable): void {
        fwrite(STDERR, 'SSH connection failed: ' . $throwable->getMessage() . PHP_EOL);
        Loop::stop();
    }
);

Loop::run();
