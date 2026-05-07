<?php declare(strict_types=1);

namespace WilliamEggers\React\SSHClient\Values;

final readonly class WinSize
{
    public function __construct(
        public int $rows,
        public int $cols,
        public int $widthPixels = 0,
        public int $heightPixels = 0,
    ) {}
}
