<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Security\PasswordHasher;
use Tests\Support\TestCase;

final class PasswordHasherTest extends TestCase
{
    public function testHashAndVerify(): void
    {
        $hasher = new PasswordHasher();
        $hash = $hasher->hash('geheim-123');
        $this->assertTrue(str_starts_with($hash, '$argon2id$'));
        $this->assertTrue($hasher->verify('geheim-123', $hash));
        $this->assertFalse($hasher->verify('falsch', $hash));
    }
}
