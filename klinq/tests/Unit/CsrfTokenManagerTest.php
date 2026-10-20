<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Security\CsrfTokenManager;
use Tests\Support\TestCase;

final class CsrfTokenManagerTest extends TestCase
{
    public function testTokenIsStableWithinSession(): void
    {
        $csrf = new CsrfTokenManager();
        $token = $csrf->token();
        $this->assertSame(64, strlen($token));
        $this->assertSame($token, $csrf->token());
    }

    public function testValidateAcceptsOnlyMatchingToken(): void
    {
        $csrf = new CsrfTokenManager();
        $token = $csrf->token();
        $this->assertTrue($csrf->validate($token));
        $this->assertFalse($csrf->validate('x' . substr($token, 1)));
        $this->assertFalse($csrf->validate(''));
        $this->assertFalse($csrf->validate(null));
    }

    public function testValidateFailsWithoutSessionToken(): void
    {
        $csrf = new CsrfTokenManager();
        $this->assertFalse($csrf->validate(str_repeat('a', 64)));
    }
}
