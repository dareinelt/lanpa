<?php

declare(strict_types=1);

namespace App\Repositories;

/**
 * Abwesenheitsnotizen der Mail-App Orvanta (Tabellen `orvanta_oof_templates`
 * und `orvanta_oof_settings`).
 *
 * Vorlagen werden im Adminbereich gepflegt; die Zuordnung zu Mitarbeitern
 * erfolgt ueber AD-Gruppen (JSON-Liste in `ad_groups`). Je Benutzer wird die
 * zuletzt eingestellte Notiz gehalten – massgeblich fuer den Banner ist der
 * Zustand auf dem Exchange-Server.
 *
 * @phpstan-type OofTemplateRow array{id:int,name:string,fixed_text:string,example_text:string,groups:list<string>,sort_order:int,active:bool}
 * @phpstan-type OofSettingsRow array{uid:string,template_id:int,dynamic_text:string,external_audience:string,schedule_mode:string,start_date:string,end_date:string,active:bool}
 */
final class OrvantaOofRepository extends Repository
{
    private const TEMPLATE_COLUMNS = 'id, name, fixed_text, example_text, ad_groups, sort_order, active';

    /**
     * @return list<OofTemplateRow>
     */
    public function all(bool $activeOnly = false): array
    {
        $sql = 'SELECT ' . self::TEMPLATE_COLUMNS . ' FROM orvanta_oof_templates'
            . ($activeOnly ? ' WHERE active = 1' : '')
            . ' ORDER BY sort_order ASC, name ASC, id ASC';
        $statement = $this->pdo->query($sql);

        return array_map([self::class, 'hydrate'], $statement === false ? [] : $statement->fetchAll());
    }

    /**
     * @return OofTemplateRow|null
     */
    public function find(int $id): ?array
    {
        $statement = $this->pdo->prepare('SELECT ' . self::TEMPLATE_COLUMNS . ' FROM orvanta_oof_templates WHERE id = :id');
        $statement->execute(['id' => $id]);
        $row = $statement->fetch();

        return is_array($row) ? self::hydrate($row) : null;
    }

    /**
     * @param array{name:string,fixed_text:string,example_text:string,groups:list<string>,sort_order:int,active:bool} $data
     */
    public function save(?int $id, array $data): int
    {
        $bindings = [
            'name' => $data['name'],
            'fixed_text' => $data['fixed_text'],
            'example_text' => $data['example_text'],
            'ad_groups' => json_encode(array_values($data['groups']), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'sort_order' => $data['sort_order'],
            'active' => $data['active'] ? 1 : 0,
        ];
        if ($id === null) {
            $statement = $this->pdo->prepare(
                'INSERT INTO orvanta_oof_templates (name, fixed_text, example_text, ad_groups, sort_order, active)
                 VALUES (:name, :fixed_text, :example_text, :ad_groups, :sort_order, :active)'
            );
            $statement->execute($bindings);

            return (int) $this->pdo->lastInsertId();
        }

        $statement = $this->pdo->prepare(
            'UPDATE orvanta_oof_templates
                SET name = :name, fixed_text = :fixed_text, example_text = :example_text, ad_groups = :ad_groups,
                    sort_order = :sort_order, active = :active
              WHERE id = :id'
        );
        $statement->execute($bindings + ['id' => $id]);

        return $id;
    }

    public function delete(int $id): void
    {
        $statement = $this->pdo->prepare('DELETE FROM orvanta_oof_templates WHERE id = :id');
        $statement->execute(['id' => $id]);
    }

    /**
     * Zuletzt in Orvanta eingestellte Abwesenheitsnotiz des Benutzers.
     *
     * @return OofSettingsRow|null
     */
    public function settings(string $uid): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT user_uid, template_id, dynamic_text, external_audience, schedule_mode, start_date, end_date, active
               FROM orvanta_oof_settings WHERE user_uid = :uid'
        );
        $statement->execute(['uid' => $uid]);
        $row = $statement->fetch();

        return is_array($row) ? self::hydrateSettings($row) : null;
    }

    /**
     * @param array{template_id:int,dynamic_text:string,external_audience:string,schedule_mode:string,start_date:string,end_date:string,active:bool} $data
     */
    public function saveSettings(string $uid, array $data): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO orvanta_oof_settings (user_uid, template_id, dynamic_text, external_audience, schedule_mode, start_date, end_date, active)
             VALUES (:uid, :template_id, :dynamic_text, :external_audience, :schedule_mode, :start_date, :end_date, :active)
             ON DUPLICATE KEY UPDATE template_id = VALUES(template_id), dynamic_text = VALUES(dynamic_text),
                 external_audience = VALUES(external_audience), schedule_mode = VALUES(schedule_mode),
                 start_date = VALUES(start_date), end_date = VALUES(end_date), active = VALUES(active)'
        );
        $statement->execute([
            'uid' => $uid,
            'template_id' => $data['template_id'] > 0 ? $data['template_id'] : null,
            'dynamic_text' => $data['dynamic_text'],
            'external_audience' => $data['external_audience'],
            'schedule_mode' => $data['schedule_mode'],
            'start_date' => $data['start_date'] !== '' ? $data['start_date'] : null,
            'end_date' => $data['end_date'] !== '' ? $data['end_date'] : null,
            'active' => $data['active'] ? 1 : 0,
        ]);
    }

    /**
     * @param array<string,mixed> $row
     * @return OofTemplateRow
     */
    private static function hydrate(array $row): array
    {
        $groups = json_decode((string) ($row['ad_groups'] ?? '[]'), true);

        return [
            'id' => (int) $row['id'],
            'name' => (string) $row['name'],
            'fixed_text' => (string) ($row['fixed_text'] ?? ''),
            'example_text' => (string) ($row['example_text'] ?? ''),
            'groups' => is_array($groups) ? array_values(array_map('strval', $groups)) : [],
            'sort_order' => (int) ($row['sort_order'] ?? 1),
            'active' => (int) ($row['active'] ?? 1) === 1,
        ];
    }

    /**
     * @param array<string,mixed> $row
     * @return OofSettingsRow
     */
    private static function hydrateSettings(array $row): array
    {
        return [
            'uid' => (string) $row['user_uid'],
            'template_id' => (int) ($row['template_id'] ?? 0),
            'dynamic_text' => (string) ($row['dynamic_text'] ?? ''),
            'external_audience' => (string) ($row['external_audience'] ?? 'none'),
            'schedule_mode' => (string) ($row['schedule_mode'] ?? 'until_off'),
            'start_date' => (string) ($row['start_date'] ?? ''),
            'end_date' => (string) ($row['end_date'] ?? ''),
            'active' => (int) ($row['active'] ?? 0) === 1,
        ];
    }
}
