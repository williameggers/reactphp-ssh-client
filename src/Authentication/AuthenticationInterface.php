<?php declare(strict_types=1);

namespace WilliamEggers\React\SSHClient\Authentication;

interface AuthenticationInterface
{
    /**
     * Return the username to authenticate as.
     */
    public function getUsername(): string;
}
