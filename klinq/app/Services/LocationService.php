<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\ValidationException;
use App\Repositories\LocationRepository;
use App\Support\Validator;

/** Hierarchische Standorte: Pfadpflege, Zyklusschutz, Baumaufbau. */
final class LocationService
{
    public const SEPARATOR = ' / ';

    public function __construct(
        private readonly LocationRepository $locations,
        private readonly AuditLogService $audit
    ) {}

    /**
     * Baut aus der flachen Liste einen Baum (children-Schlüssel).
     * @param array<int,array<string,mixed>> $rows
     * @return array<int,array<string,mixed>>
     */
    public static function buildTree(array $rows): array
    {
        $byId = [];
        foreach ($rows as $row) {
            $row['children'] = [];
            $byId[(int) $row['id']] = $row;
        }
        $roots = [];
        foreach ($byId as $id => &$node) {
            $parent = $node['parent_id'] !== null ? (int) $node['parent_id'] : null;
            if ($parent !== null && isset($byId[$parent])) {
                $byId[$parent]['children'][] = &$node;
            } else {
                $roots[] = &$node;
            }
        }
        unset($node);
        $sort = static function (array &$nodes) use (&$sort): void {
            usort($nodes, static fn (array $a, array $b): int => [$a['sort_order'], $a['name']] <=> [$b['sort_order'], $b['name']]);
            foreach ($nodes as &$n) {
                $sort($n['children']);
            }
        };
        $sort($roots);

        return $roots;
    }

    /**
     * Flache Liste in Baumreihenfolge mit Einrückungstiefe (für Select-Felder).
     * @param array<int,array<string,mixed>> $rows
     * @return array<int,array<string,mixed>>
     */
    public static function flatten(array $rows): array
    {
        $out = [];
        $walk = static function (array $nodes, int $depth) use (&$walk, &$out): void {
            foreach ($nodes as $node) {
                $children = $node['children'];
                unset($node['children']);
                $node['depth'] = $depth;
                $out[] = $node;
                $walk($children, $depth + 1);
            }
        };
        $walk(self::buildTree($rows), 0);

        return $out;
    }

    /** @param array<string,mixed> $input */
    public function create(array $input): int
    {
        $data = $this->validate($input, null);
        $parent = $data['parent_id'] !== null ? $this->locations->find((int) $data['parent_id']) : null;
        if ($data['parent_id'] !== null && $parent === null) {
            throw ValidationException::single('parent_id', 'Übergeordneter Standort nicht gefunden.');
        }
        $data['depth'] = $parent === null ? 0 : (int) $parent['depth'] + 1;
        $data['full_path'] = $parent === null ? $data['name'] : $parent['full_path'] . self::SEPARATOR . $data['name'];

        $id = $this->locations->create($data);
        $this->audit->log('create', 'location', $id, $data['full_path'], null, $data);

        return $id;
    }

    /**
     * @param array<string,mixed> $existing
     * @param array<string,mixed> $input
     */
    public function update(int $id, array $existing, array $input): void
    {
        $data = $this->validate($input, $id);
        $newParentId = $data['parent_id'] !== null ? (int) $data['parent_id'] : null;

        if ($newParentId === $id) {
            throw ValidationException::single('parent_id', 'Ein Standort kann nicht sich selbst untergeordnet werden.');
        }
        if ($newParentId !== null && in_array($newParentId, $this->locations->descendantIds($id), true)) {
            throw ValidationException::single('parent_id', 'Ein Standort kann nicht einem eigenen Unterstandort zugeordnet werden.');
        }
        $parent = $newParentId !== null ? $this->locations->find($newParentId) : null;
        if ($newParentId !== null && $parent === null) {
            throw ValidationException::single('parent_id', 'Übergeordneter Standort nicht gefunden.');
        }

        $this->locations->transaction(function () use ($id, $data, $parent, $existing): void {
            $this->locations->update($id, $data);
            $depth = $parent === null ? 0 : (int) $parent['depth'] + 1;
            $path = $parent === null ? $data['name'] : $parent['full_path'] . self::SEPARATOR . $data['name'];
            $this->locations->updatePath($id, $path, $depth);
            $this->recomputeChildren($id, $path, $depth);
            $this->audit->log('update', 'location', $id, $path, $existing, array_merge($data, ['full_path' => $path]));
        });
    }

    /**
     * Verschiebt einen Standort (Drag & Drop im Baum): neuer übergeordneter Standort und
     * Reihenfolge der Geschwister. Nicht genannte Geschwister (z. B. ausgeblendete inaktive)
     * folgen in ihrer bisherigen Reihenfolge.
     *
     * @param array<string,mixed> $existing
     * @param list<int> $order IDs der Geschwister in neuer Reihenfolge (inklusive des verschobenen Standorts)
     * @return array<string,mixed> Aktualisierter Datensatz
     */
    public function move(int $id, array $existing, ?int $newParentId, array $order): array
    {
        if ($newParentId === $id) {
            throw ValidationException::single('parent_id', 'Ein Standort kann nicht sich selbst untergeordnet werden.');
        }
        if ($newParentId !== null && in_array($newParentId, $this->locations->descendantIds($id), true)) {
            throw ValidationException::single('parent_id', 'Ein Standort kann nicht einem eigenen Unterstandort zugeordnet werden.');
        }
        $parent = $newParentId !== null ? $this->locations->find($newParentId) : null;
        if ($newParentId !== null && $parent === null) {
            throw ValidationException::single('parent_id', 'Übergeordneter Standort nicht gefunden.');
        }
        $oldParentId = $existing['parent_id'] !== null ? (int) $existing['parent_id'] : null;
        $parentChanged = $oldParentId !== $newParentId;
        if ($parentChanged && $this->locations->findSibling($newParentId, (string) $existing['name'], $id) !== null) {
            throw ValidationException::single('name', 'Unter dem Ziel existiert bereits ein Standort mit dem Namen „' . $existing['name'] . '“.');
        }

        $this->locations->transaction(function () use ($id, $existing, $parent, $newParentId, $parentChanged, $order): void {
            $path = (string) $existing['full_path'];
            if ($parentChanged) {
                $this->locations->update($id, ['parent_id' => $newParentId]);
                $depth = $parent === null ? 0 : (int) $parent['depth'] + 1;
                $path = $parent === null ? (string) $existing['name'] : $parent['full_path'] . self::SEPARATOR . $existing['name'];
                $this->locations->updatePath($id, $path, $depth);
                $this->recomputeChildren($id, $path, $depth);
            }

            $siblings = $this->locations->children($newParentId);
            $siblingIds = array_map(static fn (array $s): int => (int) $s['id'], $siblings);
            $sorted = [];
            foreach ($order as $siblingId) {
                if (in_array($siblingId, $siblingIds, true) && !in_array($siblingId, $sorted, true)) {
                    $sorted[] = $siblingId;
                }
            }
            if (!in_array($id, $sorted, true)) {
                $sorted[] = $id;
            }
            foreach ($siblingIds as $siblingId) {
                if (!in_array($siblingId, $sorted, true)) {
                    $sorted[] = $siblingId;
                }
            }
            $current = array_column($siblings, 'sort_order', 'id');
            foreach ($sorted as $index => $siblingId) {
                $sortOrder = ($index + 1) * 10;
                if ((int) ($current[$siblingId] ?? -1) !== $sortOrder) {
                    $this->locations->update($siblingId, ['sort_order' => $sortOrder]);
                }
            }

            $this->audit->log(
                'move',
                'location',
                $id,
                $path,
                ['parent_id' => $existing['parent_id'], 'full_path' => $existing['full_path'], 'sort_order' => $existing['sort_order']],
                ['parent_id' => $newParentId, 'full_path' => $path, 'sort_order' => (array_search($id, $sorted, true) + 1) * 10]
            );
        });

        return $this->locations->find($id) ?? $existing;
    }

    /** @param array<string,mixed> $existing */
    public function setActive(int $id, array $existing, bool $active): void
    {
        $this->locations->transaction(function () use ($id, $existing, $active): void {
            $this->locations->update($id, ['is_active' => $active ? 1 : 0]);
            if (!$active) {
                foreach ($this->locations->descendantIds($id) as $childId) {
                    $this->locations->update($childId, ['is_active' => 0]);
                }
            }
            $this->audit->log($active ? 'activate' : 'deactivate', 'location', $id, (string) $existing['full_path'], ['is_active' => $existing['is_active']], ['is_active' => $active ? 1 : 0]);
        });
    }

    private function recomputeChildren(int $parentId, string $parentPath, int $parentDepth): void
    {
        foreach ($this->locations->children($parentId) as $child) {
            $path = $parentPath . self::SEPARATOR . $child['name'];
            $this->locations->updatePath((int) $child['id'], $path, $parentDepth + 1);
            $this->recomputeChildren((int) $child['id'], $path, $parentDepth + 1);
        }
    }

    /**
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    private function validate(array $input, ?int $excludeId): array
    {
        $data = (new Validator($input))
            ->string('name', 'Name', true, 150)
            ->in('type', 'Typ', array_keys(LocationRepository::TYPES), true)
            ->string('code', 'Kürzel', false, 50)
            ->id('parent_id', 'Übergeordneter Standort')
            ->int('sort_order', 'Sortierung', false, -9999, 9999)
            ->bool('is_active')
            ->validated();
        $data['sort_order'] ??= 0;

        if (str_contains($data['name'], trim(self::SEPARATOR))) {
            throw ValidationException::single('name', 'Der Name darf kein „/“ enthalten.');
        }
        $sibling = $this->locations->findSibling($data['parent_id'] !== null ? (int) $data['parent_id'] : null, $data['name'], $excludeId);
        if ($sibling !== null) {
            throw ValidationException::single('name', 'Auf dieser Ebene existiert bereits ein Standort mit diesem Namen.');
        }

        return $data;
    }
}
