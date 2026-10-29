<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\TimelineGrouper;
use Tests\Support\TestCase;

/** Gruppierung kurz aufeinanderfolgender Verlaufsereignisse (Ticket-Historie). */
final class TimelineGrouperTest extends TestCase
{
    public function testEventsWithinWindowOfSameUserFormOneGroup(): void
    {
        $groups = TimelineGrouper::group([
            $this->event('viewed', '2026-10-27 11:23:00', 7),
            $this->event('comment', '2026-10-27 11:23:30', 7),
            $this->event('viewed', '2026-10-27 11:24:00', 7),
        ]);

        $this->assertCount(1, $groups);
        $this->assertCount(3, $groups[0]);
        $this->assertSame(['viewed', 'comment', 'viewed'], array_column($groups[0], 'type'), 'Reihenfolge bleibt erhalten');
    }

    public function testEventsBeyondWindowAreSeparateGroups(): void
    {
        $groups = TimelineGrouper::group([
            $this->event('viewed', '2026-10-27 11:23:00', 7),
            $this->event('comment', '2026-10-27 11:31:00', 7),
        ]);

        $this->assertCount(2, $groups);
        $this->assertCount(1, $groups[0]);
        $this->assertCount(1, $groups[1]);
    }

    public function testWindowIsMeasuredFromFirstEventOfGroup(): void
    {
        // 11:23, 11:27 und 11:31 liegen jeweils vier Minuten auseinander. Das Fenster ab dem
        // Gruppenersten (fünf Minuten) endet vor dem dritten Ereignis.
        $groups = TimelineGrouper::group([
            $this->event('viewed', '2026-10-27 11:23:00', 7),
            $this->event('comment', '2026-10-27 11:27:00', 7),
            $this->event('viewed', '2026-10-27 11:31:00', 7),
        ]);

        $this->assertCount(2, $groups);
        $this->assertCount(2, $groups[0]);
        $this->assertCount(1, $groups[1]);
    }

    public function testDifferentUsersAreNotGrouped(): void
    {
        $groups = TimelineGrouper::group([
            $this->event('viewed', '2026-10-27 11:23:00', 7),
            $this->event('comment', '2026-10-27 11:23:10', 8),
        ]);

        $this->assertCount(2, $groups);
    }

    public function testEventsWithoutUserAreGroupedByName(): void
    {
        $groups = TimelineGrouper::group([
            ['type' => 'sla_warning', 'user_id' => null, 'user_name' => 'system', 'created_at' => '2026-10-27 11:23:00'],
            ['type' => 'escalated', 'user_id' => null, 'user_name' => 'system', 'created_at' => '2026-10-27 11:23:05'],
        ]);

        $this->assertCount(1, $groups);
        $this->assertCount(2, $groups[0]);
    }

    public function testEventsWithoutReadableTimestampStayAlone(): void
    {
        $groups = TimelineGrouper::group([
            ['type' => 'viewed', 'user_id' => 7, 'user_name' => 'A', 'created_at' => null],
            ['type' => 'viewed', 'user_id' => 7, 'user_name' => 'A', 'created_at' => null],
        ]);

        $this->assertCount(2, $groups);
    }

    public function testCustomWindowAndEmptyInput(): void
    {
        $events = [
            $this->event('viewed', '2026-10-27 11:23:00', 7),
            $this->event('comment', '2026-10-27 11:30:00', 7),
        ];

        $this->assertCount(2, TimelineGrouper::group($events));
        $this->assertCount(1, TimelineGrouper::group($events, 10));
        $this->assertSame([], TimelineGrouper::group([]));
    }

    /** @return array<string,mixed> */
    private function event(string $type, string $createdAt, ?int $userId): array
    {
        return ['type' => $type, 'user_id' => $userId, 'user_name' => 'Reinelt, Daniel-André', 'created_at' => $createdAt];
    }
}
