<?php

declare(strict_types=1);

namespace App\Services\Helpdesk;

/**
 * Auswertung von Regelbedingungen (reine Logik, testbar ohne Datenbank).
 *
 * Bedingungen: {"all":[{"field":"priority_code","op":"eq","value":"critical"}], "any":[...]}
 * Operatoren: eq, neq, in, not_in, contains, starts_with, gt, lt, empty, not_empty
 * Aktionen (Schlüssel → Wert): set_group, set_priority, set_assignee, set_sla, add_tags, notify_group, notify_emails, set_status
 */
final class TicketRuleEvaluator
{
    public const FIELDS = ['priority_code', 'priority_level', 'type_code', 'status_code', 'category_name', 'subcategory_name', 'group_name', 'subject', 'description', 'source', 'tag', 'impact', 'urgency', 'assignee', 'requester_email', 'location_name'];
    public const OPERATORS = ['eq', 'neq', 'in', 'not_in', 'contains', 'starts_with', 'gt', 'lt', 'empty', 'not_empty'];
    public const ACTIONS = ['set_group', 'set_priority', 'set_assignee', 'set_sla', 'add_tags', 'notify_group', 'notify_emails', 'set_status'];

    /**
     * @param array<string,mixed> $conditions
     * @param array<string,mixed> $context Feldwerte des Tickets (siehe FIELDS; „tag“ als Liste)
     */
    public function matches(array $conditions, array $context): bool
    {
        $all = $conditions['all'] ?? [];
        $any = $conditions['any'] ?? [];
        if (!is_array($all) || !is_array($any)) {
            return false;
        }
        foreach ($all as $condition) {
            if (!is_array($condition) || !$this->check($condition, $context)) {
                return false;
            }
        }
        if ($any !== []) {
            foreach ($any as $condition) {
                if (is_array($condition) && $this->check($condition, $context)) {
                    return true;
                }
            }

            return false;
        }

        return true;
    }

    /** @param array<string,mixed> $condition @param array<string,mixed> $context */
    private function check(array $condition, array $context): bool
    {
        $field = (string) ($condition['field'] ?? '');
        $op = (string) ($condition['op'] ?? 'eq');
        $expected = $condition['value'] ?? null;
        $actual = $context[$field] ?? null;

        // Listenfelder (Tags): Treffer, wenn ein Element passt
        if (is_array($actual)) {
            if ($op === 'empty') {
                return $actual === [];
            }
            if ($op === 'not_empty') {
                return $actual !== [];
            }
            foreach ($actual as $item) {
                if ($this->compare((string) $item, $op, $expected)) {
                    return true;
                }
            }

            return $op === 'neq' || $op === 'not_in';
        }

        return $this->compare($actual === null ? null : (string) $actual, $op, $expected);
    }

    private function compare(?string $actual, string $op, mixed $expected): bool
    {
        $a = mb_strtolower(trim((string) $actual));
        $list = is_array($expected) ? array_map(static fn ($v): string => mb_strtolower(trim((string) $v)), $expected) : [mb_strtolower(trim((string) $expected))];
        $e = $list[0] ?? '';

        return match ($op) {
            'eq' => $a === $e,
            'neq' => $a !== $e,
            'in' => in_array($a, $list, true),
            'not_in' => !in_array($a, $list, true),
            'contains' => $e !== '' && str_contains($a, $e),
            'starts_with' => $e !== '' && str_starts_with($a, $e),
            'gt' => is_numeric($a) && is_numeric($e) && (float) $a > (float) $e,
            'lt' => is_numeric($a) && is_numeric($e) && (float) $a < (float) $e,
            'empty' => $a === '',
            'not_empty' => $a !== '',
            default => false,
        };
    }

    /** Validiert die JSON-Struktur einer Regel; liefert Fehlermeldungen (leer = gültig). @return array<int,string> */
    public function validateDefinition(mixed $conditions, mixed $actions): array
    {
        $errors = [];
        if (!is_array($conditions)) {
            $errors[] = 'Bedingungen müssen ein JSON-Objekt sein.';
        } else {
            foreach (['all', 'any'] as $key) {
                foreach ((array) ($conditions[$key] ?? []) as $condition) {
                    if (!is_array($condition) || !in_array($condition['field'] ?? '', self::FIELDS, true)) {
                        $errors[] = 'Unbekanntes Bedingungsfeld: ' . (string) ($condition['field'] ?? '?');
                    } elseif (!in_array($condition['op'] ?? 'eq', self::OPERATORS, true)) {
                        $errors[] = 'Unbekannter Operator: ' . (string) $condition['op'];
                    }
                }
            }
        }
        if (!is_array($actions) || $actions === []) {
            $errors[] = 'Mindestens eine Aktion ist erforderlich.';
        } else {
            foreach (array_keys($actions) as $action) {
                if (!in_array($action, self::ACTIONS, true)) {
                    $errors[] = 'Unbekannte Aktion: ' . $action;
                }
            }
        }

        return $errors;
    }
}
