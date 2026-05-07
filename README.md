# SSH client for ReactPHP

[![Latest Stable Version](http://poser.pugx.org/williameggers/reactphp-ssh-client/v)](https://packagist.org/packages/williameggers/reactphp-ssh-client)
[![License](http://poser.pugx.org/williameggers/reactphp-ssh-client/license)](https://packagist.org/packages/williameggers/reactphp-ssh-client)

This project is an event-driven, standalone SSH client implementation for [ReactPHP](https://reactphp.org/) developed by William Eggers. It provides a small asynchronous API for connecting to SSH servers, authenticating, opening shell sessions, and executing remote commands.

## Overview

* Implements core SSH client functionality, including transport negotiation, host key validation, authentication, and session channel management

* Built on a fully asynchronous, non-blocking architecture using ReactPHP for high concurrency and integration into event-driven applications

* Supports password, public key, and keyboard-interactive authentication flows

* Provides simple APIs for running remote commands and opening interactive shell sessions

* Exposes host key information during connection setup so applications can enforce their own trust policy

* Supports widely-used encryption modes such as Galois/Counter Mode (GCM) and Counter Mode (CTR), along with modern host key algorithms including `ssh-ed25519`

* Designed for testing, development, automation, and internal tooling. It is **not security-audited**. See [disclaimer](#disclaimer).

* Lightweight and dependency-friendly, making it suitable for embedding into existing ReactPHP applications and service tooling

**Table of Contents**

* [Disclaimer](#disclaimer)
* [Installation](#installation)
* [Quickstart example](#quickstart-example)
* [Examples](#examples)
* [Client usage](#client-usage)
  * [Connector](#connector)
  * [Client](#client)
  * [Process](#process)
  * [Shell](#shell)
* [Authentication](#authentication)
  * [Password authentication](#password-authentication)
  * [Public key authentication](#public-key-authentication)
  * [Keyboard-interactive authentication](#keyboard-interactive-authentication)
* [Host key verification](#host-key-verification)
* [Configuration values](#configuration-values)
* [Error handling](#error-handling)
* [Supported algorithms](#supported-algorithms)
* [Unsupported features](#unsupported-features)
* [Contributions](#contributions)
* [License](#license)
* [Support and Credits](#support-and-credits)

## Disclaimer

This project is intended for educational use, testing, internal tooling, and automation scenarios. It has not been security-audited and should not be treated as a drop-in replacement for hardened SSH tooling without careful review.

In particular, applications using this library remain responsible for safe credential handling and host key verification. Examples that accept any host key are for demonstration purposes only.

If you need a mature, security-audited SSH client for general-purpose interactive system access, you should evaluate established tools such as OpenSSH.

In no event shall the authors of reactphp-ssh-client be liable for anything that happens while using this library. Please read the [license](#license) for the full disclaimer.

## Installation

Install via Composer:

```bash
composer require williameggers/reactphp-ssh-client
```

This library requires **PHP 8.2 or higher** and the following PHP extensions:

* **ext-sodium** - for cryptographic operations such as Curve25519 and Ed25519 support

* **ext-mbstring** - for multibyte string handling

* **ext-openssl** - for RSA and AES encryption support

Ensure these extensions are enabled in your environment before installing the package.

## Quickstart example

Here is a minimal example that connects with password authentication and runs a command:

```php
use React\EventLoop\Loop;
use WilliamEggers\React\SSHClient\Authentication\PasswordAuthentication;
use WilliamEggers\React\SSHClient\Client;
use WilliamEggers\React\SSHClient\Connector;
use WilliamEggers\React\SSHClient\HostKey\CallbackHostKeyVerifier;
use WilliamEggers\React\SSHClient\Process;

$connector = new Connector();

$connector->connect(
    '127.0.0.1:2222',
    new PasswordAuthentication('test', 'abc123'),
    // Demo-only verifier. See the host key verification section below for a safer pattern.
    new CallbackHostKeyVerifier(static fn (): bool => true),
)->then(
    function (Client $client): void {
        $client->exec('whoami')->then(
            function (Process $process) use ($client): void {
                $process->stdout()->on('data', static function (string $data): void {
                    fwrite(STDOUT, $data);
                });

                $process->stderr()->on('data', static function (string $data): void {
                    fwrite(STDERR, $data);
                });

                $process->join()->then(static function (int $exitCode) use ($client): void {
                    fwrite(STDERR, sprintf("\nProcess exited with code %d\n", $exitCode));
                    $client->close();
                    Loop::stop();
                });
            }
        );
    },
    static function (Throwable $throwable): void {
        fwrite(STDERR, 'SSH connection failed: ' . $throwable->getMessage() . PHP_EOL);
        Loop::stop();
    }
);

Loop::run();
```

See also the [examples](examples) directory.

## Examples

The [`examples`](examples) directory contains small standalone scripts for common use cases:

* `quickstart.php` - connect with password auth and run a command
* `quickstart-with-logging.php` - same flow with inline PSR-3 logging output
* `exec.php` - run a command with environment variables and stream stdout/stderr
* `shell.php` - open an interactive shell session
* `keyboard-interactive.php` - authenticate with keyboard-interactive prompts
* `public-key-authentication.php` - authenticate with a private key from disk
* `host-key-verification.php` - verify a server fingerprint before continuing
* `test-server.php` - launch a local SSH server for exercising the client examples

Each example can be run directly with:

```bash
php examples/<file>.php
```

You can launch a matching SSH test server from this repository:

```bash
php examples/test-server.php
```

This example relies on the dev dependency `williameggers/reactphp-ssh-server`.

With the test server running, these examples work against its default configuration:

```bash
php examples/quickstart.php
php examples/exec.php
php examples/shell.php
php examples/keyboard-interactive.php
```

To enable `public-key-authentication.php`, start the server with an OpenSSH-format public key:

```bash
SSH_AUTHORIZED_KEY="$(cat /path/to/id_ed25519.pub)" php examples/test-server.php
SSH_PRIVATE_KEY=/path/to/id_ed25519 php examples/public-key-authentication.php
```

## Client usage

### Connector

The `Connector` is the main entry point for opening SSH connections.

It accepts an optional PSR-3 logger in its constructor and exposes a single `connect()` method:

* **connect(string $uri, AuthenticationInterface $authentication, HostKeyVerifierInterface $hostKeyVerifier): PromiseInterface<Client>**

The URI should be a host and port combination such as:

```php
'127.0.0.1:22'
'example.com:22'
'[::1]:22'
```

Example:

```php
$connector = new WilliamEggers\React\SSHClient\Connector();

$connector->connect(
    'example.com:22',
    new WilliamEggers\React\SSHClient\Authentication\PasswordAuthentication('user', 'secret'),
    new WilliamEggers\React\SSHClient\HostKey\CallbackHostKeyVerifier(static fn (): bool => true),
);
```

### Client

The `Client` represents an authenticated SSH connection and is resolved from `Connector::connect()`.

It provides the following methods:

* **exec(string $command, ?ProcessConfig $config = null): PromiseInterface<Process>**

* **shell(?ShellConfig $config = null): PromiseInterface<Shell>**

* **close(): void**

Use `exec()` to run a single remote command:

```php
$client->exec('uname -a')->then(function (WilliamEggers\React\SSHClient\Process $process): void {
    $process->stdout()->on('data', static function (string $data): void {
        fwrite(STDOUT, $data);
    });
});
```

Use `shell()` to open an interactive session with a PTY:

```php
use WilliamEggers\React\SSHClient\Values\ShellConfig;
use WilliamEggers\React\SSHClient\Values\WinSize;

$client->shell(new ShellConfig('xterm-256color', new WinSize(24, 80)))->then(
    function (WilliamEggers\React\SSHClient\Shell $shell): void {
        $shell->stdout()->on('data', static function (string $data): void {
            fwrite(STDOUT, $data);
        });
    }
);
```

### Process

The `Process` class represents a remote exec request after it has been successfully started.

It exposes stream accessors for stdin, stdout, and stderr, plus a `join()` method for waiting on the remote exit status:

* **stdin(): WritableStreamInterface**

* **stdout(): ReadableStreamInterface**

* **stderr(): ReadableStreamInterface**

* **join(): PromiseInterface<int>**

* **close(): void**

`join()` resolves with the remote process exit code once the channel closes.

### Shell

The `Shell` class represents an interactive remote shell session.

It provides the same stream accessors as `Process`, plus terminal resizing support:

* **stdin(): WritableStreamInterface**

* **stdout(): ReadableStreamInterface**

* **stderr(): ReadableStreamInterface**

* **resize(WinSize $size): void**

* **join(): PromiseInterface<int>**

* **close(): void**

Use `resize()` to propagate terminal size changes to the remote session.

## Authentication

Authentication is configured by passing an implementation of `AuthenticationInterface` to `Connector::connect()`.

### Password authentication

Use `PasswordAuthentication` when authenticating with a username and password:

```php
use WilliamEggers\React\SSHClient\Authentication\PasswordAuthentication;

$authentication = new PasswordAuthentication('user', 'secret');
```

### Public key authentication

Use `PublicKeyAuthentication` when authenticating with an SSH private key.

You can either pass the private key contents directly to the constructor or use `fromPath()` to load from disk:

```php
use WilliamEggers\React\SSHClient\Authentication\PublicKeyAuthentication;

$authentication = PublicKeyAuthentication::fromPath('user', '/home/user/.ssh/id_ed25519');
```

If the key is passphrase-protected, provide the passphrase as the third argument.

### Keyboard-interactive authentication

Use `KeyboardInteractiveAuthentication` when the server presents one or more prompts and expects corresponding responses.

The responder callback receives a `KeyboardInteractiveChallenge` containing:

* `name`
* `instruction`
* `languageTag`
* `prompts` as a list of `KeyboardInteractivePrompt` objects

Example:

```php
use WilliamEggers\React\SSHClient\Authentication\KeyboardInteractiveAuthentication;
use WilliamEggers\React\SSHClient\Values\KeyboardInteractiveChallenge;

$authentication = new KeyboardInteractiveAuthentication(
    'user',
    static function (KeyboardInteractiveChallenge $challenge): array {
        return ['secret'];
    }
);
```

## Host key verification

Host key verification is required for every connection and is configured using a `HostKeyVerifierInterface` implementation.

This package currently provides `CallbackHostKeyVerifier`, which accepts a callback with the signature:

```php
function (WilliamEggers\React\SSHClient\HostKey\HostKeyInfo $hostKey, string $address): bool
```

`HostKeyInfo` exposes:

* `algorithm`
* `blob`
* `opensshKey`
* `getFingerprintSha256(): string`

Example using a pinned fingerprint:

```php
use WilliamEggers\React\SSHClient\HostKey\CallbackHostKeyVerifier;
use WilliamEggers\React\SSHClient\HostKey\HostKeyInfo;

$expectedFingerprint = 'SHA256:your-base64-fingerprint-here';

$verifier = new CallbackHostKeyVerifier(
    static function (HostKeyInfo $hostKey, string $address) use ($expectedFingerprint): bool {
        return hash_equals($expectedFingerprint, $hostKey->getFingerprintSha256());
    }
);
```

> [!IMPORTANT]
> Returning `true` unconditionally disables host authenticity checks and should only be used in local tests or throwaway demos. See [`examples/host-key-verification.php`](examples/host-key-verification.php) for the recommended pattern.

## Configuration values

Several small value objects are used to configure process and shell sessions.

### ProcessConfig

`ProcessConfig` currently supports:

* `environment` - associative array of environment variables to request before starting the remote command

### ShellConfig

`ShellConfig` currently supports:

* `term` - terminal type string, default `xterm`

* `size` - a `WinSize` instance, default `24x80`

* `environment` - associative array of environment variables to request before starting the shell

### WinSize

`WinSize` accepts:

* `rows`

* `cols`

* `widthPixels`

* `heightPixels`

## Error handling

Connection and session operations may reject their promises with exceptions from the `WilliamEggers\React\SSHClient\Exception` namespace.

The most relevant exception types are:

* `AuthenticationException` - authentication failed, an unsupported auth flow was attempted, or a challenge/response exchange was invalid

* `HostKeyException` - the server host key was rejected by your verifier or the host key signature validation failed

* `ProtocolException` - the remote peer sent invalid or unexpected SSH protocol data, or the channel/session flow failed

In most applications, these should be handled by attaching rejection handlers to `connect()`, `exec()`, and `shell()` promises:

```php
$connector->connect($uri, $authentication, $verifier)->then(
    static function (WilliamEggers\React\SSHClient\Client $client): void {
        // Use the client
    },
    static function (Throwable $throwable): void {
        fwrite(STDERR, $throwable->getMessage() . PHP_EOL);
    }
);
```

## Supported algorithms

The negotiated algorithm lists are defined in `KexNegotiator`.

### Key exchange methods

The following key exchange methods are supported:

* `curve25519-sha256`
* `curve25519-sha256@libssh.org`
* `diffie-hellman-group14-sha256`

### Server host key algorithms

The following server host key algorithms are supported:

* `ssh-ed25519`
* `rsa-sha2-256`
* `rsa-sha2-512`

### Encryption algorithms

The following encryption algorithms are supported:

* `aes256-gcm@openssh.com`
* `aes128-gcm@openssh.com`
* `aes256-ctr`
* `aes192-ctr`
* `aes128-ctr`

### MAC algorithms

The following MAC algorithms are supported:

* `hmac-sha2-512`
* `hmac-sha2-256`
* `hmac-sha1`

### Compression algorithms

The following compression algorithms are supported:

* `none`

## Unsupported features

This SSH client implementation is intentionally scoped to support core SSH connection management, authentication, exec requests, and shell sessions. The following features are not currently supported:

* **SFTP and SCP** - file transfer protocols and helpers are not implemented

* **Port forwarding** - local, remote, and dynamic forwarding are not supported

* **Agent forwarding** - SSH agent forwarding is not implemented

* **Known hosts file management** - this package does not parse or maintain OpenSSH `known_hosts` files for you

* **Subsystem support beyond session channels** - subsystem-specific helpers are not provided

* **Proxy or jump host features** - this client does not act as an SSH proxy chain or bastion helper

* **Compression negotiation beyond `none`** - no alternative compression modes are currently implemented

## Contributions

Contributions are welcome and encouraged.

To contribute:

1. Fork the repository.
2. Create a new branch for your changes.
3. Submit a pull request with a clear description of what you've done and why.

Please try to follow existing coding style and conventions, and include tests if applicable. Feel free to open an issue if you'd like to discuss a potential change or need guidance on where to start.

## License

BSD 2-Clause License

Copyright (c) 2026, William Eggers

Redistribution and use in source and binary forms, with or without
modification, are permitted provided that the following conditions are met:

1. Redistributions of source code must retain the above copyright notice, this
   list of conditions and the following disclaimer.

2. Redistributions in binary form must reproduce the above copyright notice,
   this list of conditions and the following disclaimer in the documentation
   and/or other materials provided with the distribution.

THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS "AS IS"
AND ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT LIMITED TO, THE
IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS FOR A PARTICULAR PURPOSE ARE
DISCLAIMED. IN NO EVENT SHALL THE COPYRIGHT HOLDER OR CONTRIBUTORS BE LIABLE
FOR ANY DIRECT, INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY, OR CONSEQUENTIAL
DAMAGES (INCLUDING, BUT NOT LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR
SERVICES; LOSS OF USE, DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER
CAUSED AND ON ANY THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT LIABILITY,
OR TORT (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY OUT OF THE USE
OF THIS SOFTWARE, EVEN IF ADVISED OF THE POSSIBILITY OF SUCH DAMAGE.

## Support and Credits

This project shares lineage with the related ReactPHP SSH server work and earlier SSH protocol implementations that inspired and informed its structure.

If you are using both sides of the stack, see also [`williameggers/reactphp-ssh-server`](https://github.com/williameggers/reactphp-ssh-server).
