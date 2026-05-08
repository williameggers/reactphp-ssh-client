<?php declare(strict_types=1);

namespace WilliamEggers\React\SSHClient;

use Psr\Log\LoggerInterface;
use React\Promise\PromiseInterface;
use WilliamEggers\React\SSHClient\Authentication\AuthenticationInterface;
use WilliamEggers\React\SSHClient\HostKey\HostKeyVerifierInterface;
use WilliamEggers\React\SSHClient\Loggers\NullLogger;

final readonly class Connector
{
    public function __construct(
        private ?LoggerInterface $logger = null,
        private ?float $timeout = 30.0,
    ) {
        if (null !== $this->timeout && $this->timeout <= 0) {
            throw new \InvalidArgumentException('Connector timeout must be greater than 0 seconds or null to disable it');
        }
    }

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
            $this->timeout,
        );
    }
}
