<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\ArticleRepository;
use App\Repositories\AssetRepository;
use App\Repositories\CostCenterRepository;
use App\Repositories\EmployeeRepository;
use App\Repositories\LocationRepository;
use App\Repositories\ManufacturerRepository;
use App\Repositories\SupplierRepository;
use App\Repositories\TicketRepository;
use App\Security\CurrentUser;

/**
 * Globale Suche über Inventarnummer, Seriennummer, Hersteller, Artikel, Mitarbeiter,
 * Kostenstelle, Standort, Lieferant, MAC-Adresse, IMEI und Help-Desk-Tickets. Rechte werden pro Gruppe geprüft.
 */
final class SearchService
{
    public function __construct(
        private readonly AssetRepository $assets,
        private readonly EmployeeRepository $employees,
        private readonly LocationRepository $locations,
        private readonly CostCenterRepository $costCenters,
        private readonly ManufacturerRepository $manufacturers,
        private readonly ArticleRepository $articles,
        private readonly SupplierRepository $suppliers,
        private readonly CurrentUser $user,
        private readonly ?TicketRepository $tickets = null,
        private readonly bool $helpdeskEnabled = true
    ) {}

    /**
     * @return array<int,array{key:string,label:string,url:string,items:array<int,array{title:string,meta:string,url:string}>}>
     */
    public function search(string $term, int $perGroup = 5): array
    {
        $term = trim($term);
        if (mb_strlen($term) < 2) {
            return [];
        }
        $groups = [];

        if ($this->tickets !== null && $this->helpdeskEnabled && ($this->user->can('helpdesk.view') || $this->user->can('portal.view'))) {
            $agent = $this->user->can('helpdesk.view');
            $listUrl = $agent ? '/helpdesk/tickets?view=all&q=' : '/portal/tickets?q=';
            $groups[] = $this->group('tickets', 'Tickets', $listUrl . rawurlencode($term), array_map(static fn (array $t): array => [
                'title' => $t['number'] . ' · ' . $t['subject'],
                'meta' => implode(' · ', array_filter([$t['status_name'], $t['priority_name'], $t['requester_name'] ?? null, $t['assignee_name'] ?? null])),
                'url' => ($agent ? '/helpdesk/tickets/' : '/portal/tickets/') . (int) $t['id'],
            ], $this->tickets->quickSearch($term, $perGroup, $agent ? null : $this->user->id())));
        }

        if ($this->user->can('assets.view')) {
            $groups[] = $this->group('assets', 'Assets', '/assets?status=all&q=' . rawurlencode($term), array_map(static fn (array $a): array => [
                'title' => $a['inventory_number'] . ' · ' . ($a['name'] ?: ($a['article_name'] ?? $a['asset_type_name'])),
                'meta' => implode(' · ', array_filter([$a['manufacturer_name'], $a['serial_number'] ? 'SN ' . $a['serial_number'] : null, $a['status_name'], $a['employee_name'], $a['location_path']])),
                'url' => '/assets/' . (int) $a['id'],
            ], $this->assets->quickSearch($term, $perGroup)));
        }
        if ($this->user->can('employees.view')) {
            $groups[] = $this->group('employees', 'Mitarbeiter', '/employees?active=&q=' . rawurlencode($term), array_map(static fn (array $e): array => [
                'title' => $e['display_name'],
                'meta' => implode(' · ', array_filter([$e['username'], $e['personnel_number'], $e['department'], (int) $e['is_active'] ? null : 'inaktiv'])),
                'url' => '/employees/' . (int) $e['id'],
            ], $this->employees->search(['q' => $term, 'active' => ''], $perGroup, 0)));
        }
        if ($this->user->can('locations.view')) {
            $groups[] = $this->group('locations', 'Standorte', '/locations?q=' . rawurlencode($term), array_map(static fn (array $l): array => [
                'title' => $l['name'],
                'meta' => $l['full_path'],
                'url' => '/locations/' . (int) $l['id'],
            ], $this->locations->search($term, $perGroup)));
        }
        if ($this->user->can('costcenters.view')) {
            $groups[] = $this->group('costcenters', 'Kostenstellen', '/cost-centers?active=&q=' . rawurlencode($term), array_map(static fn (array $c): array => [
                'title' => $c['number'] . ' – ' . $c['description'],
                'meta' => (string) ($c['location_path'] ?? ''),
                'url' => '/assets?status=all&cost_center_id=' . (int) $c['id'],
            ], $this->costCenters->search(['q' => $term, 'active' => ''], $perGroup, 0)));
        }
        if ($this->user->can('manufacturers.view')) {
            $groups[] = $this->group('manufacturers', 'Hersteller', '/manufacturers?active=&q=' . rawurlencode($term), array_map(static fn (array $m): array => [
                'title' => $m['name'],
                'meta' => ((int) ($m['article_count'] ?? 0)) . ' Artikel',
                'url' => '/articles?active=&manufacturer_id=' . (int) $m['id'],
            ], $this->manufacturers->search(['q' => $term, 'active' => ''], $perGroup, 0)));
        }
        if ($this->user->can('articles.view')) {
            $groups[] = $this->group('articles', 'Artikel', '/articles?active=&q=' . rawurlencode($term), array_map(static fn (array $a): array => [
                'title' => $a['manufacturer_name'] . ' ' . $a['name'],
                'meta' => implode(' · ', array_filter([$a['article_number'], $a['asset_type_name'], ((int) $a['asset_count']) . ' Assets'])),
                'url' => '/assets?status=all&article_id=' . (int) $a['id'],
            ], $this->articles->search(['q' => $term, 'active' => ''], $perGroup, 0)));
        }
        if ($this->user->can('suppliers.view')) {
            $groups[] = $this->group('suppliers', 'Lieferanten', '/suppliers?active=&q=' . rawurlencode($term), array_map(static fn (array $s): array => [
                'title' => $s['name'],
                'meta' => implode(' · ', array_filter([$s['city'] ?? null, $s['email'] ?? null])),
                'url' => '/suppliers/' . (int) $s['id'] . '/edit',
            ], $this->suppliers->search(['q' => $term, 'active' => ''], $perGroup, 0)));
        }

        return $groups;
    }

    /** Exakter Treffer auf eine Inventarnummer → Direktziel (für Enter in der Suche/Scanner). */
    public function resolveDirect(string $term): ?string
    {
        $asset = $this->assets->findByInventoryNumber(trim($term));

        return $asset !== null ? '/assets/' . (int) $asset['id'] : null;
    }

    /** @param array<int,array{title:string,meta:string,url:string}> $items @return array{key:string,label:string,url:string,items:array<int,array{title:string,meta:string,url:string}>} */
    private function group(string $key, string $label, string $url, array $items): array
    {
        return ['key' => $key, 'label' => $label, 'url' => $url, 'items' => $items];
    }
}
