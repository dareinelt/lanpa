<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\LocationService;
use Tests\Support\TestCase;

final class LocationTreeTest extends TestCase
{
    /** @return array<int,array<string,mixed>> */
    private function rows(): array
    {
        $mk = static fn (int $id, ?int $parent, string $name, int $sort = 0): array => ['id' => $id, 'parent_id' => $parent, 'name' => $name, 'sort_order' => $sort, 'is_active' => 1];

        return [
            $mk(4, 3, 'Raum 2.15', 1),
            $mk(1, null, 'Peine'),
            $mk(5, 3, 'Raum 2.14', 0),
            $mk(2, 1, 'Gebäude A'),
            $mk(3, 2, '2. OG'),
            $mk(6, null, 'Berlin'),
            $mk(7, 99, 'Verwaist'),
        ];
    }

    public function testBuildTreeNestsChildrenAndSorts(): void
    {
        $tree = LocationService::buildTree($this->rows());
        $this->assertCount(3, $tree, 'Zwei Wurzeln plus verwaister Knoten');
        $this->assertSame('Berlin', $tree[0]['name']);
        $this->assertSame('Peine', $tree[1]['name']);
        $building = $tree[1]['children'][0];
        $this->assertSame('Gebäude A', $building['name']);
        $floor = $building['children'][0];
        $this->assertSame('2. OG', $floor['name']);
        $this->assertSame(['Raum 2.14', 'Raum 2.15'], array_column($floor['children'], 'name'));
    }

    public function testFlattenPreservesDepthAndOrder(): void
    {
        $flat = LocationService::flatten($this->rows());
        $names = array_column($flat, 'name');
        $this->assertSame(['Berlin', 'Peine', 'Gebäude A', '2. OG', 'Raum 2.14', 'Raum 2.15', 'Verwaist'], $names);
        $depths = array_combine($names, array_column($flat, 'depth'));
        $this->assertSame(0, $depths['Peine']);
        $this->assertSame(1, $depths['Gebäude A']);
        $this->assertSame(3, $depths['Raum 2.14']);
        foreach ($flat as $row) {
            $this->assertFalse(array_key_exists('children', $row));
        }
    }
}
