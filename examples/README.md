# Examples

Each example is a small standalone script, mirroring the example style used in the server project.

Run any example with:

```bash
php examples/<file>.php
```

## Common environment variables

- `SSH_HOST` default: `127.0.0.1`
- `SSH_PORT` default: `2222`
- `SSH_USERNAME` default: `test`
- `SSH_PASSWORD` default: `abc123`
- `SSH_COMMAND` default: `whoami`

Additional variables used by specific examples:

- `SSH_PRIVATE_KEY`
- `SSH_PRIVATE_KEY_PASSPHRASE`
- `SSH_KEYBOARD_RESPONSES` comma-separated responses for keyboard-interactive auth
- `SSH_HOST_FINGERPRINT` expected `SHA256:...` host key fingerprint
- `SSH_TERM`, `SSH_ROWS`, `SSH_COLS` for shell sessions
- `SSH_ENV_GREETING`, `SSH_ENV_TARGET` for `exec.php`

## Files

- `quickstart.php`: Connect with password auth and run a command.
- `quickstart-with-logging.php`: Same as quickstart, with an inline PSR-3 logger.
- `exec.php`: Run a command with environment variables and stream stdout/stderr.
- `shell.php`: Open an interactive shell session.
- `keyboard-interactive.php`: Authenticate with keyboard-interactive prompts.
- `public-key-authentication.php`: Authenticate with a private key from disk.
- `host-key-verification.php`: Verify the server host key fingerprint before continuing.

`quickstart.php`, `exec.php`, `shell.php`, `keyboard-interactive.php`, and `public-key-authentication.php` use a permissive host key verifier for demonstration purposes only. Use `host-key-verification.php` as the safer production pattern.
