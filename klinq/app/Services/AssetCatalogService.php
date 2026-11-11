<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\ValidationException;
use App\Repositories\AssetTypeRepository;
use App\Support\Validator;

/**
 * Pflege der Kataloge der Assetverwaltung („Auswertung & System → Kataloge“):
 * Assettypen und Kategorien. Einträge werden nicht gelöscht, sondern deaktiviert –
 * deaktivierte Einträge stehen bei neuen Datensätzen nicht mehr zur Auswahl.
 */
final class AssetCatalogService
{
    /** Auswählbare Symbole für Assettypen (Teilmenge des Icon-Sprites). */
    public const ICONS = [
        'laptop' => 'Notebook / PC',
        'phone' => 'Mobilgerät',
        'network' => 'Netzwerk',
        'box' => 'Box',
        'print' => 'Drucker',
        'camera' => 'Kamera',
        'keyboard' => 'Tastatur',
        'key' => 'Schlüssel',
        'layers' => 'Ebenen',
        'tag' => 'Etikett',
    ];

    public function __construct(
        private readonly AssetTypeRepository $types,
        private readonly AuditLogService $audit
    ) {}

    /**
     * Assettyp anlegen (null) oder bearbeiten.
     *
     * @param array<string,mixed> $input
     */
    public function saveType(?int $id, array $input): int
    {
        $existing = $id !== null ? $this->types->find($id) : null;
        if ($id !== null && $existing === null) {
            throw ValidationException::single('name', 'Assettyp nicht gefunden.');
        }

        $input['code'] = strtoupper(trim((string) ($input['code'] ?? '')));
        $input['inventory_prefix'] = strtoupper(trim((string) ($input['inventory_prefix'] ?? '')));
        $data = (new Validator($input))
            ->pattern('code', 'Code', '/^[A-Z0-9_-]{1,20}$/', 'Der Code darf nur aus Großbuchstaben, Ziffern, „-“ und „_“ bestehen (höchstens 20 Zeichen).', true)
            ->string('name', 'Bezeichnung', true, 100)
            ->pattern('inventory_prefix', 'Inventarnummern-Präfix', '/^[A-Z]{1,6}$/', 'Das Präfix muss aus 1 bis 6 Buchstaben (A–Z) bestehen.', true)
            ->bool('has_serial_number')
            ->bool('has_mac_address')
            ->bool('has_imei')
            ->bool('supports_licenses')
            ->in('icon', 'Symbol', array_keys(self::ICONS), true)
            ->int('sort_order', 'Sortierung', false, 0, 100000)
            ->bool('is_active')
            ->validated();
        $data['sort_order'] ??= 0;

        $byCode = $this->types->findByCode($data['code']);
        if ($byCode !== null && (int) $byCode['id'] !== $id) {
            throw ValidationException::single('code', 'Dieser Code ist bereits vergeben.');
        }
        $byPrefix = $this->types->findByPrefix($data['inventory_prefix']);
        if ($byPrefix !== null && (int) $byPrefix['id'] !== $id) {
            throw ValidationException::single('inventory_prefix', 'Dieses Präfix wird bereits vom Assettyp „' . $byPrefix['name'] . '“ verwendet.');
        }
        // Inventarnummern vorhandener Assets beginnen mit dem Präfix – danach nicht mehr änderbar.
        if ($existing !== null && $data['inventory_prefix'] !== (string) $existing['inventory_prefix'] && $this->types->assetCount((int) $id) > 0) {
            throw ValidationException::single('inventory_prefix', 'Das Präfix kann nicht mehr geändert werden, weil bereits Assets mit diesem Assettyp existieren.');
        }

        if ($existing === null) {
            $newId = $this->types->create($data);
            $this->audit->log('create', 'asset_type', $newId, $data['name'], null, $data);

            return $newId;
        }

        $this->types->update((int) $id, $data);
        $this->audit->log('update', 'asset_type', (int) $id, $data['name'], $this->only($existing, $data), $data);

        return (int) $id;
    }

    /** @param array<string,mixed> $existing */
    public function setTypeActive(int $id, array $existing, bool $active): void
    {
        $this->types->update($id, ['is_active' => $active ? 1 : 0]);
        $this->audit->log($active ? 'activate' : 'deactivate', 'asset_type', $id, (string) $existing['name'], ['is_active' => $existing['is_active']], ['is_active' => $active ? 1 : 0]);
    }

    /**
     * Kategorie anlegen (null) oder bearbeiten.
     *
     * @param array<string,mixed> $input
     */
    public function saveCategory(?int $id, array $input): int
    {
        $existing = $id !== null ? $this->types->findCategory($id) : null;
        if ($id !== null && $existing === null) {
            throw ValidationException::single('name', 'Kategorie nicht gefunden.');
        }

        $data = (new Validator($input))
            ->id('asset_type_id', 'Assettyp', true)
            ->string('name', 'Bezeichnung', true, 100)
            ->int('sort_order', 'Sortierung', false, 0, 100000)
            ->bool('is_active')
            ->validated();
        $data['sort_order'] ??= 0;

        $type = $this->types->find((int) $data['asset_type_id']);
        if ($type === null) {
            throw ValidationException::single('asset_type_id', 'Assettyp nicht gefunden.');
        }
        // Assets und Artikel müssen zum Assettyp ihrer Kategorie passen.
        if ($existing !== null && (int) $existing['asset_type_id'] !== (int) $data['asset_type_id'] && $this->types->categoryUsage((int) $id) > 0) {
            throw ValidationException::single('asset_type_id', 'Der Assettyp kann nicht mehr geändert werden, weil die Kategorie bereits von Assets oder Artikeln verwendet wird.');
        }
        $duplicate = $this->types->findCategoryByName((int) $data['asset_type_id'], $data['name']);
        if ($duplicate !== null && (int) $duplicate['id'] !== $id) {
            throw ValidationException::single('name', 'Diese Kategorie gibt es beim Assettyp „' . $type['name'] . '“ bereits.');
        }

        if ($existing === null) {
            $newId = $this->types->createCategory($data);
            $this->audit->log('create', 'asset_category', $newId, $type['name'] . ' / ' . $data['name'], null, $data);

            return $newId;
        }

        $this->types->updateCategory((int) $id, $data);
        $this->audit->log('update', 'asset_category', (int) $id, $type['name'] . ' / ' . $data['name'], $this->only($existing, $data), $data);

        return (int) $id;
    }

    /** @param array<string,mixed> $existing */
    public function setCategoryActive(int $id, array $existing, bool $active): void
    {
        $this->types->updateCategory($id, ['is_active' => $active ? 1 : 0]);
        $this->audit->log($active ? 'activate' : 'deactivate', 'asset_category', $id, (string) $existing['name'], ['is_active' => $existing['is_active']], ['is_active' => $active ? 1 : 0]);
    }

    /**
     * Alte Werte auf die gespeicherten Felder beschränken (übersichtliches Audit-Log).
     *
     * @param array<string,mixed> $existing
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    private function only(array $existing, array $data): array
    {
        return array_intersect_key($existing, $data);
    }
}
