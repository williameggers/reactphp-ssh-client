<?php declare(strict_types=1);

namespace WilliamEggers\React\SSHClient\Channel;

use React\Promise\PromiseInterface;
use React\Stream\ReadableStreamInterface;
use React\Stream\ThroughStream;
use WilliamEggers\React\SSHClient\Connection;
use WilliamEggers\React\SSHClient\Values\WinSize;

final class SessionChannel extends AbstractChannel
{
    private ThroughStream $stderr;
    private int $exitCode = -1;

    public function __construct(
        Connection $connection,
        int $localChannel,
        int $remoteChannel,
        int $initialWindow,
        int $maxPacketSize,
    ) {
        parent::__construct($connection, $localChannel, $remoteChannel, $initialWindow, $maxPacketSize);
        $this->stderr = new ThroughStream();
    }

    public function getExitCode(): int
    {
        return $this->exitCode;
    }

    public function setExitCode(int $exitCode): void
    {
        $this->exitCode = $exitCode;
    }

    public function stdout(): ReadableStreamInterface
    {
        return $this;
    }

    public function stderr(): ReadableStreamInterface
    {
        return $this->stderr;
    }

    public function writeExtendedData(int $dataTypeCode, string $data): void
    {
        if (1 !== $dataTypeCode || '' === $data) {
            return;
        }

        $this->consumeLocalWindow(strlen($data));
        $this->stderr->write($data);
        $this->getConnection()->adjustChannelWindow($this, strlen($data));
    }

    public function receiveEof(): void
    {
        parent::receiveEof();
        $this->stderr->end();
    }

    public function receiveClose(): void
    {
        $this->stderr->close();
        $this->emit('exit', [$this->exitCode]);
        parent::receiveClose();
    }

    /**
     * @return PromiseInterface<bool>
     */
    public function requestEnvironmentVariable(string $name, string $value): PromiseInterface
    {
        return $this->getConnection()->sendChannelRequest($this, 'env', true, [$name, $value]);
    }

    /**
     * @return PromiseInterface<bool>
     */
    public function requestPty(string $term, WinSize $size): PromiseInterface
    {
        return $this->getConnection()->sendChannelRequest($this, 'pty-req', true, [
            $term,
            $size->cols,
            $size->rows,
            $size->widthPixels,
            $size->heightPixels,
            '',
        ]);
    }

    /**
     * @return PromiseInterface<bool>
     */
    public function requestShell(): PromiseInterface
    {
        return $this->getConnection()->sendChannelRequest($this, 'shell', true);
    }

    /**
     * @return PromiseInterface<bool>
     */
    public function requestExec(string $command): PromiseInterface
    {
        return $this->getConnection()->sendChannelRequest($this, 'exec', true, [$command]);
    }

    public function resize(WinSize $size): void
    {
        $this->getConnection()->sendChannelRequest($this, 'window-change', false, [
            $size->cols,
            $size->rows,
            $size->widthPixels,
            $size->heightPixels,
        ]);
    }
}
