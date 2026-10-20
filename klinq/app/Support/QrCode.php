<?php

declare(strict_types=1);

namespace App\Support;

/**
 * QR-Code-Encoder (ISO/IEC 18004), Byte-Modus, Versionen 1–40, Fehlerkorrektur L/M/Q/H.
 * Ohne externe Abhängigkeiten; erzeugt eine Modulmatrix und daraus SVG.
 */
final class QrCode
{
    public const ECC_L = 0;
    public const ECC_M = 1;
    public const ECC_Q = 2;
    public const ECC_H = 3;

    private const ECC_FORMAT_BITS = [1, 0, 3, 2];

    /** EC-Codewörter pro Block, Index [level][version-1] */
    private const ECC_CODEWORDS_PER_BLOCK = [
        [7, 10, 15, 20, 26, 18, 20, 24, 30, 18, 20, 24, 26, 30, 22, 24, 28, 30, 28, 28, 28, 28, 30, 30, 26, 28, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30],
        [10, 16, 26, 18, 24, 16, 18, 22, 22, 26, 30, 22, 22, 24, 24, 28, 28, 26, 26, 26, 26, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28],
        [13, 22, 18, 26, 18, 24, 18, 22, 20, 24, 28, 26, 24, 20, 30, 24, 28, 28, 26, 30, 28, 30, 30, 30, 30, 28, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30],
        [17, 28, 22, 16, 22, 28, 26, 26, 24, 28, 24, 28, 22, 24, 24, 30, 28, 28, 26, 28, 30, 24, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30],
    ];

    /** Anzahl EC-Blöcke, Index [level][version-1] */
    private const NUM_ERROR_CORRECTION_BLOCKS = [
        [1, 1, 1, 1, 1, 2, 2, 2, 2, 4, 4, 4, 4, 4, 6, 6, 6, 6, 7, 8, 8, 9, 9, 10, 12, 12, 12, 13, 14, 15, 16, 17, 18, 19, 19, 20, 21, 22, 24, 25],
        [1, 1, 1, 2, 2, 4, 4, 4, 5, 5, 5, 8, 9, 9, 10, 10, 11, 13, 14, 16, 17, 17, 18, 20, 21, 23, 25, 26, 28, 29, 31, 33, 35, 37, 38, 40, 43, 45, 47, 49],
        [1, 1, 2, 2, 4, 4, 6, 6, 8, 8, 8, 10, 12, 16, 12, 17, 16, 18, 21, 20, 23, 23, 25, 27, 29, 34, 34, 35, 38, 40, 43, 45, 48, 51, 53, 56, 59, 62, 65, 68],
        [1, 1, 2, 4, 4, 4, 5, 6, 8, 8, 11, 11, 16, 16, 18, 16, 19, 21, 25, 25, 25, 34, 30, 32, 35, 37, 40, 42, 45, 48, 51, 54, 57, 60, 63, 66, 70, 74, 77, 81],
    ];

    private int $size;
    /** @var array<int,array<int,bool>> */
    private array $modules = [];
    /** @var array<int,array<int,bool>> Funktionsmuster (nicht maskieren) */
    private array $isFunction = [];

    private function __construct(private readonly int $version, private readonly int $ecc, array $dataCodewords, int $mask)
    {
        $this->size = $version * 4 + 17;
        for ($y = 0; $y < $this->size; $y++) {
            $this->modules[$y] = array_fill(0, $this->size, false);
            $this->isFunction[$y] = array_fill(0, $this->size, false);
        }
        $this->drawFunctionPatterns();
        $allCodewords = $this->addEccAndInterleave($dataCodewords);
        $this->drawCodewords($allCodewords);

        if ($mask === -1) {
            $minPenalty = PHP_INT_MAX;
            for ($i = 0; $i < 8; $i++) {
                $this->applyMask($i);
                $this->drawFormatBits($i);
                $penalty = $this->getPenaltyScore();
                if ($penalty < $minPenalty) {
                    $mask = $i;
                    $minPenalty = $penalty;
                }
                $this->applyMask($i); // XOR ist selbstinvers
            }
        }
        $this->applyMask($mask);
        $this->drawFormatBits($mask);
        $this->isFunction = [];
    }

    /**
     * Kodiert Text im Byte-Modus (UTF-8) mit der kleinsten passenden Version.
     * @param int $mask -1 = automatische Wahl
     */
    public static function encode(string $text, int $ecc = self::ECC_M, int $minVersion = 1, int $maxVersion = 40, int $mask = -1, bool $boostEcc = true): self
    {
        if ($ecc < 0 || $ecc > 3 || $minVersion < 1 || $maxVersion > 40 || $minVersion > $maxVersion) {
            throw new \InvalidArgumentException('Ungültige QR-Parameter.');
        }
        $bytes = array_values(unpack('C*', $text) ?: []);
        $dataLen = count($bytes);

        $version = $minVersion;
        for (;; $version++) {
            $capacityBits = self::getNumDataCodewords($version, $ecc) * 8;
            $usedBits = 4 + ($version <= 9 ? 8 : 16) + $dataLen * 8;
            if ($usedBits <= $capacityBits) {
                break;
            }
            if ($version >= $maxVersion) {
                throw new \LengthException('Daten zu lang für QR-Code.');
            }
        }
        if ($boostEcc) {
            foreach ([self::ECC_M, self::ECC_Q, self::ECC_H] as $better) {
                if ($better > $ecc && $usedBits <= self::getNumDataCodewords($version, $better) * 8) {
                    $ecc = $better;
                }
            }
        }

        // Bitstrom: Modus 0100, Zeichenzahl, Daten, Terminator, Padding
        $bits = [];
        self::appendBits($bits, 0b0100, 4);
        self::appendBits($bits, $dataLen, $version <= 9 ? 8 : 16);
        foreach ($bytes as $b) {
            self::appendBits($bits, $b, 8);
        }
        $capacityBits = self::getNumDataCodewords($version, $ecc) * 8;
        self::appendBits($bits, 0, min(4, $capacityBits - count($bits)));
        self::appendBits($bits, 0, (8 - count($bits) % 8) % 8);
        for ($pad = 0xEC; count($bits) < $capacityBits; $pad ^= 0xEC ^ 0x11) {
            self::appendBits($bits, $pad, 8);
        }
        $codewords = array_fill(0, intdiv(count($bits), 8), 0);
        foreach ($bits as $i => $bit) {
            $codewords[$i >> 3] |= $bit << (7 - ($i & 7));
        }

        return new self($version, $ecc, $codewords, $mask);
    }

    public function size(): int
    {
        return $this->size;
    }

    public function version(): int
    {
        return $this->version;
    }

    public function isDark(int $x, int $y): bool
    {
        return $this->modules[$y][$x] ?? false;
    }

    /** @return array<int,array<int,bool>> */
    public function matrix(): array
    {
        return $this->modules;
    }

    /**
     * SVG mit einem einzigen Pfad (skalierbar, druckscharf).
     * @param int $border Ruhezone in Modulen (Norm: 4)
     */
    public function toSvg(int $border = 4, string $dark = '#000000', ?string $light = '#ffffff', ?string $class = null): string
    {
        $dim = $this->size + $border * 2;
        $path = [];
        for ($y = 0; $y < $this->size; $y++) {
            $runStart = null;
            for ($x = 0; $x <= $this->size; $x++) {
                $isDark = $x < $this->size && $this->modules[$y][$x];
                if ($isDark && $runStart === null) {
                    $runStart = $x;
                } elseif (!$isDark && $runStart !== null) {
                    $path[] = sprintf('M%d %dh%dv1h-%dz', $runStart + $border, $y + $border, $x - $runStart, $x - $runStart);
                    $runStart = null;
                }
            }
        }
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' . $dim . ' ' . $dim . '" shape-rendering="crispEdges"'
            . ($class !== null ? ' class="' . htmlspecialchars($class, ENT_QUOTES) . '"' : '') . ' role="img" aria-label="QR-Code">';
        if ($light !== null) {
            $svg .= '<rect width="100%" height="100%" fill="' . htmlspecialchars($light, ENT_QUOTES) . '"/>';
        }
        $svg .= '<path d="' . implode('', $path) . '" fill="' . htmlspecialchars($dark, ENT_QUOTES) . '"/></svg>';

        return $svg;
    }

    // ------------------------------------------------------------------ Aufbau

    private function drawFunctionPatterns(): void
    {
        for ($i = 0; $i < $this->size; $i++) {
            $this->setFunctionModule(6, $i, $i % 2 === 0);
            $this->setFunctionModule($i, 6, $i % 2 === 0);
        }
        $this->drawFinderPattern(3, 3);
        $this->drawFinderPattern($this->size - 4, 3);
        $this->drawFinderPattern(3, $this->size - 4);

        $alignPos = $this->getAlignmentPatternPositions();
        $numAlign = count($alignPos);
        for ($i = 0; $i < $numAlign; $i++) {
            for ($j = 0; $j < $numAlign; $j++) {
                if (!(($i === 0 && $j === 0) || ($i === 0 && $j === $numAlign - 1) || ($i === $numAlign - 1 && $j === 0))) {
                    $this->drawAlignmentPattern($alignPos[$i], $alignPos[$j]);
                }
            }
        }
        $this->drawFormatBits(0);
        $this->drawVersion();
    }

    private function drawFormatBits(int $mask): void
    {
        $data = (self::ECC_FORMAT_BITS[$this->ecc] << 3) | $mask;
        $rem = $data;
        for ($i = 0; $i < 10; $i++) {
            $rem = ($rem << 1) ^ ((($rem >> 9) & 1) * 0x537);
        }
        $bits = (($data << 10) | $rem) ^ 0x5412;

        for ($i = 0; $i <= 5; $i++) {
            $this->setFunctionModule(8, $i, self::getBit($bits, $i));
        }
        $this->setFunctionModule(8, 7, self::getBit($bits, 6));
        $this->setFunctionModule(8, 8, self::getBit($bits, 7));
        $this->setFunctionModule(7, 8, self::getBit($bits, 8));
        for ($i = 9; $i < 15; $i++) {
            $this->setFunctionModule(14 - $i, 8, self::getBit($bits, $i));
        }
        for ($i = 0; $i < 8; $i++) {
            $this->setFunctionModule($this->size - 1 - $i, 8, self::getBit($bits, $i));
        }
        for ($i = 8; $i < 15; $i++) {
            $this->setFunctionModule(8, $this->size - 15 + $i, self::getBit($bits, $i));
        }
        $this->setFunctionModule(8, $this->size - 8, true);
    }

    private function drawVersion(): void
    {
        if ($this->version < 7) {
            return;
        }
        $rem = $this->version;
        for ($i = 0; $i < 12; $i++) {
            $rem = ($rem << 1) ^ ((($rem >> 11) & 1) * 0x1F25);
        }
        $bits = ($this->version << 12) | $rem;
        for ($i = 0; $i < 18; $i++) {
            $bit = self::getBit($bits, $i);
            $a = $this->size - 11 + $i % 3;
            $b = intdiv($i, 3);
            $this->setFunctionModule($a, $b, $bit);
            $this->setFunctionModule($b, $a, $bit);
        }
    }

    private function drawFinderPattern(int $x, int $y): void
    {
        for ($dy = -4; $dy <= 4; $dy++) {
            for ($dx = -4; $dx <= 4; $dx++) {
                $dist = max(abs($dx), abs($dy));
                $xx = $x + $dx;
                $yy = $y + $dy;
                if ($xx >= 0 && $xx < $this->size && $yy >= 0 && $yy < $this->size) {
                    $this->setFunctionModule($xx, $yy, $dist !== 2 && $dist !== 4);
                }
            }
        }
    }

    private function drawAlignmentPattern(int $x, int $y): void
    {
        for ($dy = -2; $dy <= 2; $dy++) {
            for ($dx = -2; $dx <= 2; $dx++) {
                $this->setFunctionModule($x + $dx, $y + $dy, max(abs($dx), abs($dy)) !== 1);
            }
        }
    }

    private function setFunctionModule(int $x, int $y, bool $isDark): void
    {
        $this->modules[$y][$x] = $isDark;
        $this->isFunction[$y][$x] = true;
    }

    /** @param array<int,int> $data @return array<int,int> */
    private function addEccAndInterleave(array $data): array
    {
        $numBlocks = self::NUM_ERROR_CORRECTION_BLOCKS[$this->ecc][$this->version - 1];
        $blockEccLen = self::ECC_CODEWORDS_PER_BLOCK[$this->ecc][$this->version - 1];
        $rawCodewords = intdiv(self::getNumRawDataModules($this->version), 8);
        $numShortBlocks = $numBlocks - $rawCodewords % $numBlocks;
        $shortBlockLen = intdiv($rawCodewords, $numBlocks);

        $blocks = [];
        $rsDiv = self::reedSolomonComputeDivisor($blockEccLen);
        for ($i = 0, $k = 0; $i < $numBlocks; $i++) {
            $datLen = $shortBlockLen - $blockEccLen + ($i < $numShortBlocks ? 0 : 1);
            $dat = array_slice($data, $k, $datLen);
            $k += $datLen;
            $ecc = self::reedSolomonComputeRemainder($dat, $rsDiv);
            if ($i < $numShortBlocks) {
                $dat[] = 0; // Platzhalter für Interleaving
            }
            $blocks[] = array_merge($dat, $ecc);
        }

        $result = [];
        $blockLen = count($blocks[0]);
        for ($i = 0; $i < $blockLen; $i++) {
            foreach ($blocks as $j => $block) {
                if ($i !== $shortBlockLen - $blockEccLen || $j >= $numShortBlocks) {
                    $result[] = $block[$i];
                }
            }
        }

        return $result;
    }

    /** @param array<int,int> $data */
    private function drawCodewords(array $data): void
    {
        $i = 0;
        $total = count($data) * 8;
        for ($right = $this->size - 1; $right >= 1; $right -= 2) {
            if ($right === 6) {
                $right = 5;
            }
            for ($vert = 0; $vert < $this->size; $vert++) {
                for ($j = 0; $j < 2; $j++) {
                    $x = $right - $j;
                    $upward = (($right + 1) & 2) === 0;
                    $y = $upward ? $this->size - 1 - $vert : $vert;
                    if (!$this->isFunction[$y][$x] && $i < $total) {
                        $this->modules[$y][$x] = self::getBit($data[$i >> 3], 7 - ($i & 7));
                        $i++;
                    }
                }
            }
        }
    }

    private function applyMask(int $mask): void
    {
        for ($y = 0; $y < $this->size; $y++) {
            for ($x = 0; $x < $this->size; $x++) {
                $invert = match ($mask) {
                    0 => ($x + $y) % 2 === 0,
                    1 => $y % 2 === 0,
                    2 => $x % 3 === 0,
                    3 => ($x + $y) % 3 === 0,
                    4 => (intdiv($x, 3) + intdiv($y, 2)) % 2 === 0,
                    5 => $x * $y % 2 + $x * $y % 3 === 0,
                    6 => ($x * $y % 2 + $x * $y % 3) % 2 === 0,
                    7 => (($x + $y) % 2 + $x * $y % 3) % 2 === 0,
                    default => throw new \InvalidArgumentException('Maske ungültig.'),
                };
                if (!$this->isFunction[$y][$x] && $invert) {
                    $this->modules[$y][$x] = !$this->modules[$y][$x];
                }
            }
        }
    }

    private function getPenaltyScore(): int
    {
        $result = 0;
        $size = $this->size;

        // Läufe gleicher Farbe in Zeilen und Spalten + Finder-ähnliche Muster
        for ($y = 0; $y < $size; $y++) {
            $runColor = false;
            $runX = 0;
            $runHistory = array_fill(0, 7, 0);
            for ($x = 0; $x < $size; $x++) {
                if ($this->modules[$y][$x] === $runColor) {
                    $runX++;
                    if ($runX === 5) {
                        $result += 3;
                    } elseif ($runX > 5) {
                        $result++;
                    }
                } else {
                    self::finderPenaltyAddHistory($runX, $runHistory, $size);
                    if (!$runColor) {
                        $result += self::finderPenaltyCountPatterns($runHistory, $size) * 40;
                    }
                    $runColor = $this->modules[$y][$x];
                    $runX = 1;
                }
            }
            $result += self::finderPenaltyTerminateAndCount($runColor, $runX, $runHistory, $size) * 40;
        }
        for ($x = 0; $x < $size; $x++) {
            $runColor = false;
            $runY = 0;
            $runHistory = array_fill(0, 7, 0);
            for ($y = 0; $y < $size; $y++) {
                if ($this->modules[$y][$x] === $runColor) {
                    $runY++;
                    if ($runY === 5) {
                        $result += 3;
                    } elseif ($runY > 5) {
                        $result++;
                    }
                } else {
                    self::finderPenaltyAddHistory($runY, $runHistory, $size);
                    if (!$runColor) {
                        $result += self::finderPenaltyCountPatterns($runHistory, $size) * 40;
                    }
                    $runColor = $this->modules[$y][$x];
                    $runY = 1;
                }
            }
            $result += self::finderPenaltyTerminateAndCount($runColor, $runY, $runHistory, $size) * 40;
        }

        // 2×2-Blöcke
        for ($y = 0; $y < $size - 1; $y++) {
            for ($x = 0; $x < $size - 1; $x++) {
                $c = $this->modules[$y][$x];
                if ($c === $this->modules[$y][$x + 1] && $c === $this->modules[$y + 1][$x] && $c === $this->modules[$y + 1][$x + 1]) {
                    $result += 3;
                }
            }
        }

        // Anteil dunkler Module
        $dark = 0;
        foreach ($this->modules as $row) {
            $dark += count(array_filter($row));
        }
        $total = $size * $size;
        $k = intdiv(abs($dark * 20 - $total * 10) + $total - 1, $total) - 1;

        return $result + $k * 10;
    }

    /** @param array<int,int> $runHistory */
    private static function finderPenaltyCountPatterns(array $runHistory, int $size): int
    {
        $n = $runHistory[1];
        $core = $n > 0 && $runHistory[2] === $n && $runHistory[3] === $n * 3 && $runHistory[4] === $n && $runHistory[5] === $n;

        return ($core && $runHistory[0] >= $n * 4 && $runHistory[6] >= $n ? 1 : 0)
            + ($core && $runHistory[6] >= $n * 4 && $runHistory[0] >= $n ? 1 : 0);
    }

    /** @param array<int,int> $runHistory */
    private static function finderPenaltyTerminateAndCount(bool $currentRunColor, int $currentRunLength, array &$runHistory, int $size): int
    {
        if ($currentRunColor) {
            self::finderPenaltyAddHistory($currentRunLength, $runHistory, $size);
            $currentRunLength = 0;
        }
        $currentRunLength += $size;
        self::finderPenaltyAddHistory($currentRunLength, $runHistory, $size);

        return self::finderPenaltyCountPatterns($runHistory, $size);
    }

    /** @param array<int,int> $runHistory */
    private static function finderPenaltyAddHistory(int $currentRunLength, array &$runHistory, int $size): void
    {
        if ($runHistory[0] === 0) {
            $currentRunLength += $size;
        }
        array_pop($runHistory);
        array_unshift($runHistory, $currentRunLength);
    }

    // ------------------------------------------------------------------ Tabellen/Hilfen

    /** @return array<int,int> */
    private function getAlignmentPatternPositions(): array
    {
        if ($this->version === 1) {
            return [];
        }
        $numAlign = intdiv($this->version, 7) + 2;
        $step = $this->version === 32 ? 26 : intdiv($this->version * 4 + $numAlign * 2 + 1, $numAlign * 2 - 2) * 2;
        $result = [6];
        for ($pos = $this->size - 7; count($result) < $numAlign; $pos -= $step) {
            array_splice($result, 1, 0, [$pos]);
        }

        return $result;
    }

    private static function getNumRawDataModules(int $ver): int
    {
        $result = (16 * $ver + 128) * $ver + 64;
        if ($ver >= 2) {
            $numAlign = intdiv($ver, 7) + 2;
            $result -= (25 * $numAlign - 10) * $numAlign - 55;
            if ($ver >= 7) {
                $result -= 36;
            }
        }

        return $result;
    }

    private static function getNumDataCodewords(int $ver, int $ecc): int
    {
        return intdiv(self::getNumRawDataModules($ver), 8)
            - self::ECC_CODEWORDS_PER_BLOCK[$ecc][$ver - 1] * self::NUM_ERROR_CORRECTION_BLOCKS[$ecc][$ver - 1];
    }

    /** @return array<int,int> */
    private static function reedSolomonComputeDivisor(int $degree): array
    {
        $result = array_fill(0, $degree, 0);
        $result[$degree - 1] = 1;
        $root = 1;
        for ($i = 0; $i < $degree; $i++) {
            for ($j = 0; $j < $degree; $j++) {
                $result[$j] = self::gfMultiply($result[$j], $root);
                if ($j + 1 < $degree) {
                    $result[$j] ^= $result[$j + 1];
                }
            }
            $root = self::gfMultiply($root, 0x02);
        }

        return $result;
    }

    /** @param array<int,int> $data @param array<int,int> $divisor @return array<int,int> */
    private static function reedSolomonComputeRemainder(array $data, array $divisor): array
    {
        $result = array_fill(0, count($divisor), 0);
        foreach ($data as $b) {
            $factor = $b ^ array_shift($result);
            $result[] = 0;
            foreach ($divisor as $i => $coef) {
                $result[$i] ^= self::gfMultiply($coef, $factor);
            }
        }

        return $result;
    }

    private static function gfMultiply(int $x, int $y): int
    {
        $z = 0;
        for ($i = 7; $i >= 0; $i--) {
            $z = ($z << 1) ^ ((($z >> 7) & 1) * 0x11D);
            $z ^= (($y >> $i) & 1) * $x;
        }

        return $z & 0xFF;
    }

    /** @param array<int,int> $bits */
    private static function appendBits(array &$bits, int $value, int $length): void
    {
        for ($i = $length - 1; $i >= 0; $i--) {
            $bits[] = ($value >> $i) & 1;
        }
    }

    private static function getBit(int $x, int $i): bool
    {
        return (($x >> $i) & 1) !== 0;
    }
}
