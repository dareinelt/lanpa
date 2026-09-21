<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\ValidationException;
use App\Repositories\AlarmGroupRepository;
use App\Repositories\NavigationRepository;
use App\Support\Sanitizer;
use App\Support\Validator;

final class NavigationService
{
    public function __construct(
        private readonly NavigationRepository $repository,
        private readonly ?AlarmGroupRepository $alarmGroups = null
    ) {
    }

    /**
     * Aktive Navigationselemente der obersten Ebene (Landingpage).
     *
     * @return list<array<string,mixed>>
     */
    public function activeTopLevel(): array
    {
        return $this->repository->activeTopLevel();
    }

    /**
     * Aktive Unterseiten eines Elements.
     *
     * @return list<array<string,mixed>>
     */
    public function activeChildren(int $parentId): array
    {
        return $this->repository->activeChildren($parentId);
    }

    /**
     * Alle Elemente (auch inaktive), fuer die Admin-Liste.
     *
     * @return list<array<string,mixed>>
     */
    public function allItems(): array
    {
        return $this->repository->all();
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function subpages(): array
    {
        return $this->repository->subpages();
    }

    /**
     * @return array<string,mixed>|null
     */
    public function find(int $id): ?array
    {
        return $this->repository->find($id);
    }

    /**
     * Findet ein aktives Element (fuer oeffentliche Seiten).
     *
     * @return array<string,mixed>|null
     */
    public function findActive(int $id): ?array
    {
        $item = $this->repository->find($id);

        return $item !== null && (int) $item['active'] === 1 ? $item : null;
    }

    /**
     * Liefert den Breadcrumb-Pfad (Elternkette) fuer ein Element.
     *
     * @return list<array<string,mixed>>
     */
    public function breadcrumb(int $id): array
    {
        $chain = [];
        $visited = [];
        $current = $this->repository->find($id);

        while ($current !== null && !in_array((int) $current['id'], $visited, true)) {
            $visited[] = (int) $current['id'];
            array_unshift($chain, $current);

            $parentId = $current['parent_id'];
            $current = $parentId === null ? null : $this->repository->find((int) $parentId);
        }

        return $chain;
    }

    /**
     * @param array<string,mixed> $input
     *
     * @return array<string,mixed> validierte Daten
     */
    public function validate(array $input, bool $isNew, ?int $id = null): array
    {
        $errors = [];

        $title = Validator::cleanText((string) ($input['title'] ?? ''), 120);
        if (!Validator::isNotEmpty($title, 120)) {
            $errors['title'] = 'Bitte einen Titel angeben (max. 120 Zeichen).';
        }

        $type = (string) ($input['type'] ?? 'external');
        if (!Validator::isNavigationType($type)) {
            $errors['type'] = 'Ungültiger Typ.';
        }

        $url = '';
        $content = null;
        $parentId = null;
        $alarmText = null;
        $alarmGroupId = null;

        if ($type === 'external' || $type === 'internal') {
            $url = trim((string) ($input['url'] ?? ''));
            if (!Validator::isSafeUrl($url)) {
                $errors['url'] = 'Bitte eine gültige http(s)-URL oder einen internen Pfad (/telefonliste) angeben.';
            } elseif ($type === 'internal' && !str_starts_with($url, '/')) {
                $errors['url'] = 'Interne Elemente benötigen einen anwendungsinternen Pfad, z. B. /telefonliste.';
            }
        } else {
            // subpage / page / alarm: verschachtelbar; page enthält formatierten Rich-Text.
            $parentId = $this->resolveParentId($input['parent_id'] ?? null);
            if ($parentId !== null && !$this->isValidParent($parentId, $id)) {
                $errors['parent_id'] = 'Die übergeordnete Ebene ist ungültig oder würde eine Schleife erzeugen.';
            }

            if ($type === 'alarm') {
                $alarmText = Validator::cleanText((string) ($input['alarm_text'] ?? ''), 255);
                if (!Validator::isNotEmpty($alarmText, 255)) {
                    $errors['alarm_text'] = 'Bitte einen Alarmierungstext angeben (max. 255 Zeichen).';
                }

                $alarmGroupId = $this->resolveParentId($input['alarm_group_id'] ?? null);
                if ($alarmGroupId === null) {
                    $errors['alarm_group_id'] = 'Bitte eine Gruppe auswählen.';
                } elseif ($this->alarmGroups !== null && $this->alarmGroups->find($alarmGroupId) === null) {
                    $errors['alarm_group_id'] = 'Die gewählte Gruppe ist ungültig.';
                }
            } elseif ($type === 'page') {
                $content = Sanitizer::html((string) ($input['content'] ?? ''));
            }
        }

        $icon = Validator::cleanText((string) ($input['icon'] ?? ''), 32);
        if ($icon !== '' && preg_match('/^[a-z0-9-]{1,32}$/', $icon) !== 1) {
            $errors['icon'] = 'Icon-Schlüssel dürfen nur Kleinbuchstaben, Ziffern und Bindestriche enthalten.';
        }

        $overrideBackground = !empty($input['override_background']);
        $backgroundColor = null;
        $backgroundOpacity = null;

        if ($overrideBackground) {
            $backgroundColor = (string) ($input['background_color'] ?? '');
            if ($backgroundColor !== '' && !Validator::isHexColor($backgroundColor)) {
                $errors['background_color'] = 'Bitte einen gültigen Hex-Farbwert angeben (z. B. #1f4e79).';
            }

            $backgroundOpacity = $input['background_opacity'] ?? null;
            if ($backgroundOpacity !== null && $backgroundOpacity !== '' && !Validator::isPercentage((string) $backgroundOpacity)) {
                $errors['background_opacity'] = 'Bitte einen Wert zwischen 0 und 100 angeben.';
            }
        }

        $shortDescription = Validator::cleanText((string) ($input['short_description'] ?? ''), 255);
        $description = Validator::cleanText((string) ($input['description'] ?? ''), 5000);

        $sortOrder = (int) ($input['sort_order'] ?? 0);
        if ($sortOrder < 1) {
            $sortOrder = $isNew ? $this->repository->nextSortOrder($parentId) : 1;
        }
        if ($sortOrder > 9999) {
            $errors['sort_order'] = 'Sortierung muss zwischen 1 und 9999 liegen.';
        }

        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        return [
            'title' => $title,
            'url' => $url,
            'type' => $type,
            'parent_id' => $parentId,
            'content' => $content,
            'alarm_text' => $alarmText,
            'alarm_group_id' => $alarmGroupId,
            'icon' => $icon === '' ? null : $icon,
            'override_background' => $overrideBackground,
            'background_color' => $backgroundColor === '' ? null : $backgroundColor,
            'background_opacity' => $backgroundOpacity === null || $backgroundOpacity === '' ? null : (int) $backgroundOpacity,
            'short_description' => $shortDescription,
            'description' => $description,
            'sort_order' => $sortOrder,
            'active' => !empty($input['active']),
            'protected_access' => !empty($input['protected_access']),
        ];
    }

    private function resolveParentId(mixed $value): ?int
    {
        if ($value === null || $value === '' || $value === '0' || $value === 0) {
            return null;
        }

        $id = (int) $value;

        return $id > 0 ? $id : null;
    }

    private function isValidParent(int $parentId, ?int $currentId): bool
    {
        $parent = $this->repository->find($parentId);
        if ($parent === null || $parent['type'] !== 'subpage') {
            return false;
        }

        if ($currentId !== null) {
            if ($parentId === $currentId || in_array($parentId, $this->repository->descendantIds($currentId), true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<string,mixed> $input
     */
    public function create(array $input): int
    {
        return $this->repository->create($this->validate($input, true));
    }

    /**
     * @param array<string,mixed> $input
     */
    public function update(int $id, array $input): void
    {
        if ($this->repository->find($id) === null) {
            throw new ValidationException(['id' => 'Element nicht gefunden.']);
        }

        $this->repository->update($id, $this->validate($input, false, $id));
    }

    public function delete(int $id): void
    {
        $this->repository->delete($id);
    }

    public function toggle(int $id): void
    {
        $item = $this->repository->find($id);
        if ($item === null) {
            throw new ValidationException(['id' => 'Element nicht gefunden.']);
        }

        $this->repository->setActive($id, !(bool) $item['active']);
    }

    public function move(int $id, string $direction): bool
    {
        if (!in_array($direction, ['up', 'down'], true)) {
            return false;
        }

        return $this->repository->move($id, $direction);
    }
}
