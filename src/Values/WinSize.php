<?php declare(strict_types=1);

namespace WilliamEggers\React\SSHClient\Values;

final readonly class WinSize
{
    /**
     * Describe a terminal window size for PTY allocation and resize requests.
     *
     * @param int $rows         terminal height in character rows
     * @param int $cols         terminal width in character columns
     * @param int $widthPixels  terminal width in pixels, or 0 when unknown
     * @param int $heightPixels terminal height in pixels, or 0 when unknown
     */
    public function __construct(
        public int $rows,
        public int $cols,
        public int $widthPixels = 0,
        public int $heightPixels = 0,
    ) {}
}
