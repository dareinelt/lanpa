<?php

declare(strict_types=1);

namespace App\Services\Helpdesk;

/**
 * Prioritätsmatrix Auswirkung × Dringlichkeit (jeweils 1 = niedrig … 3 = hoch) → Prioritätsstufe 1–4.
 * Eine manuell gesetzte Priorität hat immer Vorrang.
 */
final class TicketPriorityMatrix
{
    public const IMPACT_LABELS = [1 => 'Einzelne Person', 2 => 'Team / Abteilung', 3 => 'Unternehmensweit'];
    public const URGENCY_LABELS = [1 => 'Kann warten', 2 => 'Zeitnah', 3 => 'Sofort'];

    /** @var array<int,array<int,int>> [impact][urgency] => level */
    private const MATRIX = [
        1 => [1 => 1, 2 => 2, 3 => 2],
        2 => [1 => 2, 2 => 3, 3 => 3],
        3 => [1 => 3, 2 => 3, 3 => 4],
    ];

    public static function level(int $impact, int $urgency): int
    {
        $impact = max(1, min(3, $impact));
        $urgency = max(1, min(3, $urgency));

        return self::MATRIX[$impact][$urgency];
    }
}
