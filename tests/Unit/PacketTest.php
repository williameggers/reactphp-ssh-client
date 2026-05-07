<?php declare(strict_types=1);

use WilliamEggers\React\SSHClient\Packet;

test('extracts string and integers correctly', function (): void {
    $payload = chr(94)
        . pack('N', 1)
        . pack('N', 3) . 'abc';

    $packet = new Packet($payload);
    [$channelId, $data] = $packet->extractFormat('%u%s');

    expect($channelId)->toBe(1)
        ->and($data)->toBe('abc')
    ;
});
