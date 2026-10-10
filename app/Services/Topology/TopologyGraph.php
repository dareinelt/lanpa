<?php

declare(strict_types=1);

namespace App\Services\Topology;

use InvalidArgumentException;

/**
 * Aufbau und Pruefung des fachlichen Topologiegraphen.
 *
 * Der Graph kennt drei Ebenen:
 * - Gruppen: die fachliche Hierarchie (Kern, Module, optionale Zusaetze). Eine
 *   Gruppe hat hoechstens eine Elterngruppe; die Hierarchie muss zyklenfrei sein.
 * - Knoten: die Bausteine selbst. Jeder Knoten gehoert genau einer Gruppe an.
 * - Kanten: gerichtete Beziehungen zwischen zwei verschiedenen Knoten.
 *
 * Alle Kennungen sind stabil und werden aus fachlichen Schluesseln gebildet
 * (z. B. "service:nextcloud", "tier:3"), nicht aus Positionen oder Laufzeitwerten.
 * {@see self::validate()} prueft die im Auftrag genannten Regeln und liefert
 * Verstoesse als Liste zurueck, statt sie zu verschweigen.
 */
final class TopologyGraph
{
    /** Zulaessige Knotenarten (bestimmen Form und Farbe in der Oberflaeche). */
    public const KINDS = [
        'core',
        'users',
        'external',
        'web',
        'identity',
        'module',
        'service',
        'database',
        'cache',
        'storage',
        'volume',
        'backup',
        'snapshot',
        'monitor',
        'mail',
        'certificate',
        'network',
    ];

    /** Zulaessige Beziehungstypen. */
    public const EDGE_TYPES = [
        'depends',
        'routes',
        'stores',
        'backs_up',
        'restores',
        'monitors',
        'contains',
        'authenticates',
        'replicates',
    ];

    /** Herkunft einer Aussage. Bestimmt, wie belastbar sie ist. */
    public const EVIDENCE = ['proven', 'derived', 'suspected', 'unwatched'];

    /** @var array<string,array<string,mixed>> */
    private array $nodes = [];

    /** @var array<string,array<string,mixed>> */
    private array $edges = [];

    /** @var array<string,array<string,mixed>> */
    private array $groups = [];

    /**
     * Legt eine Gruppe an. Ohne Elterngruppe ist sie eine Wurzelgruppe.
     *
     * @param array<string,mixed> $extra zusaetzliche Felder (subtitle, module, optional, link)
     */
    public function group(string $id, string $title, ?string $parent = null, array $extra = []): void
    {
        $this->groups[$id] = array_merge([
            'id' => $id,
            'title' => $title,
            'subtitle' => '',
            'parent' => $parent,
            'module' => $id,
            'optional' => false,
            'link' => null,
            'nodes' => [],
            'state' => TopologyStatus::UNKNOWN,
            'state_label' => TopologyStatus::label(TopologyStatus::UNKNOWN),
        ], $extra, ['id' => $id, 'parent' => $parent]);
    }

    /**
     * Legt einen Knoten an. Doppelte Kennungen werden nicht ueberschrieben,
     * sondern bleiben als Verstoss in {@see self::validate()} sichtbar.
     *
     * @param array<string,mixed> $definition title, subtitle, kind, group, layer, state, message, …
     */
    public function node(string $id, array $definition): void
    {
        if (isset($this->nodes[$id])) {
            $this->nodes[$id]['__duplicate'] = true;

            return;
        }

        $definition['id'] = $id;
        $definition['__duplicate'] = false;
        // Ein Knoten ohne ausdruecklichen Zustand ist nicht gesund, sondern
        // unbekannt: fehlende Messungen duerfen nie als "ok" durchgehen.
        // Quellvokabeln der Module (z. B. "disabled") laufen ueber die
        // zentrale Abbildung, damit "nicht aktiviert" als abgeschaltet gilt.
        $definition['state'] = TopologyStatus::fromSource((string) ($definition['state'] ?? TopologyStatus::UNKNOWN));
        $this->nodes[$id] = $definition;
    }

    /**
     * Legt eine gerichtete Kante an. Ersetzt eine gleichnamige Kante nicht,
     * sondern markiert sie als Verstoss.
     *
     * @param array<string,mixed> $definition type, label, state, message, evidence, protocol
     */
    public function edge(string $id, string $from, string $to, array $definition = []): void
    {
        if (isset($this->edges[$id])) {
            $this->edges[$id]['__duplicate'] = true;

            return;
        }

        $this->edges[$id] = array_merge([
            'id' => $id,
            'from' => $from,
            'to' => $to,
            'type' => 'depends',
            'label' => '',
            'state' => TopologyStatus::UNKNOWN,
            'state_label' => TopologyStatus::label(TopologyStatus::UNKNOWN),
            'message' => '',
            'evidence' => 'suspected',
            'protocol' => '',
            'measured_at' => null,
        ], $definition, ['id' => $id, 'from' => $from, 'to' => $to, '__duplicate' => false]);
    }

    public function hasNode(string $id): bool
    {
        return isset($this->nodes[$id]);
    }

    public function hasEdge(string $id): bool
    {
        return isset($this->edges[$id]);
    }

    /** @return array<string,mixed>|null */
    public function nodeById(string $id): ?array
    {
        return $this->nodes[$id] ?? null;
    }

    public function stateOf(string $id): string
    {
        return (string) ($this->nodes[$id]['state'] ?? TopologyStatus::UNKNOWN);
    }

    /** Setzt den Zustand eines vorhandenen Knotens. */
    public function setState(string $id, string $state, string $message = ''): void
    {
        if (!isset($this->nodes[$id])) {
            throw new InvalidArgumentException(sprintf('Unbekannter Knoten "%s".', $id));
        }
        $this->nodes[$id]['state'] = TopologyStatus::fromSource($state);
        $this->nodes[$id]['state_label'] = TopologyStatus::label($this->nodes[$id]['state']);
        if ($message !== '') {
            $this->nodes[$id]['message'] = $message;
        }
    }

    /**
     * Kinder eines Knotens: Knoten, deren "parent" dieser Knoten ist.
     *
     * @return list<string>
     */
    public function childrenOf(string $id): array
    {
        $children = [];
        foreach ($this->nodes as $nodeId => $node) {
            if (($node['parent'] ?? null) === $id) {
                $children[] = $nodeId;
            }
        }

        return $children;
    }

    /**
     * Knoten, die von $id abhaengen: ausgehende Kanten ohne "contains"/"monitors"
     * gelten als fachliche Abhaengigkeit (Wirkungsrichtung).
     *
     * @return list<string>
     */
    public function dependenciesOf(string $id): array
    {
        $targets = [];
        foreach ($this->edges as $edge) {
            if ($edge['from'] !== $id) {
                continue;
            }
            if ($edge['type'] === 'contains' || $edge['type'] === 'monitors') {
                continue;
            }
            $targets[$edge['to']] = true;
        }

        return array_keys($targets);
    }

    /**
     * Knoten, die auf $id aufbauen (eingehende Abhaengigkeiten).
     *
     * @return list<string>
     */
    public function dependentsOf(string $id): array
    {
        $sources = [];
        foreach ($this->edges as $edge) {
            if ($edge['to'] !== $id) {
                continue;
            }
            if ($edge['type'] === 'contains' || $edge['type'] === 'monitors') {
                continue;
            }
            $sources[$edge['from']] = true;
        }

        return array_keys($sources);
    }

    /**
     * Nachbarn in beide Richtungen – Grundlage der Detailanzeige.
     *
     * @return list<string>
     */
    public function neighboursOf(string $id): array
    {
        $neighbours = [];
        foreach ($this->edges as $edge) {
            if ($edge['from'] === $id) {
                $neighbours[$edge['to']] = true;
            } elseif ($edge['to'] === $id) {
                $neighbours[$edge['from']] = true;
            }
        }
        unset($neighbours[$id]);

        return array_keys($neighbours);
    }

    /** @return array<string,array<string,mixed>> */
    public function nodes(): array
    {
        return $this->nodes;
    }

    /** @return array<string,array<string,mixed>> */
    public function edges(): array
    {
        return $this->edges;
    }

    /** @return array<string,array<string,mixed>> */
    public function groups(): array
    {
        return $this->groups;
    }

    /**
     * Prueft die im Auftrag geforderten Graphregeln.
     *
     * Geprueft werden: eindeutige Kennungen, vorhandene Kantenendpunkte, keine
     * Selbstkanten, gueltige Zustaende, gueltige Arten und Typen, keine
     * verwaisten Knoten, vorhandene Elterngruppen sowie eine zyklenfreie
     * Gruppenhierarchie. Fachliche Zyklen zwischen Knoten sind ausdruecklich
     * erlaubt (z. B. wechselseitige Abhaengigkeiten) und werden nicht geprueft.
     *
     * @return list<array{code:string,id:string,message:string}>
     */
    public function validate(): array
    {
        $issues = [];

        foreach ($this->nodes as $id => $node) {
            if (!empty($node['__duplicate'])) {
                $issues[] = self::issue('duplicate_id', $id, 'Die Knotenkennung ist mehrfach vergeben.');
            }
            if (!in_array((string) ($node['kind'] ?? ''), self::KINDS, true)) {
                $issues[] = self::issue('invalid_kind', $id, 'Unbekannte Knotenart "' . (string) ($node['kind'] ?? '') . '".');
            }
            if (!in_array((string) ($node['state'] ?? ''), TopologyStatus::STATES, true)) {
                $issues[] = self::issue('invalid_state', $id, 'Unbekannter Zustand "' . (string) ($node['state'] ?? '') . '".');
            }
            $group = (string) ($node['group'] ?? '');
            if (!isset($this->groups[$group])) {
                $issues[] = self::issue('unknown_group', $id, 'Die Gruppe "' . $group . '" ist nicht definiert.');
            }
            $parent = $node['parent'] ?? null;
            if ($parent !== null && !isset($this->nodes[$parent])) {
                $issues[] = self::issue('parent_missing', $id, 'Der Elternknoten "' . (string) $parent . '" fehlt.');
            }
            if ($parent === $id) {
                $issues[] = self::issue('self_parent', $id, 'Ein Knoten kann nicht sein eigener Elternknoten sein.');
            }
        }

        foreach ($this->edges as $id => $edge) {
            if (!empty($edge['__duplicate'])) {
                $issues[] = self::issue('duplicate_id', $id, 'Die Kantenkennung ist mehrfach vergeben.');
            }
            if (!in_array((string) $edge['type'], self::EDGE_TYPES, true)) {
                $issues[] = self::issue('invalid_type', $id, 'Unbekannter Beziehungstyp "' . (string) $edge['type'] . '".');
            }
            if (!in_array((string) $edge['state'], TopologyStatus::STATES, true)) {
                $issues[] = self::issue('invalid_state', $id, 'Unbekannter Zustand "' . (string) $edge['state'] . '".');
            }
            if (!in_array((string) ($edge['evidence'] ?? ''), self::EVIDENCE, true)) {
                $issues[] = self::issue('invalid_evidence', $id, 'Unbekannte Belegart "' . (string) ($edge['evidence'] ?? '') . '".');
            }
            if (!isset($this->nodes[(string) $edge['from']])) {
                $issues[] = self::issue('unknown_endpoint', $id, 'Der Startknoten "' . (string) $edge['from'] . '" fehlt.');
            }
            if (!isset($this->nodes[(string) $edge['to']])) {
                $issues[] = self::issue('unknown_endpoint', $id, 'Der Zielknoten "' . (string) $edge['to'] . '" fehlt.');
            }
            if ($edge['from'] === $edge['to']) {
                $issues[] = self::issue('self_edge', $id, 'Eine Kante darf keinen Knoten mit sich selbst verbinden.');
            }
        }

        foreach ($this->groups as $id => $group) {
            $parent = $group['parent'] ?? null;
            if ($parent !== null && !isset($this->groups[$parent])) {
                $issues[] = self::issue('parent_missing', $id, 'Die Elterngruppe "' . (string) $parent . '" fehlt.');
            }
        }
        $issues = array_merge($issues, $this->hierarchyIssues());

        // Verwaiste Knoten: kein Elternknoten, keine Kante und kein Kind.
        // Die zentrale Anwendung und die Nutzerseite sind bewusst Ausnahmen,
        // weil sie den Graphen an den beiden Enden aufspannen.
        $roots = ['core:lanpa', 'users:clients'];
        foreach ($this->nodes as $id => $node) {
            if (in_array($id, $roots, true)) {
                continue;
            }
            if (($node['parent'] ?? null) !== null || $this->childrenOf($id) !== []) {
                continue;
            }
            if ($this->neighboursOf($id) === []) {
                $issues[] = self::issue('orphan', $id, 'Der Knoten haengt weder in der Hierarchie noch an einer Beziehung.');
            }
        }

        return $issues;
    }

    /**
     * Zyklen in der Gruppenhierarchie.
     *
     * @return list<array{code:string,id:string,message:string}>
     */
    private function hierarchyIssues(): array
    {
        $issues = [];
        foreach (array_keys($this->groups) as $id) {
            $seen = [];
            $current = $id;
            while ($current !== null) {
                if (isset($seen[$current])) {
                    $issues[] = self::issue('hierarchy_cycle', $id, 'Die Gruppenhierarchie enthaelt einen Zyklus ueber "' . $current . '".');
                    break;
                }
                $seen[$current] = true;
                $current = $this->groups[$current]['parent'] ?? null;
                if ($current !== null && !isset($this->groups[$current])) {
                    break;
                }
            }
        }

        return $issues;
    }

    /** @return array{code:string,id:string,message:string} */
    private static function issue(string $code, string $id, string $message): array
    {
        return ['code' => $code, 'id' => $id, 'message' => $message];
    }
}
