<?php declare(strict_types=1);

namespace WilliamEggers\React\SSHClient\Authentication;

interface AuthenticationInterface
{
    public function getUsername(): string;
}
