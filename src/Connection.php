<?php declare(strict_types=1);

namespace WilliamEggers\React\SSHClient;

use Evenement\EventEmitterTrait;
use Psr\Log\LoggerInterface;
use React\EventLoop\Loop;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;
use React\Socket\ConnectionInterface;
use React\Socket\Connector as SocketConnector;
use WilliamEggers\React\SSHClient\Authentication\AuthenticationInterface;
use WilliamEggers\React\SSHClient\Authentication\KeyboardInteractiveAuthentication;
use WilliamEggers\React\SSHClient\Authentication\PasswordAuthentication;
use WilliamEggers\React\SSHClient\Authentication\PublicKeyAuthentication;
use WilliamEggers\React\SSHClient\Channel\SessionChannel;
use WilliamEggers\React\SSHClient\Concerns\WritesLogs;
use WilliamEggers\React\SSHClient\Enums\MessageType;
use WilliamEggers\React\SSHClient\Exception\AuthenticationException;
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

    /** @var array<int, SessionChannel> */
    private array $channels = [];

    /** @var array<int, Deferred<bool>> */
    private array $channelReplies = [];

    /** @var Deferred<Client>|null */
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
            $this->emit('error', [$throwable]);
        });
        $this->connection->on('close', function (): void {
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
    ): PromiseInterface {
        /** @var Deferred<Client> $deferred */
        $deferred = new Deferred();
        $connector = new SocketConnector(loop: Loop::get());
        $connector->connect($uri)->then(
            function (ConnectionInterface $connection) use ($authentication, $hostKeyVerifier, $deferred, $logger): void {
                $clientConnection = new self($connection, $logger);
                $clientConnection->start($authentication, $hostKeyVerifier)->then(
                    static function (Client $client) use ($deferred): void {
                        $deferred->resolve($client);
                    },
                    static function (\Throwable $throwable) use ($deferred): void {
                        $deferred->reject($throwable);
                    }
                );
            },
            static function (\Throwable $throwable) use ($deferred): void {
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
        $this->connection->write($this->clientVersion . "\r\n");

        return $handshakeDeferred->promise();
    }

    public function writeChannelData(SessionChannel $channel, string $data): int
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

    public function sendChannelEof(SessionChannel $channel): void
    {
        $this->writePacked(MessageType::CHANNEL_EOF, $channel->getRemoteChannel());
    }

    public function closeChannel(SessionChannel $channel): void
    {
        $this->writePacked(MessageType::CHANNEL_CLOSE, $channel->getRemoteChannel());
    }

    public function close(): void
    {
        $this->connection->close();
    }

    /**
     * @return PromiseInterface<SessionChannel>
     */
    public function openSession(): PromiseInterface
    {
        /** @var Deferred<SessionChannel> $deferred */
        $deferred = new Deferred();
        $localChannel = $this->nextChannelId++;
        $initialWindow = 1024 * 1024;
        $maxPacketSize = 32768;

        $this->emit('session.opening', [$localChannel, $deferred]);
        $this->once('channel.open.' . $localChannel, function (SessionChannel $channel) use ($deferred): void {
            $deferred->resolve($channel);
        });

        $this->writePacked(MessageType::CHANNEL_OPEN, ['session', $localChannel, $initialWindow, $maxPacketSize]);

        return $deferred->promise();
    }

    /**
     * @param list<mixed> $values
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

        $this->writePacked(MessageType::CHANNEL_REQUEST, [...[
            $channel->getRemoteChannel(),
            $request,
            $wantReply,
        ], ...$values]);

        return $deferred->promise();
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
                $this->writeConnection($this->kexNegotiator->createClientKexInit());

                return;
            }
        }
    }

    private function handlePacket(Packet $packet): void
    {
        match ($packet->type) {
            MessageType::KEXINIT => $this->handleKexInit($packet),
            MessageType::KEXDH_REPLY => $this->handleKexReply($packet),
            MessageType::NEWKEYS => $this->handleNewKeys(),
            MessageType::SERVICE_ACCEPT => $this->handleServiceAccept($packet),
            MessageType::USERAUTH_BANNER => null,
            MessageType::USERAUTH_FAILURE => $this->handleUserAuthFailure($packet),
            MessageType::USERAUTH_SUCCESS => $this->handleUserAuthSuccess(),
            MessageType::USERAUTH_PK_OK => $this->handleUserAuthMessage60($packet),
            MessageType::CHANNEL_OPEN_CONFIRMATION => $this->handleChannelOpenConfirmation($packet),
            MessageType::CHANNEL_OPEN_FAILURE => throw new ProtocolException('Channel open failure'),
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

        if (!$this->hostKeyVerifier?->verify($hostKeyInfo, $address)) {
            throw new HostKeyException('Host key rejected by verifier');
        }

        if (!$this->publicKeyHelper->verifyServerHostKey($hostKeyAlgorithm, $hostKeyBlob, $signatureBlob, $kex->getExchangeHash())) {
            throw new HostKeyException('Host key signature verification failed');
        }

        $this->packetHandler->setKex($kex);
        $algorithms = $this->kexNegotiator->getNegotiatedAlgorithms() ?? throw new ProtocolException('Missing negotiated algorithms');
        $this->packetHandler->setEncryptionMethods($algorithms['encryption_ctos'], $algorithms['encryption_stoc']);
        $this->packetHandler->setMacMethods($algorithms['mac_ctos'], $algorithms['mac_stoc']);
        $this->writeConnection(chr(MessageType::NEWKEYS->value));
    }

    private function handleNewKeys(): void
    {
        $this->packetHandler->deriveKeys();
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

        $this->sendAuthRequest($this->authentication ?? throw new AuthenticationException('Authentication not configured'));
    }

    private function handleUserAuthFailure(Packet $packet): void
    {
        [$methods] = $packet->extractFormat('%s%b');
        if (!is_string($methods)) {
            throw new AuthenticationException('Invalid auth failure payload');
        }

        $availableMethods = explode(',', $methods);

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
        $client = new Client($this);
        $this->handshakeDeferred?->resolve($client);
    }

    private function handlePublicKeyOk(): void
    {
        $authentication = $this->authentication;
        if (!$authentication instanceof PublicKeyAuthentication) {
            throw new AuthenticationException('Unexpected USERAUTH_PK_OK packet');
        }

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
        $responses = $this->authentication->respond($challenge);
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

        $channel = new SessionChannel($this, $localChannel, $remoteChannel, $initialWindow, $maxPacketSize);
        $this->channels[$localChannel] = $channel;
        $this->emit('channel.open.' . $localChannel, [$channel]);
    }

    private function handleChannelReply(Packet $packet, bool $success): void
    {
        [$recipientChannel] = $packet->extractFormat('%u');
        if (!is_int($recipientChannel)) {
            throw new ProtocolException('Invalid channel reply payload');
        }

        $deferred = $this->channelReplies[$recipientChannel] ?? null;
        unset($this->channelReplies[$recipientChannel]);
        $deferred?->resolve($success);
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
            $this->channels[$recipientChannel]->writeStdout($data);
        }
    }

    private function handleChannelExtendedData(Packet $packet): void
    {
        [$recipientChannel, $dataTypeCode, $data] = $packet->extractFormat('%u%u%s');
        if (!is_int($recipientChannel) || !is_int($dataTypeCode) || !is_string($data)) {
            throw new ProtocolException('Invalid extended data payload');
        }

        if (1 === $dataTypeCode && isset($this->channels[$recipientChannel])) {
            $this->channels[$recipientChannel]->writeStderr($data);
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
        if (null === $channel) {
            return;
        }

        $channel->receiveClose();
        unset($this->channels[$recipientChannel]);
    }

    private function handleChannelRequest(Packet $packet): void
    {
        [$recipientChannel, $requestType] = $packet->extractFormat('%u%s%b');
        if (!is_int($recipientChannel) || !is_string($requestType)) {
            throw new ProtocolException('Invalid channel request payload');
        }

        $channel = $this->channels[$recipientChannel] ?? null;
        if (null === $channel) {
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
            $this->sendUnsignedPublicKeyRequest($authentication);

            return;
        }

        if ($authentication instanceof KeyboardInteractiveAuthentication) {
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
}
