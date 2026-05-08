<?php declare(strict_types=1);

namespace WilliamEggers\React\SSHClient;

use Evenement\EventEmitterTrait;
use Psr\Log\LoggerInterface;
use React\EventLoop\Loop;
use React\EventLoop\LoopInterface;
use React\EventLoop\TimerInterface;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;
use React\Socket\ConnectionInterface;
use React\Socket\Connector as SocketConnector;
use React\Socket\ConnectorInterface as SocketConnectorInterface;
use WilliamEggers\React\SSHClient\Authentication\AuthenticationInterface;
use WilliamEggers\React\SSHClient\Authentication\KeyboardInteractiveAuthentication;
use WilliamEggers\React\SSHClient\Authentication\PasswordAuthentication;
use WilliamEggers\React\SSHClient\Authentication\PublicKeyAuthentication;
use WilliamEggers\React\SSHClient\Channel\ChannelInterface;
use WilliamEggers\React\SSHClient\Channel\DirectTcpipChannel;
use WilliamEggers\React\SSHClient\Channel\ForwardedTcpipChannel;
use WilliamEggers\React\SSHClient\Channel\SessionChannel;
use WilliamEggers\React\SSHClient\Concerns\WritesLogs;
use WilliamEggers\React\SSHClient\Enums\MessageType;
use WilliamEggers\React\SSHClient\Exception\AuthenticationException;
use WilliamEggers\React\SSHClient\Exception\ChannelOpenException;
use WilliamEggers\React\SSHClient\Exception\HostKeyException;
use WilliamEggers\React\SSHClient\Exception\ProtocolException;
use WilliamEggers\React\SSHClient\HostKey\HostKeyInfo;
use WilliamEggers\React\SSHClient\HostKey\HostKeyVerifierInterface;
use WilliamEggers\React\SSHClient\Loggers\NullLogger;
use WilliamEggers\React\SSHClient\Values\KeyboardInteractiveChallenge;
use WilliamEggers\React\SSHClient\Values\KeyboardInteractivePrompt;

final class Connection
{
    use EventEmitterTrait;
    use WritesLogs;

    private string $clientVersion = 'SSH-2.0-ReactPHP-SSHClient_0.1';
    private ?string $serverVersion = null;
    private string $inputBuffer = '';
    private PacketHandler $packetHandler;
    private readonly PublicKeyHelper $publicKeyHelper;
    private readonly KexNegotiator $kexNegotiator;
    private ?Kex $kex = null;
    private int $nextChannelId = 0;
    private ?string $sessionId = null;

    /** @var array<int, ChannelInterface> */
    private array $channels = [];

    /** @var array<int, Deferred<bool>> */
    private array $channelReplies = [];

    /** @var array<int, Deferred<ChannelInterface>> */
    private array $pendingChannelOpens = [];

    /** @var array<int, string> */
    private array $pendingChannelTypes = [];

    /** @var list<array{type: string, bindPort: int, deferred: Deferred<int>}> */
    private array $pendingGlobalRequests = [];

    /** @var null|Deferred<Client> */
    private ?Deferred $handshakeDeferred = null;
    private ?AuthenticationInterface $authentication = null;
    private ?HostKeyVerifierInterface $hostKeyVerifier = null;

    public function __construct(
        private readonly ConnectionInterface $connection,
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
        $this->packetHandler = new PacketHandler($connection);
        $this->packetHandler->setLogger($this->logger);
        $this->publicKeyHelper = new PublicKeyHelper();
        $this->kexNegotiator = new KexNegotiator($this->clientVersion, '');
        $this->connection->on('data', function (mixed $data): void {
            if (is_string($data)) {
                $this->handleData($data);
            }
        });
        $this->connection->on('error', function (\Throwable $throwable): void {
            $this->error('Socket error', [
                'exception' => $throwable::class,
                'message' => $throwable->getMessage(),
            ]);
            $this->emit('error', [$throwable]);
        });
        $this->connection->on('close', function (): void {
            $this->info('SSH connection closed', [
                'active_channels' => count($this->channels),
            ]);
            foreach ($this->channels as $channel) {
                $channel->receiveClose();
            }
        });
    }

    /**
     * @return PromiseInterface<Client>
     */
    public static function connect(
        string $uri,
        AuthenticationInterface $authentication,
        HostKeyVerifierInterface $hostKeyVerifier,
        ?LoggerInterface $logger = null,
        ?float $timeout = 30.0,
        ?SocketConnectorInterface $socketConnector = null,
    ): PromiseInterface {
        $connectionLogger = $logger ?? new NullLogger();

        /** @var null|ConnectionInterface $socketConnection */
        $socketConnection = null;

        /** @var null|PromiseInterface<ConnectionInterface> $socketPromise */
        $socketPromise = null;
        $loop = Loop::get();
        $timer = null;

        /** @var Deferred<Client> $deferred */
        $deferred = new Deferred(static function () use (&$socketConnection, &$socketPromise, $loop, &$timer): void {
            self::cancelConnectTimeoutTimer($loop, $timer);
            $timer = null;
            $socketConnection?->close();

            if (null !== $socketPromise) {
                $socketPromise->cancel();
            }
        });
        $connectionLogger->info('Starting SSH connection', [
            'uri' => $uri,
            'timeout' => $timeout,
        ]);

        $timer = self::createConnectTimeoutTimer($uri, $timeout, $deferred, $loop, $socketConnection, $socketPromise, $connectionLogger);
        $connector = $socketConnector ?? new SocketConnector(loop: $loop);
        $socketPromise = $connector->connect($uri);
        $socketPromise->then(
            function (ConnectionInterface $connection) use ($authentication, $hostKeyVerifier, $deferred, $logger, $connectionLogger, $uri, $loop, &$timer, &$socketConnection): void {
                $socketConnection = $connection;
                $connectionLogger->info('TCP connection established', [
                    'uri' => $uri,
                    'remote_address' => $connection->getRemoteAddress(),
                ]);
                $clientConnection = new self($connection, $logger);
                $clientConnection->start($authentication, $hostKeyVerifier)->then(
                    static function (Client $client) use ($deferred, $connectionLogger, $uri, $loop, &$timer): void {
                        self::cancelConnectTimeoutTimer($loop, $timer);
                        $timer = null;
                        $connectionLogger->info('SSH connection ready', [
                            'uri' => $uri,
                        ]);
                        $deferred->resolve($client);
                    },
                    static function (\Throwable $throwable) use ($deferred, $connectionLogger, $uri, $loop, &$timer): void {
                        self::cancelConnectTimeoutTimer($loop, $timer);
                        $timer = null;
                        $connectionLogger->error('SSH connection failed during handshake', [
                            'uri' => $uri,
                            'exception' => $throwable::class,
                            'message' => $throwable->getMessage(),
                        ]);
                        $deferred->reject($throwable);
                    }
                );
            },
            static function (\Throwable $throwable) use ($deferred, $connectionLogger, $uri, $loop, &$timer): void {
                self::cancelConnectTimeoutTimer($loop, $timer);
                $timer = null;
                $connectionLogger->error('TCP connection failed', [
                    'uri' => $uri,
                    'exception' => $throwable::class,
                    'message' => $throwable->getMessage(),
                ]);
                $deferred->reject($throwable);
            }
        );

        return $deferred->promise();
    }

    /**
     * @return PromiseInterface<Client>
     */
    public function start(AuthenticationInterface $authentication, HostKeyVerifierInterface $hostKeyVerifier): PromiseInterface
    {
        $this->authentication = $authentication;
        $this->hostKeyVerifier = $hostKeyVerifier;

        /** @var Deferred<Client> $handshakeDeferred */
        $handshakeDeferred = new Deferred();
        $this->handshakeDeferred = $handshakeDeferred;
        $this->info('Starting SSH handshake', [
            'remote_address' => $this->connection->getRemoteAddress(),
        ]);
        $this->connection->write($this->clientVersion . "\r\n");

        return $handshakeDeferred->promise();
    }

    public function writeChannelData(ChannelInterface $channel, string $data): int
    {
        if ('' === $data) {
            return 0;
        }

        $offset = 0;
        while ($offset < strlen($data) && $channel->getRemoteWindow() > 0) {
            $chunkLength = min(strlen($data) - $offset, $channel->getRemoteWindow(), max(1, $channel->getMaxPacketSize() - 1024));
            $chunk = substr($data, $offset, $chunkLength);
            $this->writePacked(MessageType::CHANNEL_DATA, [$channel->getRemoteChannel(), $chunk]);
            $channel->consumeRemoteWindow(strlen($chunk));
            $offset += strlen($chunk);
        }

        return $offset;
    }

    public function sendChannelEof(ChannelInterface $channel): void
    {
        $this->writePacked(MessageType::CHANNEL_EOF, $channel->getRemoteChannel());
    }

    public function closeChannel(ChannelInterface $channel): void
    {
        $this->writePacked(MessageType::CHANNEL_CLOSE, $channel->getRemoteChannel());
    }

    public function adjustChannelWindow(ChannelInterface $channel, int $bytesToAdd): void
    {
        if ($bytesToAdd <= 0) {
            return;
        }

        $this->writePacked(MessageType::CHANNEL_WINDOW_ADJUST, [$channel->getRemoteChannel(), $bytesToAdd]);
    }

    public function close(): void
    {
        $this->info('Closing SSH connection');
        $this->connection->close();
    }

    /**
     * @return PromiseInterface<SessionChannel>
     */
    public function openSession(): PromiseInterface
    {
        $localChannel = $this->nextChannelId++;
        $initialWindow = 1024 * 1024;
        $maxPacketSize = 32768;

        /** @var Deferred<SessionChannel> $deferred */
        $deferred = new Deferred();
        $this->pendingChannelOpens[$localChannel] = new Deferred(function () use ($localChannel): void {
            unset($this->pendingChannelOpens[$localChannel], $this->pendingChannelTypes[$localChannel]);
        });
        $this->pendingChannelTypes[$localChannel] = 'session';

        $this->pendingChannelOpens[$localChannel]->promise()->then(function (mixed $channel) use ($deferred): void {
            if (!$channel instanceof SessionChannel) {
                $deferred->reject(new ProtocolException('Expected session channel open confirmation'));

                return;
            }

            $deferred->resolve($channel);
        }, function (\Throwable $throwable) use ($deferred): void {
            $deferred->reject($throwable);
        });

        $this->debug('Opening SSH session channel', [
            'local_channel' => $localChannel,
        ]);
        $this->writePacked(MessageType::CHANNEL_OPEN, ['session', $localChannel, $initialWindow, $maxPacketSize]);

        return $deferred->promise();
    }

    /**
     * @return PromiseInterface<DirectTcpipChannel>
     */
    public function openDirectTcpip(string $srcIp, int $srcPort, string $dstIp, int $dstPort): PromiseInterface
    {
        self::assertPort($srcPort, 'Source');
        self::assertPort($dstPort, 'Destination');

        if ('' === $srcIp) {
            throw new \InvalidArgumentException('Source IP must not be empty');
        }

        if ('' === $dstIp) {
            throw new \InvalidArgumentException('Destination IP must not be empty');
        }

        $localChannel = $this->nextChannelId++;
        $initialWindow = 1024 * 1024;
        $maxPacketSize = 32768;

        /** @var Deferred<DirectTcpipChannel> $deferred */
        $deferred = new Deferred();
        $this->pendingChannelOpens[$localChannel] = new Deferred(function () use ($localChannel): void {
            unset($this->pendingChannelOpens[$localChannel], $this->pendingChannelTypes[$localChannel]);
        });
        $this->pendingChannelTypes[$localChannel] = 'direct-tcpip';

        $this->pendingChannelOpens[$localChannel]->promise()->then(function (mixed $channel) use ($deferred): void {
            if (!$channel instanceof DirectTcpipChannel) {
                $deferred->reject(new ProtocolException('Expected direct-tcpip channel open confirmation'));

                return;
            }

            $deferred->resolve($channel);
        }, function (\Throwable $throwable) use ($deferred): void {
            $deferred->reject($throwable);
        });

        $this->debug('Opening SSH direct-tcpip channel', [
            'local_channel' => $localChannel,
            'source_ip' => $srcIp,
            'source_port' => $srcPort,
            'destination_ip' => $dstIp,
            'destination_port' => $dstPort,
        ]);
        $this->writePacked(MessageType::CHANNEL_OPEN, ['direct-tcpip', $localChannel, $initialWindow, $maxPacketSize, $dstIp, $dstPort, $srcIp, $srcPort]);

        return $deferred->promise();
    }

    /**
     * @return PromiseInterface<int>
     */
    public function forwardIn(string $bindAddress, int $bindPort): PromiseInterface
    {
        self::assertPort($bindPort, 'Bind');

        /** @var Deferred<int> $deferred */
        $deferred = new Deferred();
        $this->pendingGlobalRequests[] = [
            'type' => 'tcpip-forward',
            'bindPort' => $bindPort,
            'deferred' => $deferred,
        ];

        $this->debug('Requesting SSH remote TCP forwarding', [
            'bind_address' => $bindAddress,
            'bind_port' => $bindPort,
        ]);
        $this->writePacked(MessageType::GLOBAL_REQUEST, ['tcpip-forward', true, $bindAddress, $bindPort]);

        return $deferred->promise();
    }

    /**
     * @param list<mixed> $values
     *
     * @return PromiseInterface<bool>
     */
    public function sendChannelRequest(SessionChannel $channel, string $request, bool $wantReply, array $values = []): PromiseInterface
    {
        /** @var Deferred<bool> $deferred */
        $deferred = new Deferred();
        if ($wantReply) {
            $this->channelReplies[$channel->getLocalChannel()] = $deferred;
        } else {
            $deferred->resolve(true);
        }

        $this->debug('Sending SSH channel request', [
            'local_channel' => $channel->getLocalChannel(),
            'remote_channel' => $channel->getRemoteChannel(),
            'request' => $request,
            'want_reply' => $wantReply,
        ]);

        $this->writePacked(MessageType::CHANNEL_REQUEST, [...[
            $channel->getRemoteChannel(),
            $request,
            $wantReply,
        ], ...$values]);

        return $deferred->promise();
    }

    /**
     * @param Deferred<Client>                           $deferred
     * @param null|PromiseInterface<ConnectionInterface> $socketPromise
     */
    private static function createConnectTimeoutTimer(
        string $uri,
        ?float $timeout,
        Deferred $deferred,
        LoopInterface $loop,
        ?ConnectionInterface &$socketConnection,
        ?PromiseInterface &$socketPromise,
        LoggerInterface $logger,
    ): ?TimerInterface {
        if (null === $timeout) {
            return null;
        }

        return $loop->addTimer($timeout, static function () use ($uri, $timeout, $deferred, &$socketConnection, &$socketPromise, $logger): void {
            $socketConnection?->close();

            if (null !== $socketPromise) {
                $socketPromise->cancel();
            }

            $logger->warning('SSH connection timed out', [
                'uri' => $uri,
                'timeout' => $timeout,
            ]);

            $deferred->reject(new \RuntimeException(sprintf('SSH connection to %s timed out after %s seconds', $uri, self::formatTimeout($timeout))));
        });
    }

    private static function cancelConnectTimeoutTimer(LoopInterface $loop, ?TimerInterface $timer): void
    {
        if (!$timer instanceof TimerInterface) {
            return;
        }

        $loop->cancelTimer($timer);
    }

    private static function formatTimeout(float $timeout): string
    {
        $formatted = number_format($timeout, 3, '.', '');

        return rtrim(rtrim($formatted, '0'), '.');
    }

    private function handleData(string $data): void
    {
        if (null === $this->serverVersion) {
            $this->handleVersionData($data);

            return;
        }

        $this->inputBuffer .= $data;
        while (true) {
            [$packet, $bytesUsed] = $this->packetHandler->fromData($this->inputBuffer);
            if (!$packet instanceof Packet || 0 === $bytesUsed) {
                break;
            }

            $this->inputBuffer = substr($this->inputBuffer, $bytesUsed);
            $this->handlePacket($packet);
        }
    }

    private function handleVersionData(string $data): void
    {
        $this->inputBuffer .= $data;
        while (false !== ($pos = strpos($this->inputBuffer, "\n"))) {
            $line = rtrim(substr($this->inputBuffer, 0, $pos), "\r");
            $this->inputBuffer = substr($this->inputBuffer, $pos + 1);
            if (str_starts_with($line, 'SSH-')) {
                $this->serverVersion = $line;
                $this->kexNegotiator->serverVersion = $line;
                $this->debug('Received SSH server version', [
                    'server_version' => $line,
                ]);
                $this->writeConnection($this->kexNegotiator->createClientKexInit());

                return;
            }
        }
    }

    private function handlePacket(Packet $packet): void
    {
        if (MessageType::DISCONNECT === $packet->type) {
            $this->warning('Remote server disconnected');
        }

        match ($packet->type) {
            MessageType::KEXINIT => $this->handleKexInit($packet),
            MessageType::KEXDH_REPLY => $this->handleKexReply($packet),
            MessageType::NEWKEYS => $this->handleNewKeys(),
            MessageType::SERVICE_ACCEPT => $this->handleServiceAccept($packet),
            MessageType::USERAUTH_BANNER => null,
            MessageType::USERAUTH_FAILURE => $this->handleUserAuthFailure($packet),
            MessageType::USERAUTH_SUCCESS => $this->handleUserAuthSuccess(),
            MessageType::USERAUTH_PK_OK => $this->handleUserAuthMessage60($packet),
            MessageType::REQUEST_SUCCESS => $this->handleRequestSuccess($packet),
            MessageType::REQUEST_FAILURE => $this->handleRequestFailure(),
            MessageType::CHANNEL_OPEN => $this->handleChannelOpen($packet),
            MessageType::CHANNEL_OPEN_CONFIRMATION => $this->handleChannelOpenConfirmation($packet),
            MessageType::CHANNEL_OPEN_FAILURE => $this->handleChannelOpenFailure($packet),
            MessageType::CHANNEL_SUCCESS => $this->handleChannelReply($packet, true),
            MessageType::CHANNEL_FAILURE => $this->handleChannelReply($packet, false),
            MessageType::CHANNEL_WINDOW_ADJUST => $this->handleChannelWindowAdjust($packet),
            MessageType::CHANNEL_DATA => $this->handleChannelData($packet),
            MessageType::CHANNEL_EXTENDED_DATA => $this->handleChannelExtendedData($packet),
            MessageType::CHANNEL_EOF => $this->handleChannelEof($packet),
            MessageType::CHANNEL_CLOSE => $this->handleChannelClose($packet),
            MessageType::CHANNEL_REQUEST => $this->handleChannelRequest($packet),
            MessageType::DISCONNECT => throw new ProtocolException('Remote disconnected'),
            default => null,
        };
    }

    private function handleKexInit(Packet $packet): void
    {
        $this->debug('Negotiating SSH key exchange');
        $this->kexNegotiator->acceptServerKexInit($packet);
        $this->kexNegotiator->negotiateAlgorithms();
        $this->kex = new Kex($this->kexNegotiator, $packet);
        $this->writeConnection($this->kex->createRequest());
    }

    private function handleKexReply(Packet $packet): void
    {
        $kex = $this->kex ?? throw new ProtocolException('KEX state not initialized');
        $packetCopy = new Packet(chr($packet->typeValue) . $packet->message);
        if (null !== $this->sessionId && null === $kex->getSessionId()) {
            $kex->setSessionId($this->sessionId);
        }

        [$hostKeyBlob, , $signatureBlob] = $packetCopy->extractFormat('%s%s%s');
        if (!is_string($hostKeyBlob) || !is_string($signatureBlob)) {
            throw new ProtocolException('Invalid host key reply payload');
        }

        $kex->setReplyPacket($packet);
        $kex->acceptReply();
        $this->sessionId = $kex->getSessionId();

        $hostKeyAlgorithm = $this->kexNegotiator->getNegotiatedAlgorithm('hostkey') ?? throw new ProtocolException('Missing host key algorithm');
        $hostKeyInfo = new HostKeyInfo($hostKeyAlgorithm, $hostKeyBlob, $this->publicKeyHelper->getOpenSshKey($hostKeyBlob));
        $address = $this->connection->getRemoteAddress() ?? 'unknown';

        $this->debug('Verifying SSH host key', [
            'algorithm' => $hostKeyAlgorithm,
            'remote_address' => $address,
        ]);

        if (!$this->hostKeyVerifier?->verify($hostKeyInfo, $address)) {
            $this->warning('SSH host key rejected by verifier', [
                'algorithm' => $hostKeyAlgorithm,
                'remote_address' => $address,
            ]);

            throw new HostKeyException('Host key rejected by verifier');
        }

        if (!$this->publicKeyHelper->verifyServerHostKey($hostKeyAlgorithm, $hostKeyBlob, $signatureBlob, $kex->getExchangeHash())) {
            $this->warning('SSH host key signature verification failed', [
                'algorithm' => $hostKeyAlgorithm,
                'remote_address' => $address,
            ]);

            throw new HostKeyException('Host key signature verification failed');
        }

        $this->packetHandler->setKex($kex);
        $algorithms = $this->kexNegotiator->getNegotiatedAlgorithms() ?? throw new ProtocolException('Missing negotiated algorithms');
        $this->packetHandler->setEncryptionMethods($algorithms['encryption_ctos'], $algorithms['encryption_stoc']);
        $this->packetHandler->setMacMethods($algorithms['mac_ctos'], $algorithms['mac_stoc']);
        $this->info('SSH key exchange completed', [
            'hostkey' => $hostKeyAlgorithm,
            'encryption_ctos' => $algorithms['encryption_ctos'],
            'encryption_stoc' => $algorithms['encryption_stoc'],
        ]);
        $this->writeConnection(chr(MessageType::NEWKEYS->value));
    }

    private function handleNewKeys(): void
    {
        $this->packetHandler->deriveKeys();
        $this->debug('SSH transport encryption enabled');
        $this->writePacked(MessageType::SERVICE_REQUEST, 'ssh-userauth');
    }

    private function handleServiceAccept(Packet $packet): void
    {
        [$service] = $packet->extractFormat('%s');
        if (!is_string($service)) {
            throw new ProtocolException('Invalid service accept payload');
        }

        if ('ssh-userauth' !== $service) {
            throw new ProtocolException('Unexpected accepted service: ' . $service);
        }

        $this->debug('SSH service accepted', [
            'service' => $service,
        ]);

        $this->sendAuthRequest($this->authentication ?? throw new AuthenticationException('Authentication not configured'));
    }

    private function handleUserAuthFailure(Packet $packet): void
    {
        [$methods] = $packet->extractFormat('%s%b');
        if (!is_string($methods)) {
            throw new AuthenticationException('Invalid auth failure payload');
        }

        $availableMethods = explode(',', $methods);

        $this->warning('SSH authentication failed', [
            'method' => self::describeAuthentication($this->authentication),
            'available_methods' => $availableMethods,
        ]);

        if ($this->authentication instanceof PasswordAuthentication) {
            throw new AuthenticationException('Password authentication failed');
        }

        if ($this->authentication instanceof PublicKeyAuthentication) {
            if (in_array('publickey', $availableMethods, true)) {
                throw new AuthenticationException('Public key authentication failed');
            }

            throw new AuthenticationException('Public key authentication unsupported');
        }

        if ($this->authentication instanceof KeyboardInteractiveAuthentication) {
            if (in_array('keyboard-interactive', $availableMethods, true)) {
                $this->sendKeyboardInteractiveRequest($this->authentication);

                return;
            }

            throw new AuthenticationException('Keyboard-interactive authentication failed');
        }
    }

    private function handleUserAuthSuccess(): void
    {
        $this->info('SSH authentication succeeded', [
            'method' => self::describeAuthentication($this->authentication),
        ]);
        $client = new Client($this);
        $this->handshakeDeferred?->resolve($client);
    }

    private function handlePublicKeyOk(): void
    {
        $authentication = $this->authentication;
        if (!$authentication instanceof PublicKeyAuthentication) {
            throw new AuthenticationException('Unexpected USERAUTH_PK_OK packet');
        }

        $this->debug('SSH public key accepted, sending signed request');

        $this->sendSignedPublicKeyRequest($authentication);
    }

    private function handleUserAuthMessage60(Packet $packet): void
    {
        if ($this->authentication instanceof PublicKeyAuthentication) {
            $this->handlePublicKeyOk();

            return;
        }

        if (!$this->authentication instanceof KeyboardInteractiveAuthentication) {
            throw new AuthenticationException('Unexpected USERAUTH_INFO_REQUEST packet');
        }

        $authentication = $this->authentication;

        [$name, $instruction, $languageTag, $promptCount] = $packet->extractFormat('%s%s%s%u');
        if (!is_string($name) || !is_string($instruction) || !is_string($languageTag) || !is_int($promptCount)) {
            throw new AuthenticationException('Invalid keyboard-interactive challenge');
        }

        $prompts = [];
        for ($i = 0; $i < $promptCount; ++$i) {
            [$prompt, $echo] = $packet->extractFormat('%s%b');
            if (!is_string($prompt) || !is_bool($echo)) {
                throw new AuthenticationException('Invalid keyboard-interactive prompt');
            }

            $prompts[] = new KeyboardInteractivePrompt($prompt, $echo);
        }

        $challenge = new KeyboardInteractiveChallenge($name, $instruction, $languageTag, $prompts);
        $this->debug('Received keyboard-interactive challenge', [
            'prompt_count' => $promptCount,
        ]);
        $responses = $authentication->respond($challenge);
        $values = [count($responses)];
        foreach ($responses as $response) {
            $values[] = $response;
        }

        $this->writePacked(MessageType::USERAUTH_INFO_RESPONSE, $values);
    }

    private function handleChannelOpenConfirmation(Packet $packet): void
    {
        [$localChannel, $remoteChannel, $initialWindow, $maxPacketSize] = $packet->extractFormat('%u%u%u%u');
        if (!is_int($localChannel) || !is_int($remoteChannel) || !is_int($initialWindow) || !is_int($maxPacketSize)) {
            throw new ProtocolException('Invalid channel open confirmation');
        }

        $channelType = $this->pendingChannelTypes[$localChannel] ?? null;
        $channel = match ($channelType) {
            'session' => new SessionChannel($this, $localChannel, $remoteChannel, $initialWindow, $maxPacketSize),
            'direct-tcpip' => new DirectTcpipChannel($this, $localChannel, $remoteChannel, $initialWindow, $maxPacketSize),
            default => throw new ProtocolException('Unexpected channel open confirmation'),
        };

        $this->info('SSH channel opened', [
            'type' => $channelType,
            'local_channel' => $localChannel,
            'remote_channel' => $remoteChannel,
        ]);

        $this->channels[$localChannel] = $channel;
        $deferred = $this->pendingChannelOpens[$localChannel] ?? null;
        unset($this->pendingChannelOpens[$localChannel], $this->pendingChannelTypes[$localChannel]);
        $deferred?->resolve($channel);
    }

    private function handleChannelOpen(Packet $packet): void
    {
        [$channelType, $remoteChannel, $initialWindow, $maxPacketSize] = $packet->extractFormat('%s%u%u%u');
        if (!is_string($channelType) || !is_int($remoteChannel) || !is_int($initialWindow) || !is_int($maxPacketSize)) {
            throw new ProtocolException('Invalid channel open payload');
        }

        if ('forwarded-tcpip' !== $channelType) {
            $this->warning('Rejecting unsupported SSH channel open request', [
                'type' => $channelType,
                'remote_channel' => $remoteChannel,
            ]);
            $this->writePacked(MessageType::CHANNEL_OPEN_FAILURE, [$remoteChannel, 3, 'Unsupported channel type: ' . $channelType, '']);

            return;
        }

        [$destIp, $destPort, $srcIp, $srcPort] = $packet->extractFormat('%s%u%s%u');
        if (!is_string($destIp) || !is_int($destPort) || !is_string($srcIp) || !is_int($srcPort)) {
            throw new ProtocolException('Invalid forwarded-tcpip channel open payload');
        }

        $handled = false;
        $accept = function () use (&$handled, $remoteChannel, $initialWindow, $maxPacketSize): ForwardedTcpipChannel {
            if ($handled) {
                throw new \RuntimeException('Forwarded TCP connection has already been handled');
            }

            $handled = true;
            $localChannel = $this->nextChannelId++;
            $localInitialWindow = 1024 * 1024;
            $localMaxPacketSize = 32768;
            $channel = new ForwardedTcpipChannel($this, $localChannel, $remoteChannel, $localInitialWindow, $maxPacketSize, $initialWindow);

            $this->channels[$localChannel] = $channel;
            $this->info('Accepted SSH forwarded-tcpip channel', [
                'local_channel' => $localChannel,
                'remote_channel' => $remoteChannel,
            ]);
            $this->writePacked(MessageType::CHANNEL_OPEN_CONFIRMATION, [$remoteChannel, $localChannel, $localInitialWindow, $localMaxPacketSize]);

            return $channel;
        };
        $reject = function () use (&$handled, $remoteChannel): void {
            if ($handled) {
                return;
            }

            $handled = true;
            $this->info('Rejected SSH forwarded-tcpip channel', [
                'remote_channel' => $remoteChannel,
            ]);
            $this->writePacked(MessageType::CHANNEL_OPEN_FAILURE, [$remoteChannel, 1, 'Forwarded TCP connection rejected', '']);
        };

        $this->emit('tcp connection', [[
            'destIP' => $destIp,
            'destPort' => $destPort,
            'srcIP' => $srcIp,
            'srcPort' => $srcPort,
        ], $accept, $reject]);

        if (!$handled) {
            $reject();
        }
    }

    private function handleChannelOpenFailure(Packet $packet): void
    {
        [$localChannel, $reasonCode, $description] = $packet->extractFormat('%u%u%s%s');
        if (!is_int($localChannel) || !is_int($reasonCode) || !is_string($description)) {
            throw new ProtocolException('Invalid channel open failure');
        }

        $deferred = $this->pendingChannelOpens[$localChannel] ?? null;
        unset($this->pendingChannelOpens[$localChannel], $this->pendingChannelTypes[$localChannel]);

        $this->warning('SSH channel open failed', [
            'local_channel' => $localChannel,
            'reason_code' => $reasonCode,
            'description' => $description,
        ]);

        if (!$deferred instanceof Deferred) {
            throw new ChannelOpenException('SSH channel open failed: ' . $description, $reasonCode, $description);
        }

        $deferred->reject(new ChannelOpenException('SSH channel open failed: ' . $description, $reasonCode, $description));
    }

    private function handleChannelReply(Packet $packet, bool $success): void
    {
        [$recipientChannel] = $packet->extractFormat('%u');
        if (!is_int($recipientChannel)) {
            throw new ProtocolException('Invalid channel reply payload');
        }

        $deferred = $this->channelReplies[$recipientChannel] ?? null;
        unset($this->channelReplies[$recipientChannel]);
        $this->debug('Received SSH channel request reply', [
            'local_channel' => $recipientChannel,
            'success' => $success,
        ]);
        $deferred?->resolve($success);
    }

    private function handleRequestSuccess(Packet $packet): void
    {
        $request = array_shift($this->pendingGlobalRequests);
        if (!is_array($request)) {
            throw new ProtocolException('Unexpected global request success reply');
        }

        $port = $request['bindPort'];
        if ('tcpip-forward' === $request['type'] && 0 === $port) {
            [$allocatedPort] = $packet->extractFormat('%u');
            if (!is_int($allocatedPort)) {
                throw new ProtocolException('Invalid tcpip-forward success payload');
            }

            $port = $allocatedPort;
        }

        $this->debug('SSH global request succeeded', [
            'type' => $request['type'],
            'port' => $port,
        ]);
        $request['deferred']->resolve($port);
    }

    private function handleRequestFailure(): void
    {
        $request = array_shift($this->pendingGlobalRequests);
        if (!is_array($request)) {
            throw new ProtocolException('Unexpected global request failure reply');
        }

        $this->warning('SSH global request failed', [
            'type' => $request['type'],
            'bind_port' => $request['bindPort'],
        ]);
        $request['deferred']->reject(new \RuntimeException('SSH global request failed: ' . $request['type']));
    }

    private function handleChannelWindowAdjust(Packet $packet): void
    {
        [$recipientChannel, $bytesToAdd] = $packet->extractFormat('%u%u');
        if (!is_int($recipientChannel) || !is_int($bytesToAdd)) {
            throw new ProtocolException('Invalid window adjust payload');
        }

        if (isset($this->channels[$recipientChannel])) {
            $this->channels[$recipientChannel]->increaseRemoteWindow($bytesToAdd);
        }
    }

    private function handleChannelData(Packet $packet): void
    {
        [$recipientChannel, $data] = $packet->extractFormat('%u%s');
        if (!is_int($recipientChannel) || !is_string($data)) {
            throw new ProtocolException('Invalid channel data payload');
        }

        if (isset($this->channels[$recipientChannel])) {
            $this->channels[$recipientChannel]->writeData($data);
        }
    }

    private function handleChannelExtendedData(Packet $packet): void
    {
        [$recipientChannel, $dataTypeCode, $data] = $packet->extractFormat('%u%u%s');
        if (!is_int($recipientChannel) || !is_int($dataTypeCode) || !is_string($data)) {
            throw new ProtocolException('Invalid extended data payload');
        }

        if (isset($this->channels[$recipientChannel])) {
            $this->channels[$recipientChannel]->writeExtendedData($dataTypeCode, $data);
        }
    }

    private function handleChannelEof(Packet $packet): void
    {
        [$recipientChannel] = $packet->extractFormat('%u');
        if (!is_int($recipientChannel)) {
            throw new ProtocolException('Invalid EOF payload');
        }

        if (isset($this->channels[$recipientChannel])) {
            $this->channels[$recipientChannel]->receiveEof();
        }
    }

    private function handleChannelClose(Packet $packet): void
    {
        [$recipientChannel] = $packet->extractFormat('%u');
        if (!is_int($recipientChannel)) {
            throw new ProtocolException('Invalid channel close payload');
        }

        $channel = $this->channels[$recipientChannel] ?? null;
        if (!$channel instanceof ChannelInterface) {
            return;
        }

        $this->info('SSH channel closed', [
            'local_channel' => $recipientChannel,
        ]);
        $channel->receiveClose();
        unset($this->channels[$recipientChannel]);
    }

    private static function assertPort(int $port, string $label): void
    {
        if ($port < 0 || $port > 65535) {
            throw new \InvalidArgumentException(sprintf('%s port must be between 0 and 65535', $label));
        }
    }

    private function handleChannelRequest(Packet $packet): void
    {
        [$recipientChannel, $requestType] = $packet->extractFormat('%u%s%b');
        if (!is_int($recipientChannel) || !is_string($requestType)) {
            throw new ProtocolException('Invalid channel request payload');
        }

        $channel = $this->channels[$recipientChannel] ?? null;
        if (!$channel instanceof SessionChannel) {
            return;
        }

        switch ($requestType) {
            case 'exit-status':
                [$exitStatus] = $packet->extractFormat('%u');
                if (!is_int($exitStatus)) {
                    throw new ProtocolException('Invalid exit-status payload');
                }

                $channel->setExitCode($exitStatus);

                break;

            case 'exit-signal':
                $channel->setExitCode(255);

                break;
        }
    }

    private function sendAuthRequest(AuthenticationInterface $authentication): void
    {
        if ($authentication instanceof PasswordAuthentication) {
            $this->debug('Sending SSH authentication request', [
                'method' => 'password',
                'username' => $authentication->getUsername(),
            ]);
            $this->writePacked(MessageType::USERAUTH_REQUEST, [
                $authentication->getUsername(),
                'ssh-connection',
                'password',
                false,
                $authentication->password,
            ]);

            return;
        }

        if ($authentication instanceof PublicKeyAuthentication) {
            $this->debug('Sending SSH authentication request', [
                'method' => 'publickey',
                'username' => $authentication->getUsername(),
            ]);
            $this->sendUnsignedPublicKeyRequest($authentication);

            return;
        }

        if ($authentication instanceof KeyboardInteractiveAuthentication) {
            $this->debug('Sending SSH authentication request', [
                'method' => 'keyboard-interactive',
                'username' => $authentication->getUsername(),
            ]);
            $this->sendKeyboardInteractiveRequest($authentication);

            return;
        }

        throw new AuthenticationException('Unsupported authentication type');
    }

    private function sendUnsignedPublicKeyRequest(PublicKeyAuthentication $authentication): void
    {
        $exported = $this->publicKeyHelper->exportPublicKey($authentication->getPrivateKey());
        $this->writePacked(MessageType::USERAUTH_REQUEST, [
            $authentication->getUsername(),
            'ssh-connection',
            'publickey',
            false,
            $exported['algorithm'],
            $exported['blob'],
        ]);
    }

    private function sendSignedPublicKeyRequest(PublicKeyAuthentication $authentication): void
    {
        $exported = $this->publicKeyHelper->exportPublicKey($authentication->getPrivateKey());
        $signatureAlgorithm = 'ssh-ed25519' === $exported['algorithm'] ? 'ssh-ed25519' : 'rsa-sha2-256';
        $signedData = $this->publicKeyHelper->constructSignedUserAuthRequest(
            $this->sessionId ?? throw new AuthenticationException('Session ID unavailable'),
            $authentication->getUsername(),
            'ssh-connection',
            $signatureAlgorithm,
            $exported['blob'],
        );
        $signature = $this->publicKeyHelper->createSignature($authentication->getPrivateKey(), $signedData);
        $this->writePacked(MessageType::USERAUTH_REQUEST, [
            $authentication->getUsername(),
            'ssh-connection',
            'publickey',
            true,
            $signature['algorithm'],
            $exported['blob'],
            $signature['signature'],
        ]);
    }

    private function sendKeyboardInteractiveRequest(KeyboardInteractiveAuthentication $authentication): void
    {
        $this->writePacked(MessageType::USERAUTH_REQUEST, [
            $authentication->getUsername(),
            'ssh-connection',
            'keyboard-interactive',
            '',
            '',
        ]);
    }

    private function writeConnection(string $payload): void
    {
        $packet = $this->packetHandler->constructPacket($payload);
        if (!is_string($packet)) {
            throw new ProtocolException('Failed to construct SSH packet');
        }

        $this->connection->write($packet);
    }

    private function writePacked(MessageType $type, mixed $value = null): void
    {
        $this->writeConnection($this->packetHandler->packValues($type, is_array($value) ? array_values($value) : [$value]));
    }

    private static function describeAuthentication(?AuthenticationInterface $authentication): ?string
    {
        return match (true) {
            $authentication instanceof PasswordAuthentication => 'password',
            $authentication instanceof PublicKeyAuthentication => 'publickey',
            $authentication instanceof KeyboardInteractiveAuthentication => 'keyboard-interactive',
            null === $authentication => null,
            default => $authentication::class,
        };
    }
}
