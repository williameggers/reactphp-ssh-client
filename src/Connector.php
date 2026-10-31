<?php declare(strict_types=1);

namespace WilliamEggers\React\SSHClient;

use Psr\Log\LoggerInterface;
use React\Promise\PromiseInterface;
use React\Socket\ConnectorInterface as SocketConnectorInterface;
use WilliamEggers\React\SSHClient\Authentication\AuthenticationInterface;
use WilliamEggers\React\SSHClient\HostKey\HostKeyVerifierInterface;
use WilliamEggers\React\SSHClient\Loggers\NullLogger;

final readonly class Connector
{
    /**
     * Current version of the ReactPHP SSH client library.
     *
     * This value is used in the client identification string during the SSH handshake.
     */
    public const VERSION = '2.0.0';

    /**
     * Create a connector for opening SSH client sessions.
     *
     * @param null|LoggerInterface          $logger          optional logger for connection lifecycle events
     * @param null|float                    $timeout         full connection timeout in seconds, or null to disable it
     * @param null|SocketConnectorInterface $socketConnector optional underlying socket connector used for the TCP dial step
     */
    public function __construct(
        private ?LoggerInterface $logger = null,
        private ?float $timeout = 30.0,
        private ?SocketConnectorInterface $socketConnector = null,
    ) {
        if (null !== $this->timeout && $this->timeout <= 0) {
            throw new \InvalidArgumentException('Connector timeout must be greater than 0 seconds or null to disable it');
        }
    }

    /**
     * Open and authenticate an SSH connection.
     *
     * @param string                   $uri             SSH server address in host:port form
     * @param AuthenticationInterface  $authentication  authentication strategy used for login
     * @param HostKeyVerifierInterface $hostKeyVerifier host key verifier invoked during handshake
     *
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
            $this->socketConnector,
        );
    }
}
