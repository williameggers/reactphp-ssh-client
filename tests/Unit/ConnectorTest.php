<?php declare(strict_types=1);

use WilliamEggers\React\SSHClient\Connector;

test('connector rejects non-positive timeout values', function (): void {
    expect(fn (): Connector => new Connector(timeout: 0.0))
        ->toThrow(InvalidArgumentException::class, 'Connector timeout must be greater than 0 seconds or null to disable it')
    ;

    expect(fn (): Connector => new Connector(timeout: -1.0))
        ->toThrow(InvalidArgumentException::class, 'Connector timeout must be greater than 0 seconds or null to disable it')
    ;
});

test('connector accepts null timeout to disable it', function (): void {
    expect(new Connector(timeout: null))->toBeInstanceOf(Connector::class);
});
