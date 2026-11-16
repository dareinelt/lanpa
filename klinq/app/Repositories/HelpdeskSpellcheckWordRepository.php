<?php

declare(strict_types=1);

namespace App\Repositories;

/**
 * Persönliches Wörterbuch der Rechtschreibprüfung im Help Desk
 * (helpdesk_spellcheck_words).
 */
final class HelpdeskSpellcheckWordRepository extends BaseRepository
{
    /** @return list<string> Wörter des Benutzers, alphabetisch */
    public function words(int $userId): array
    {
        return array_map('strval', array_column($this->fetchAll(
            'SELECT word FROM helpdesk_spellcheck_words WHERE user_id = :user ORDER BY word',
            ['user' => $userId]
        ), 'word'));
    }

    public function count(int $userId): int
    {
        return (int) $this->fetchValue(
            'SELECT COUNT(*) FROM helpdesk_spellcheck_words WHERE user_id = :user',
            ['user' => $userId]
        );
    }

    public function exists(int $userId, string $word): bool
    {
        return $this->fetchValue(
            'SELECT 1 FROM helpdesk_spellcheck_words WHERE user_id = :user AND word = :word',
            ['user' => $userId, 'word' => $word]
        ) !== null;
    }

    public function add(int $userId, string $word): void
    {
        $this->execute(
            'INSERT INTO helpdesk_spellcheck_words (user_id, word) VALUES (:user, :word)',
            ['user' => $userId, 'word' => $word]
        );
    }

    public function remove(int $userId, string $word): void
    {
        $this->execute(
            'DELETE FROM helpdesk_spellcheck_words WHERE user_id = :user AND word = :word',
            ['user' => $userId, 'word' => $word]
        );
    }
}
