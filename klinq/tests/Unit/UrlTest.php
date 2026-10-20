<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\Url;
use Tests\Support\TestCase;

final class UrlTest extends TestCase
{
    public function testAcceptsInternalPaths(): void
    {
        $this->assertSame('/assets/1', Url::safeLocalPath('/assets/1'));
        $this->assertSame('/assets?q=a%20b&page=2#top', Url::safeLocalPath('/assets?q=a%20b&page=2#top'));
        $this->assertSame('/', Url::safeLocalPath('/'));
    }

    public function testRejectsForeignOrMalformedTargets(): void
    {
        foreach (['', 'https://evil.example', '//evil.example', '/\\evil.example', "/x\nLocation: y", 'assets', 'javascript:alert(1)', '/\\/evil', "/a\\b"] as $bad) {
            $this->assertSame('/fallback', Url::safeLocalPath($bad, '/fallback'), 'Sollte abgelehnt werden: ' . json_encode($bad));
        }
        $this->assertSame('', Url::safeLocalPath(null));
    }
}
