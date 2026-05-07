<?php declare(strict_types=1);

use React\EventLoop\Loop;

afterEach(function (): void {
    Loop::stop();
});
