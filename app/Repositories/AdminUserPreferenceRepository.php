<?php

declare(strict_types=1);

namespace App\Repositories;

/**
 * Persoenliche Einstellungen je Administrationskonto (Tabelle
 * admin_user_preferences, siehe 055_topology_visibility.sql).
 *
 * Schluessel ist der Kontoname, nicht die ID aus admin_users: auch Konten der
 * Windows-Anmeldung (AD-Gruppen) haben dort keine Zeile, speichern aber
 * ebenfalls persoenliche Einstellungen.
 */
final class AdminUserPreferenceRepository extends Repository
{
    public function get(string $username, string $key): ?string
    {
        $statement = $this->pdo->prepare(
            'SELECT preference_value FROM admin_user_preferences
             WHERE username = :username AND preference_key = :key'
        );
        $statement->execute(['username' => $username, 'key' => $key]);
        $value = $statement->fetchColumn();

        return is_string($value) ? $value : null;
    }

    public function set(string $username, string $key, string $value): void
    {
        // Erst lesen, dann schreiben: "UPDATE ... WHERE" meldet bei
        // unveraendertem Wert null geaenderte Zeilen und wuerde den folgenden
        // INSERT faelschlich ausloesen (Verletzung des eindeutigen Schluessels).
        if ($this->get($username, $key) !== null) {
            $statement = $this->pdo->prepare(
                'UPDATE admin_user_preferences SET preference_value = :value
                 WHERE username = :username AND preference_key = :key'
            );
            $statement->execute(['value' => $value, 'username' => $username, 'key' => $key]);

            return;
        }

        $statement = $this->pdo->prepare(
            'INSERT INTO admin_user_preferences (username, preference_key, preference_value)
             VALUES (:username, :key, :value)'
        );
        $statement->execute(['username' => $username, 'key' => $key, 'value' => $value]);
    }

    public function delete(string $username, string $key): void
    {
        $statement = $this->pdo->prepare(
            'DELETE FROM admin_user_preferences WHERE username = :username AND preference_key = :key'
        );
        $statement->execute(['username' => $username, 'key' => $key]);
    }
}
