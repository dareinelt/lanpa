<?php

declare(strict_types=1);

namespace App\Security;

final class PasswordHasher
{
    public function hash(string $plainText): string
    {
        return password_hash($plainText, PASSWORD_ARGON2ID);
    }

    public function verify(string $plainText, string $hash): bool
    {
        return $hash !== '' && password_verify($plainText, $hash);
    }

    public function needsRehash(string $hash): bool
    {
        return password_needs_rehash($hash, PASSWORD_ARGON2ID);
    }
}
