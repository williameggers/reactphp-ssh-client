# AGENTS.md

This file is for coding agents working in `/workspaces/reactphp-ssh-client`.

## Project Summary

- PHP library implementing an asynchronous SSH client on top of ReactPHP.
- Main code lives in `src/`.
- Tests use Pest and live in `tests/`.
- Examples live in `examples/` and are included in PHPStan analysis.
- Minimum PHP version is `8.2`.

## Install

- Install dependencies with `composer install`.
- CI uses `composer install --no-interaction --prefer-dist --optimize-autoloader`.
- Required PHP extensions from `composer.json`: `mbstring`, `openssl`, `sodium`.
- Dev/test work also requires `pcntl`.

## Build, Lint, Test

- Run the default test suite with `composer test`.
- Run CI-style tests with `composer test:ci`.
- Run static analysis with `composer phpstan`.
- Run formatting check with `composer cs:check`.
- Auto-fix formatting with `composer cs:fix`.

## Single-Test Workflows

- Run one test by name with:
  `composer test -- --filter='connector rejects non-positive timeout values'`
- Run one test file with:
  `vendor/bin/pest tests/Unit/ConnectorTest.php`
- Run one integration test file with:
  `vendor/bin/pest tests/Integration/PasswordShellTest.php`
- Pest also accepts `--filter` directly:
  `vendor/bin/pest tests/Integration/PasswordShellTest.php --filter='connect rejects when SSH handshake exceeds timeout'`
- If you need raw PHPUnit-style invocation, Pest is bootstrapped by `phpunit.xml.dist`, but prefer Pest commands in this repo.

## Useful Validation Order

- For focused changes: run the smallest relevant Pest test first.
- Then run `composer phpstan` if code or types changed.
- Then run `composer cs:check` or `composer cs:fix` for touched PHP files.
- Before finishing a broad change, run `composer test`.

## Directory Map

- `src/Connector.php`: public entry point for establishing SSH connections.
- `src/Connection.php`: core protocol state machine and handshake flow.
- `src/Client.php`: high-level authenticated client API.
- `src/Process.php` and `src/Shell.php`: wrappers around session channels.
- `src/Authentication/`: authentication strategies.
- `src/HostKey/`: host key verification abstractions.
- `src/Values/`: small readonly configuration/value objects.
- `tests/Support/`: local SSH test server helpers.

## Style Sources

- Formatting rules are defined in `.php-cs-fixer.php`.
- Static analysis rules are defined in `phpstan.neon.dist`.
- Existing source files are the strongest guide for local conventions.

## Formatting Rules

- Every PHP file starts with `<?php declare(strict_types=1);` on one line.
- Follow PSR-12 plus the additional php-cs-fixer rules configured here.
- Use Unix line endings.
- Use short arrays: `[]`.
- Use single spaces around binary operators.
- Use one space around string concatenation: `'a' . 'b'`.
- Imports are ordered automatically by php-cs-fixer; keep them sorted.
- There is no blank line after the opening PHP tag.
- Trailing commas are commonly used in multiline parameter lists and arrays; preserve that style.

## Imports

- Prefer `use` imports for classes and interfaces.
- Alias imports when needed to resolve collisions, for example `ConnectorInterface as SocketConnectorInterface`.
- Global exceptions are often referenced with a leading slash inside namespaced files, for example `\RuntimeException`.
- Do not add unused imports.

## Types and Signatures

- Type everything that can reasonably be typed.
- Use scalar parameter and return types consistently.
- Use nullable types explicitly, for example `?float`, `?LoggerInterface`.
- Use union-like phpdoc only when PHP itself cannot express the shape cleanly.
- Add precise generic-style phpdoc for promises and arrays when useful, for example `@return PromiseInterface<Client>` and `@var list<string>`.
- Prefer readonly properties and `final` classes where the codebase already does.
- Small immutable configuration carriers should be `final readonly class` value objects.

## Naming Conventions

- Class names are descriptive nouns: `Connector`, `PacketHandler`, `ShellConfig`.
- Interfaces end with `Interface`.
- Exceptions end with `Exception`.
- Value objects use domain nouns such as `WinSize` and `HostKeyInfo`.
- Test names use Pest sentence style, lower-case, describing behavior.
- Method names are verbs or verb phrases: `connect`, `start`, `deriveKeys`, `resize`.

## Control Flow and Error Handling

- Fail fast on invalid input with exceptions.
- Use `\InvalidArgumentException` for invalid caller input.
- Use `\RuntimeException` for runtime failures and impossible operational states.
- Domain-specific failures may use custom exceptions under `src/Exception/`.
- Guard clauses are preferred over deep nesting.
- When unpacking or reading binary protocol data, validate assumptions before casting or indexing.
- Preserve informative error messages; many tests assert on message text.

## Async and Promise Conventions

- This codebase is ReactPHP-first and non-blocking by design.
- Public async operations return `PromiseInterface`.
- Use `Deferred` when bridging event-driven callbacks into promises.
- Keep promise chains explicit; resolving and rejecting manually is common in this repo.
- In tests, the event loop is usually driven with `Loop::run()` and stopped with `Loop::stop()`.
- `tests/Pest.php` stops the loop in `afterEach`; do not fight that pattern.

## Testing Conventions

- Use Pest's `test('description', function (): void { ... });` style.
- Keep unit tests focused and narrow.
- Integration tests commonly use `tests/Support/TestSshServer.php` helpers.
- When testing async flows, use `Loop::futureTick()` or timers to wait for server setup.
- Assertions often verify both type and message content.
- If behavior changes intentionally, update or add tests in the smallest relevant scope.

## Comments and Documentation

- Keep comments sparse and useful.
- Public APIs generally have short docblocks.
- Use phpdoc for promise generics, array shapes, and callback signatures.
- Do not add noisy comments that restate obvious code.

## Editing Guidance For Agents

- Make the smallest correct change.
- Preserve public API names unless the task explicitly requires a breaking change.
- Prefer extending existing patterns over introducing new abstractions.
- Keep code in one function unless reuse or clarity clearly justifies extraction.
- Do not add compatibility layers without evidence they are needed.
- Do not reformat unrelated files just to satisfy style preferences.

## Before Finishing

- Re-read touched files for consistency with nearby code.
- Run the smallest relevant validation commands.
- Mention clearly if a failure is pre-existing versus introduced by your change.
- If you changed protocol logic, prefer running both focused tests and the full suite.
