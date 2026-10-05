<?php

declare(strict_types=1);

namespace App\Repositories;

/**
 * Signaturvorlagen der Mail-App Orvanta (Tabelle `orvanta_signatures`).
 * Die AD-Gruppen einer Vorlage liegen als JSON-Liste in `ad_groups`.
 *
 * @phpstan-type SignatureRow array{id:int,name:string,greeting:string,street:string,postal_city:string,phone_mode:string,phone_prefix:string,text_color:string,separator_color:string,groups:list<string>,sort_order:int,active:bool}
 */
final class OrvantaSignatureRepository extends Repository
{
    private const COLUMNS = 'id, name, greeting, street, postal_city, phone_mode, phone_prefix, text_color, separator_color, ad_groups, sort_order, active';

    /**
     * @return list<SignatureRow>
     */
    public function all(bool $activeOnly = false): array
    {
        $sql = 'SELECT ' . self::COLUMNS . ' FROM orvanta_signatures'
            . ($activeOnly ? ' WHERE active = 1' : '')
            . ' ORDER BY sort_order ASC, name ASC, id ASC';
        $statement = $this->pdo->query($sql);

        return array_map([self::class, 'hydrate'], $statement === false ? [] : $statement->fetchAll());
    }

    /**
     * @return SignatureRow|null
     */
    public function find(int $id): ?array
    {
        $statement = $this->pdo->prepare('SELECT ' . self::COLUMNS . ' FROM orvanta_signatures WHERE id = :id');
        $statement->execute(['id' => $id]);
        $row = $statement->fetch();

        return is_array($row) ? self::hydrate($row) : null;
    }

    /**
     * @param array{name:string,greeting:string,street:string,postal_city:string,phone_mode:string,phone_prefix:string,text_color:string,separator_color:string,groups:list<string>,sort_order:int,active:bool} $data
     */
    public function save(?int $id, array $data): int
    {
        $bindings = [
            'name' => $data['name'],
            'greeting' => $data['greeting'],
            'street' => $data['street'],
            'postal_city' => $data['postal_city'],
            'phone_mode' => $data['phone_mode'],
            'phone_prefix' => $data['phone_prefix'],
            'text_color' => $data['text_color'],
            'separator_color' => $data['separator_color'],
            'ad_groups' => json_encode(array_values($data['groups']), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'sort_order' => $data['sort_order'],
            'active' => $data['active'] ? 1 : 0,
        ];
        if ($id === null) {
            $statement = $this->pdo->prepare(
                'INSERT INTO orvanta_signatures (name, greeting, street, postal_city, phone_mode, phone_prefix, text_color, separator_color, ad_groups, sort_order, active)
                 VALUES (:name, :greeting, :street, :postal_city, :phone_mode, :phone_prefix, :text_color, :separator_color, :ad_groups, :sort_order, :active)'
            );
            $statement->execute($bindings);

            return (int) $this->pdo->lastInsertId();
        }

        $statement = $this->pdo->prepare(
            'UPDATE orvanta_signatures
                SET name = :name, greeting = :greeting, street = :street, postal_city = :postal_city, phone_mode = :phone_mode,
                    phone_prefix = :phone_prefix, text_color = :text_color, separator_color = :separator_color, ad_groups = :ad_groups, sort_order = :sort_order, active = :active
              WHERE id = :id'
        );
        $statement->execute($bindings + ['id' => $id]);

        return $id;
    }

    public function delete(int $id): void
    {
        $statement = $this->pdo->prepare('DELETE FROM orvanta_signatures WHERE id = :id');
        $statement->execute(['id' => $id]);
    }

    /**
     * @param array<string,mixed> $row
     * @return SignatureRow
     */
    private static function hydrate(array $row): array
    {
        $groups = json_decode((string) ($row['ad_groups'] ?? '[]'), true);

        return [
            'id' => (int) $row['id'],
            'name' => (string) $row['name'],
            'greeting' => (string) ($row['greeting'] ?? ''),
            'street' => (string) ($row['street'] ?? ''),
            'postal_city' => (string) ($row['postal_city'] ?? ''),
            'phone_mode' => (string) ($row['phone_mode'] ?? 'prefix'),
            'phone_prefix' => (string) ($row['phone_prefix'] ?? ''),
            'text_color' => (string) ($row['text_color'] ?? 'color_text'),
            'separator_color' => (string) ($row['separator_color'] ?? 'color_accent'),
            'groups' => is_array($groups) ? array_values(array_map('strval', $groups)) : [],
            'sort_order' => (int) ($row['sort_order'] ?? 1),
            'active' => (int) ($row['active'] ?? 1) === 1,
        ];
    }
}
