<?php declare(strict_types=1);

namespace WilliamEggers\React\SSHClient;

use Psr\Log\LoggerInterface;
use React\Promise\PromiseInterface;
use WilliamEggers\React\SSHClient\Authentication\AuthenticationInterface;
use WilliamEggers\React\SSHClient\HostKey\HostKeyVerifierInterface;
use WilliamEggers\React\SSHClient\Loggers\NullLogger;

final readonly class Connector
{
    public function __construct(private ?LoggerInterface $logger = null) {}

    /**
     * @return PromiseInterface<Client>
     */
    public function connect(
        string $uri,
        AuthenticationInterface $authentication,
        HostKeyVerifierInterface $hostKeyVerifier,
    ): PromiseInterface {
        return Connection::connect(
            $uri,
            $authentication,
            $hostKeyVerifier,
            $this->logger ?? new NullLogger(),
        );
    }
}
