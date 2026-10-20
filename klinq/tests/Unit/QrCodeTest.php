<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\QrCode;
use Tests\Support\TestCase;

final class QrCodeTest extends TestCase
{
    /** @return array<int,string> */
    private function rows(QrCode $qr): array
    {
        return array_map(static fn (array $row): string => implode('', array_map(static fn (bool $d): string => $d ? '#' : '.', $row)), $qr->matrix());
    }

    public function testShortTextFitsInVersionOne(): void
    {
        $qr = QrCode::encode('PC26001', QrCode::ECC_M);
        $this->assertSame(1, $qr->version());
        $this->assertSame(21, $qr->size());
        $this->assertCount(21, $qr->matrix());
        $this->assertCount(21, $qr->matrix()[0]);
    }

    public function testLongerTextChoosesLargerVersionAndSizeFollowsFormula(): void
    {
        $qr = QrCode::encode('http://localhost:8080/a/ZUB26001', QrCode::ECC_M);
        $this->assertTrue($qr->version() > 1);
        $this->assertSame($qr->version() * 4 + 17, $qr->size());

        $low = QrCode::encode(str_repeat('A', 200), QrCode::ECC_L);
        $high = QrCode::encode(str_repeat('A', 200), QrCode::ECC_H);
        $this->assertTrue($high->version() > $low->version(), 'höhere Fehlerkorrektur braucht mehr Platz');
    }

    public function testFinderPatternsTimingPatternAndDarkModuleFollowTheStandard(): void
    {
        $qr = QrCode::encode('PC26001', QrCode::ECC_M);
        $n = $qr->size();

        // Suchmuster: 7x7 mit dunklem Rand, hellem Ring, dunklem 3x3-Kern – in drei Ecken
        foreach ([[0, 0], [$n - 7, 0], [0, $n - 7]] as [$ox, $oy]) {
            for ($dy = 0; $dy < 7; $dy++) {
                for ($dx = 0; $dx < 7; $dx++) {
                    $ring = max(abs($dx - 3), abs($dy - 3));
                    $this->assertSame($ring !== 2, $qr->isDark($ox + $dx, $oy + $dy), "Suchmuster bei ($ox,$oy) Offset ($dx,$dy)");
                }
            }
        }
        // Rechts unten liegt kein Suchmuster, sondern Daten
        $darkBottomRight = 0;
        for ($y = $n - 7; $y < $n; $y++) {
            for ($x = $n - 7; $x < $n; $x++) {
                $darkBottomRight += $qr->isDark($x, $y) ? 1 : 0;
            }
        }
        $this->assertTrue($darkBottomRight < 49);

        // Taktmuster: Zeile/Spalte 6 wechseln zwischen den Suchmustern ab
        for ($i = 8; $i < $n - 8; $i++) {
            $this->assertSame($i % 2 === 0, $qr->isDark($i, 6));
            $this->assertSame($i % 2 === 0, $qr->isDark(6, $i));
        }
        // Dunkles Modul immer bei (8, 4*v+9)
        $this->assertTrue($qr->isDark(8, 4 * $qr->version() + 9));
    }

    public function testFixedMaskIsDeterministicAndMasksDiffer(): void
    {
        $a = QrCode::encode('PC26001', QrCode::ECC_M, 1, 40, 0);
        $b = QrCode::encode('PC26001', QrCode::ECC_M, 1, 40, 0);
        $c = QrCode::encode('PC26001', QrCode::ECC_M, 1, 40, 5);
        $this->assertSame($a->matrix(), $b->matrix());
        $this->assertTrue($a->matrix() !== $c->matrix());
        $this->assertTrue($c->isDark(0, 0) && $c->isDark(3, 3) && !$c->isDark(1, 1), 'Suchmuster unabhängig von der Maske');
    }

    public function testAutomaticMaskSelectsExactlyOneOfTheEight(): void
    {
        $auto = QrCode::encode('http://localhost:8080/a/PC26001', QrCode::ECC_M);
        $matches = 0;
        for ($mask = 0; $mask < 8; $mask++) {
            if (QrCode::encode('http://localhost:8080/a/PC26001', QrCode::ECC_M, 1, 40, $mask)->matrix() === $auto->matrix()) {
                $matches++;
            }
        }
        $this->assertSame(1, $matches);
    }

    public function testMatchesReferenceEncoderForHelloWorld(): void
    {
        // Referenzmatrix aus python-qrcode (Byte-Modus, ECC M, Version 1, automatische Maske)
        $expected = [
            '#######.....#.#######',
            '#.....#..#.#..#.....#',
            '#.###.#.#.###.#.###.#',
            '#.###.#.#.....#.###.#',
            '#.###.#.##..#.#.###.#',
            '#.....#.####..#.....#',
            '#######.#.#.#.#######',
            '........#.#..........',
            '#.#####..###..#####..',
            '...##..##...##..###.#',
            '...#..#.###.###..###.',
            '.##..#.#..####.#.##..',
            '##.####.#...#.##....#',
            '........#....#####...',
            '#######..##.####..##.',
            '#.....#.#.#.##.#.###.',
            '#.###.#.##.####.#..##',
            '#.###.#.#.#....###...',
            '#.###.#.#####.##..#..',
            '#.....#...#.##..###..',
            '#######.##.#..#.#..#.',
        ];
        $qr = QrCode::encode('Hello, world!', QrCode::ECC_M, 1, 1, -1, false);
        $this->assertSame(1, $qr->version());
        $this->assertSame($expected, $this->rows($qr));
    }

    public function testSvgOutputContainsSinglePathAndViewBox(): void
    {
        $qr = QrCode::encode('PC26001', QrCode::ECC_M);
        $svg = $qr->toSvg(0, '#000000', null, 'label-qr-svg');
        $this->assertSame('<svg', substr($svg, 0, 4));
        $this->assertStringContains('viewBox="0 0 21 21"', $svg);
        $this->assertStringContains('class="label-qr-svg"', $svg);
        $this->assertSame(1, substr_count($svg, '<path'));
        $this->assertFalse(str_contains($svg, '<rect'), 'ohne Hintergrundfarbe kein Hintergrund-Rechteck');

        $withBorder = $qr->toSvg(4, '#000000', '#ffffff');
        $this->assertStringContains('viewBox="0 0 29 29"', $withBorder);
        $this->assertStringContains('<rect', $withBorder);
    }

    public function testSvgPathCoversExactlyTheDarkModules(): void
    {
        $qr = QrCode::encode('PC26001', QrCode::ECC_M);
        $dark = 0;
        foreach ($qr->matrix() as $row) {
            $dark += count(array_filter($row));
        }
        preg_match_all('/M(\d+) (\d+)h(\d+)v1h-\d+z/', $qr->toSvg(0), $m, PREG_SET_ORDER);
        $covered = 0;
        foreach ($m as [, $x, $y, $w]) {
            for ($i = 0; $i < (int) $w; $i++) {
                $this->assertTrue($qr->isDark((int) $x + $i, (int) $y), "Pfad deckt helles Modul ab bei " . ($x + $i) . ",$y");
                $covered++;
            }
        }
        $this->assertSame($dark, $covered);
    }

    public function testTooLongInputAndTooSmallVersionRangeThrow(): void
    {
        $this->assertThrows(\LengthException::class, static fn () => QrCode::encode(str_repeat('x', 3000), QrCode::ECC_H));
        $this->assertThrows(\LengthException::class, static fn () => QrCode::encode(str_repeat('x', 100), QrCode::ECC_H, 1, 1));
    }

    public function testMinimumVersionIsRespected(): void
    {
        $this->assertSame(5, QrCode::encode('PC26001', QrCode::ECC_L, 5, 10)->version());
    }
}
