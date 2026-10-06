<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

/**
 * Persoenliche Woerterbuecher der Orvanta-Rechtschreibpruefung
 * (orvanta_spellcheck_words). SQL laeuft auf MySQL und SQLite (Tests).
 */
final class OrvantaSpellcheckWordRepository extends Repository
{
    /**
     * @return list<string> Woerter des Benutzers, alphabetisch
     */
    public function words(string $uid): array
    {
        $statement = $this->pdo->prepare('SELECT word FROM orvanta_spellcheck_words WHERE user_uid = :uid ORDER BY word');
        $statement->execute(['uid' => $uid]);

        return array_map('strval', $statement->fetchAll(PDO::FETCH_COLUMN) ?: []);
    }

    public function count(string $uid): int
    {
        $statement = $this->pdo->prepare('SELECT COUNT(*) FROM orvanta_spellcheck_words WHERE user_uid = :uid');
        $statement->execute(['uid' => $uid]);

        return (int) $statement->fetchColumn();
    }

    public function exists(string $uid, string $word): bool
    {
        $statement = $this->pdo->prepare('SELECT 1 FROM orvanta_spellcheck_words WHERE user_uid = :uid AND word = :word');
        $statement->execute(['uid' => $uid, 'word' => $word]);

        return $statement->fetchColumn() !== false;
    }

    public function add(string $uid, string $word): void
    {
        $statement = $this->pdo->prepare('INSERT INTO orvanta_spellcheck_words (user_uid, word) VALUES (:uid, :word)');
        $statement->execute(['uid' => $uid, 'word' => $word]);
    }

    public function remove(string $uid, string $word): void
    {
        $statement = $this->pdo->prepare('DELETE FROM orvanta_spellcheck_words WHERE user_uid = :uid AND word = :word');
        $statement->execute(['uid' => $uid, 'word' => $word]);
    }
}
