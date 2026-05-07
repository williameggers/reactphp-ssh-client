<?php declare(strict_types=1);

namespace WilliamEggers\React\SSHClient\Authentication;

final readonly class PasswordAuthentication implements AuthenticationInterface
{
    public function __construct(
        private string $username,
        public string $password,
    ) {}

    public function getUsername(): string
    {
        return $this->username;
    }
}
