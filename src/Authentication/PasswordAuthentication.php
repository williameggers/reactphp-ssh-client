<?php declare(strict_types=1);

namespace WilliamEggers\React\SSHClient\Authentication;

final readonly class PasswordAuthentication implements AuthenticationInterface
{
    /**
     * Create password-based authentication credentials.
     *
     * @param string $username remote username
     * @param string $password password sent during authentication
     */
    public function __construct(
        private string $username,
        public string $password,
    ) {}

    /**
     * Return the username to authenticate as.
     */
    public function getUsername(): string
    {
        return $this->username;
    }
}
