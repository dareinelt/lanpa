<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

/**
 * Datenzugriff fuer den SMTP-/IMAP-Proxy (Migration 039): Mailserver je
 * Identitaetsquelle, Postfaecher, Zuordnungen AD-Benutzer -> Postfach und
 * der Zustand (Cache-Generation, letzte Verbindung).
 *
 * Verschluesselte Passwoerter werden ausschliesslich ueber
 * mailboxSecret() und connectionRow() (Verbindungsaufbau) gelesen; alle
 * Listen- und Suchabfragen liefern nur password_set (bool) und niemals das
 * Chiffrat.
 *
 * SQL bewusst ohne MySQL-Sonderformen (laeuft auch in den SQLite-Tests).
 */
final class MailProxyRepository extends Repository
{
    private const SERVER_COLUMNS = 'id, identity_source_id, name, smtp_host, smtp_port, smtp_security, smtp_auth, imap_host, imap_port, imap_security, verify_tls, timeout_seconds, active, created_at, updated_at';

    private const MAILBOX_COLUMNS = 'b.id, b.server_id, b.username, b.email_address, b.display_name, b.active, b.created_at, b.updated_at,'
        . " CASE WHEN b.password_encrypted <> '' THEN 1 ELSE 0 END AS password_set";

    // ------------------------------------------------------------------ Server

    /**
     * @return list<array<string,mixed>>
     */
    public function servers(): array
    {
        $statement = $this->pdo->query(
            'SELECT s.' . str_replace(', ', ', s.', self::SERVER_COLUMNS) . ','
            . ' (SELECT COUNT(*) FROM mail_proxy_mailboxes b WHERE b.server_id = s.id) AS mailbox_count,'
            . ' (SELECT COUNT(*) FROM mail_proxy_mappings m JOIN mail_proxy_mailboxes b ON b.id = m.mailbox_id WHERE b.server_id = s.id) AS mapping_count'
            . ' FROM mail_proxy_servers s ORDER BY s.identity_source_id ASC, s.id ASC'
        );
        $rows = $statement === false ? [] : $statement->fetchAll(PDO::FETCH_ASSOC);

        return array_map(fn (array $row): array => $this->castServer($row), $rows);
    }

    /**
     * @return array<string,mixed>|null
     */
    public function findServer(int $id): ?array
    {
        $statement = $this->pdo->prepare('SELECT ' . self::SERVER_COLUMNS . ' FROM mail_proxy_servers WHERE id = :id');
        $statement->execute(['id' => $id]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $this->castServer($row) : null;
    }

    /**
     * @return array<string,mixed>|null
     */
    public function findServerBySource(int $sourceId): ?array
    {
        $statement = $this->pdo->prepare('SELECT ' . self::SERVER_COLUMNS . ' FROM mail_proxy_servers WHERE identity_source_id = :source');
        $statement->execute(['source' => $sourceId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $this->castServer($row) : null;
    }

    /**
     * @param array<string,mixed> $values
     */
    public function createServer(array $values): int
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO mail_proxy_servers (identity_source_id, name, smtp_host, smtp_port, smtp_security, smtp_auth, imap_host, imap_port, imap_security, verify_tls, timeout_seconds, active)'
            . ' VALUES (:identity_source_id, :name, :smtp_host, :smtp_port, :smtp_security, :smtp_auth, :imap_host, :imap_port, :imap_security, :verify_tls, :timeout_seconds, :active)'
        );
        $statement->execute($this->serverParams($values) + ['identity_source_id' => (int) $values['identity_source_id']]);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * Die Identitaetsquelle eines Servers ist nach dem Anlegen fest (sonst
     * wuerden bestehende Zuordnungen still auf eine andere Quelle zeigen).
     *
     * @param array<string,mixed> $values
     */
    public function updateServer(int $id, array $values): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE mail_proxy_servers SET name = :name, smtp_host = :smtp_host, smtp_port = :smtp_port, smtp_security = :smtp_security,'
            . ' smtp_auth = :smtp_auth, imap_host = :imap_host, imap_port = :imap_port, imap_security = :imap_security,'
            . ' verify_tls = :verify_tls, timeout_seconds = :timeout_seconds, active = :active, updated_at = CURRENT_TIMESTAMP WHERE id = :id'
        );
        $statement->execute($this->serverParams($values) + ['id' => $id]);
    }

    public function setServerActive(int $id, bool $active): void
    {
        $statement = $this->pdo->prepare('UPDATE mail_proxy_servers SET active = :active, updated_at = CURRENT_TIMESTAMP WHERE id = :id');
        $statement->execute(['active' => $active ? 1 : 0, 'id' => $id]);
    }

    public function deleteServer(int $id): void
    {
        $statement = $this->pdo->prepare('DELETE FROM mail_proxy_servers WHERE id = :id');
        $statement->execute(['id' => $id]);
    }

    public function countMailboxes(int $serverId): int
    {
        $statement = $this->pdo->prepare('SELECT COUNT(*) FROM mail_proxy_mailboxes WHERE server_id = :id');
        $statement->execute(['id' => $serverId]);

        return (int) $statement->fetchColumn();
    }

    public function hasActiveServers(): bool
    {
        $statement = $this->pdo->query('SELECT COUNT(*) FROM mail_proxy_servers WHERE active = 1');

        return $statement !== false && (int) $statement->fetchColumn() > 0;
    }

    // ------------------------------------------------------------------ Postfaecher

    /**
     * @return list<array<string,mixed>>
     */
    public function mailboxes(int $serverId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT ' . self::MAILBOX_COLUMNS . ', m.id AS mapping_id, p.display_name AS mapped_display_name, p.samaccount_name AS mapped_username'
            . ' FROM mail_proxy_mailboxes b'
            . ' LEFT JOIN mail_proxy_mappings m ON m.mailbox_id = b.id'
            . ' LEFT JOIN phonebook p ON p.id = m.phonebook_id'
            . ' WHERE b.server_id = :server ORDER BY b.email_address ASC, b.id ASC'
        );
        $statement->execute(['server' => $serverId]);

        return array_map(fn (array $row): array => $this->castMailbox($row), $statement->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * @return array<string,mixed>|null
     */
    public function findMailbox(int $id): ?array
    {
        $statement = $this->pdo->prepare('SELECT ' . self::MAILBOX_COLUMNS . ' FROM mail_proxy_mailboxes b WHERE b.id = :id');
        $statement->execute(['id' => $id]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $this->castMailbox($row) : null;
    }

    public function emailExists(int $serverId, string $email, int $exceptId = 0): bool
    {
        $statement = $this->pdo->prepare('SELECT COUNT(*) FROM mail_proxy_mailboxes WHERE server_id = :server AND LOWER(email_address) = LOWER(:email) AND id <> :id');
        $statement->execute(['server' => $serverId, 'email' => $email, 'id' => $exceptId]);

        return (int) $statement->fetchColumn() > 0;
    }

    public function createMailbox(int $serverId, string $username, string $email, string $displayName, string $passwordEncrypted, bool $active): int
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO mail_proxy_mailboxes (server_id, username, email_address, display_name, password_encrypted, active)'
            . ' VALUES (:server, :username, :email, :display_name, :password, :active)'
        );
        $statement->execute([
            'server' => $serverId,
            'username' => $username,
            'email' => $email,
            'display_name' => $displayName,
            'password' => $passwordEncrypted,
            'active' => $active ? 1 : 0,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * @param string|null $passwordEncrypted null = Passwort unveraendert lassen
     */
    public function updateMailbox(int $id, string $username, string $email, string $displayName, ?string $passwordEncrypted, bool $active): void
    {
        $params = ['id' => $id, 'username' => $username, 'email' => $email, 'display_name' => $displayName, 'active' => $active ? 1 : 0];
        $passwordSql = '';
        if ($passwordEncrypted !== null) {
            $passwordSql = ', password_encrypted = :password';
            $params['password'] = $passwordEncrypted;
        }
        $statement = $this->pdo->prepare(
            'UPDATE mail_proxy_mailboxes SET username = :username, email_address = :email, display_name = :display_name, active = :active'
            . $passwordSql . ', updated_at = CURRENT_TIMESTAMP WHERE id = :id'
        );
        $statement->execute($params);
    }

    /**
     * Nur das Kennwort-Chiffrat ersetzen (vom Benutzer bestaetigtes neues Kennwort).
     */
    public function updateMailboxPassword(int $id, string $passwordEncrypted): void
    {
        $this->pdo->prepare('UPDATE mail_proxy_mailboxes SET password_encrypted = :password, updated_at = CURRENT_TIMESTAMP WHERE id = :id')
            ->execute(['id' => $id, 'password' => $passwordEncrypted]);
    }

    public function deleteMailbox(int $id): void
    {
        // Zuordnungen explizit entfernen (SQLite-Tests ohne Fremdschluessel).
        $this->pdo->prepare('DELETE FROM mail_proxy_mappings WHERE mailbox_id = :id')->execute(['id' => $id]);
        $this->pdo->prepare('DELETE FROM mail_proxy_mailboxes WHERE id = :id')->execute(['id' => $id]);
    }

    /**
     * Passwort-Chiffrat eines Postfachs (nur fuer Pruefungen/Verbindungsaufbau).
     */
    public function mailboxSecret(int $id): ?string
    {
        $statement = $this->pdo->prepare('SELECT password_encrypted FROM mail_proxy_mailboxes WHERE id = :id');
        $statement->execute(['id' => $id]);
        $value = $statement->fetchColumn();

        return is_string($value) ? $value : null;
    }

    /**
     * Postfach-Vorschlaege fuer die Zuordnung: nur aktive Postfaecher aktiver
     * oder inaktiver Server der angegebenen Identitaetsquelle; bereits
     * zugeordnete Postfaecher nur, wenn sie der Zuordnung $exceptMappingId gehoeren.
     *
     * @return list<array{id:int,email_address:string,username:string,display_name:string}>
     */
    public function suggestMailboxes(int $sourceId, string $term, int $exceptMappingId = 0, int $limit = 20): array
    {
        $statement = $this->pdo->prepare(
            'SELECT b.id, b.email_address, b.username, b.display_name FROM mail_proxy_mailboxes b'
            . ' JOIN mail_proxy_servers s ON s.id = b.server_id'
            . ' LEFT JOIN mail_proxy_mappings m ON m.mailbox_id = b.id'
            . ' WHERE s.identity_source_id = :source AND b.active = 1 AND (m.id IS NULL OR m.id = :mapping)'
            . " AND (LOWER(b.email_address) LIKE :term ESCAPE '!' OR LOWER(b.username) LIKE :term2 ESCAPE '!' OR LOWER(b.display_name) LIKE :term3 ESCAPE '!')"
            . ' ORDER BY b.email_address ASC LIMIT ' . max(1, min(50, $limit))
        );
        $like = '%' . self::escapeLike(mb_strtolower($term)) . '%';
        $statement->execute(['source' => $sourceId, 'mapping' => $exceptMappingId, 'term' => $like, 'term2' => $like, 'term3' => $like]);

        return array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'email_address' => (string) $row['email_address'],
            'username' => (string) $row['username'],
            'display_name' => (string) $row['display_name'],
        ], $statement->fetchAll(PDO::FETCH_ASSOC));
    }

    // ------------------------------------------------------------------ Benutzer (Telefonliste)

    /**
     * Aktive, synchronisierte Benutzer einer Identitaetsquelle.
     *
     * @return list<array{id:int,samaccount_name:string,display_name:string,email:string,department:string,mapped:bool}>
     */
    public function suggestUsers(int $sourceId, string $term, int $limit = 20): array
    {
        $statement = $this->pdo->prepare(
            'SELECT p.id, p.samaccount_name, p.display_name, p.email, p.department, m.id AS mapping_id FROM phonebook p'
            . ' LEFT JOIN mail_proxy_mappings m ON m.phonebook_id = p.id'
            . " WHERE p.identity_source_id = :source AND p.active = 1 AND p.samaccount_name IS NOT NULL AND p.samaccount_name <> ''"
            . " AND (LOWER(p.display_name) LIKE :term ESCAPE '!' OR LOWER(p.samaccount_name) LIKE :term2 ESCAPE '!' OR LOWER(p.email) LIKE :term3 ESCAPE '!')"
            . ' ORDER BY p.display_name ASC, p.id ASC LIMIT ' . max(1, min(50, $limit))
        );
        $like = '%' . self::escapeLike(mb_strtolower($term)) . '%';
        $statement->execute(['source' => $sourceId, 'term' => $like, 'term2' => $like, 'term3' => $like]);

        return array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'samaccount_name' => (string) $row['samaccount_name'],
            'display_name' => (string) $row['display_name'],
            'email' => (string) ($row['email'] ?? ''),
            'department' => (string) ($row['department'] ?? ''),
            'mapped' => $row['mapping_id'] !== null,
        ], $statement->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * @return array{id:int,identity_source_id:int,samaccount_name:string,display_name:string,active:bool}|null
     */
    public function findUser(int $phonebookId): ?array
    {
        $statement = $this->pdo->prepare('SELECT id, identity_source_id, samaccount_name, display_name, active FROM phonebook WHERE id = :id');
        $statement->execute(['id' => $phonebookId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }

        return [
            'id' => (int) $row['id'],
            'identity_source_id' => (int) $row['identity_source_id'],
            'samaccount_name' => (string) ($row['samaccount_name'] ?? ''),
            'display_name' => (string) ($row['display_name'] ?? ''),
            'active' => (int) $row['active'] === 1,
        ];
    }

    /**
     * Aktive weitere Identitaetsquelle anhand ihrer Kennung (ohne Beachtung
     * der Gross-/Kleinschreibung).
     */
    public function activeSourceIdByKey(string $key): ?int
    {
        $statement = $this->pdo->prepare('SELECT id FROM identity_sources WHERE LOWER(source_key) = LOWER(:source_key) AND active = 1 LIMIT 1');
        $statement->execute(['source_key' => $key]);
        $id = $statement->fetchColumn();

        return $id === false ? null : (int) $id;
    }

    /**
     * Aktiver Benutzer anhand SamAccountName und Quelle (fuer die Aufloesung
     * der Office-Kennung bei Anhang-Links ohne Sitzung).
     */
    public function findActiveUserId(string $samAccountName, int $sourceId): ?int
    {
        $statement = $this->pdo->prepare('SELECT id FROM phonebook WHERE identity_source_id = :source AND active = 1 AND LOWER(samaccount_name) = LOWER(:name) LIMIT 1');
        $statement->execute(['source' => $sourceId, 'name' => $samAccountName]);
        $id = $statement->fetchColumn();

        return $id === false ? null : (int) $id;
    }

    // ------------------------------------------------------------------ Zuordnungen

    /**
     * @return list<array<string,mixed>>
     */
    public function mappings(int $sourceId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT m.id, m.identity_source_id, m.phonebook_id, m.mailbox_id, m.updated_at,'
            . ' p.samaccount_name, p.display_name, p.active AS user_active,'
            . ' b.email_address, b.username AS mailbox_username, b.active AS mailbox_active'
            . ' FROM mail_proxy_mappings m'
            . ' JOIN phonebook p ON p.id = m.phonebook_id'
            . ' JOIN mail_proxy_mailboxes b ON b.id = m.mailbox_id'
            . ' WHERE m.identity_source_id = :source ORDER BY p.display_name ASC, m.id ASC'
        );
        $statement->execute(['source' => $sourceId]);

        return array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'identity_source_id' => (int) $row['identity_source_id'],
            'phonebook_id' => (int) $row['phonebook_id'],
            'mailbox_id' => (int) $row['mailbox_id'],
            'samaccount_name' => (string) ($row['samaccount_name'] ?? ''),
            'display_name' => (string) ($row['display_name'] ?? ''),
            'user_active' => (int) $row['user_active'] === 1,
            'email_address' => (string) $row['email_address'],
            'mailbox_username' => (string) $row['mailbox_username'],
            'mailbox_active' => (int) $row['mailbox_active'] === 1,
            'updated_at' => (string) ($row['updated_at'] ?? ''),
        ], $statement->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * @return array{id:int,identity_source_id:int,phonebook_id:int,mailbox_id:int}|null
     */
    public function findMapping(int $id): ?array
    {
        $statement = $this->pdo->prepare('SELECT id, identity_source_id, phonebook_id, mailbox_id FROM mail_proxy_mappings WHERE id = :id');
        $statement->execute(['id' => $id]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? array_map('intval', $row) : null;
    }

    public function mappingIdForUser(int $phonebookId): ?int
    {
        $statement = $this->pdo->prepare('SELECT id FROM mail_proxy_mappings WHERE phonebook_id = :id');
        $statement->execute(['id' => $phonebookId]);
        $id = $statement->fetchColumn();

        return $id === false ? null : (int) $id;
    }

    public function mappingIdForMailbox(int $mailboxId): ?int
    {
        $statement = $this->pdo->prepare('SELECT id FROM mail_proxy_mappings WHERE mailbox_id = :id');
        $statement->execute(['id' => $mailboxId]);
        $id = $statement->fetchColumn();

        return $id === false ? null : (int) $id;
    }

    public function createMapping(int $sourceId, int $phonebookId, int $mailboxId): int
    {
        $statement = $this->pdo->prepare('INSERT INTO mail_proxy_mappings (identity_source_id, phonebook_id, mailbox_id) VALUES (:source, :user, :mailbox)');
        $statement->execute(['source' => $sourceId, 'user' => $phonebookId, 'mailbox' => $mailboxId]);

        return (int) $this->pdo->lastInsertId();
    }

    public function updateMapping(int $id, int $phonebookId, int $mailboxId): void
    {
        $statement = $this->pdo->prepare('UPDATE mail_proxy_mappings SET phonebook_id = :user, mailbox_id = :mailbox, updated_at = CURRENT_TIMESTAMP WHERE id = :id');
        $statement->execute(['user' => $phonebookId, 'mailbox' => $mailboxId, 'id' => $id]);
    }

    public function deleteMapping(int $id): void
    {
        $statement = $this->pdo->prepare('DELETE FROM mail_proxy_mappings WHERE id = :id');
        $statement->execute(['id' => $id]);
    }

    /**
     * Rohdaten fuer die Aufloesung eines Benutzers (ohne Passwort): Zuordnung,
     * Benutzer, Postfach, Server und (falls vorhanden) die Identitaetsquelle.
     *
     * @return array<string,mixed>|null
     */
    public function resolutionRow(int $sourceId, int $phonebookId): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT m.id AS mapping_id, m.identity_source_id AS mapping_source_id,'
            . ' p.id AS phonebook_id, p.identity_source_id AS user_source_id, p.active AS user_active,'
            . ' b.id AS mailbox_id, b.email_address, b.active AS mailbox_active,'
            . ' s.id AS server_id, s.identity_source_id AS server_source_id, s.active AS server_active,'
            . ' i.id AS source_row_id, i.active AS source_active, i.source_key'
            . ' FROM mail_proxy_mappings m'
            . ' JOIN phonebook p ON p.id = m.phonebook_id'
            . ' JOIN mail_proxy_mailboxes b ON b.id = m.mailbox_id'
            . ' JOIN mail_proxy_servers s ON s.id = b.server_id'
            . ' LEFT JOIN identity_sources i ON i.id = s.identity_source_id'
            . ' WHERE m.phonebook_id = :user AND m.identity_source_id = :source'
        );
        $statement->execute(['user' => $phonebookId, 'source' => $sourceId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    /**
     * Verbindungsdaten eines Postfachs inkl. Chiffrat (nur fuer den
     * Verbindungsaufbau; nie an Views oder JSON weitergeben).
     *
     * @return array<string,mixed>|null
     */
    public function connectionRow(int $mailboxId): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT b.id AS mailbox_id, b.username, b.email_address, b.display_name, b.password_encrypted, b.active AS mailbox_active,'
            . ' s.id AS server_id, s.identity_source_id, s.smtp_host, s.smtp_port, s.smtp_security, s.smtp_auth,'
            . ' s.imap_host, s.imap_port, s.imap_security, s.verify_tls, s.timeout_seconds, s.active AS server_active'
            . ' FROM mail_proxy_mailboxes b JOIN mail_proxy_servers s ON s.id = b.server_id WHERE b.id = :id'
        );
        $statement->execute(['id' => $mailboxId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    // ------------------------------------------------------------------ Identitaetsquellen

    /**
     * Weitere Identitaetsquellen (ohne Zugangsdaten).
     *
     * @return list<array{id:int,source_key:string,label:string,base_dn:string,active:bool}>
     */
    public function identitySources(): array
    {
        try {
            $statement = $this->pdo->query('SELECT id, source_key, label, base_dn, active FROM identity_sources ORDER BY sort_order ASC, label ASC, id ASC');
            $rows = $statement === false ? [] : $statement->fetchAll(PDO::FETCH_ASSOC);
        } catch (\PDOException) {
            return [];
        }

        return array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'source_key' => (string) $row['source_key'],
            'label' => (string) $row['label'],
            'base_dn' => (string) ($row['base_dn'] ?? ''),
            'active' => (int) $row['active'] === 1,
        ], $rows);
    }

    // ------------------------------------------------------------------ Zustand

    public function generation(): int
    {
        $statement = $this->pdo->query('SELECT generation FROM mail_proxy_state WHERE id = 1');
        $value = $statement === false ? false : $statement->fetchColumn();

        return $value === false ? 0 : (int) $value;
    }

    public function bumpGeneration(): int
    {
        $statement = $this->pdo->prepare('UPDATE mail_proxy_state SET generation = generation + 1 WHERE id = 1');
        $statement->execute();
        if ($statement->rowCount() === 0) {
            $this->pdo->prepare('INSERT INTO mail_proxy_state (id, generation) VALUES (1, 2)')->execute();
        }

        return $this->generation();
    }

    public function recordSuccess(): void
    {
        $this->pdo->prepare('UPDATE mail_proxy_state SET last_success_at = CURRENT_TIMESTAMP WHERE id = 1')->execute();
    }

    public function recordError(string $message): void
    {
        $statement = $this->pdo->prepare('UPDATE mail_proxy_state SET last_error_at = CURRENT_TIMESTAMP, last_error = :error WHERE id = 1');
        $statement->execute(['error' => mb_substr($message, 0, 500)]);
    }

    /**
     * @return array{generation:int,last_success_at:string,last_error_at:string,last_error:string}
     */
    public function state(): array
    {
        $statement = $this->pdo->query('SELECT generation, last_success_at, last_error_at, last_error FROM mail_proxy_state WHERE id = 1');
        $row = $statement === false ? false : $statement->fetch(PDO::FETCH_ASSOC);

        return [
            'generation' => is_array($row) ? (int) $row['generation'] : 0,
            'last_success_at' => is_array($row) ? (string) ($row['last_success_at'] ?? '') : '',
            'last_error_at' => is_array($row) ? (string) ($row['last_error_at'] ?? '') : '',
            'last_error' => is_array($row) ? (string) ($row['last_error'] ?? '') : '',
        ];
    }

    /**
     * @return array{servers:int,active_servers:int,mailboxes:int,active_mailboxes:int,mappings:int}
     */
    public function counts(): array
    {
        $count = function (string $sql): int {
            $statement = $this->pdo->query($sql);

            return $statement === false ? 0 : (int) $statement->fetchColumn();
        };

        return [
            'servers' => $count('SELECT COUNT(*) FROM mail_proxy_servers'),
            'active_servers' => $count('SELECT COUNT(*) FROM mail_proxy_servers WHERE active = 1'),
            'mailboxes' => $count('SELECT COUNT(*) FROM mail_proxy_mailboxes'),
            'active_mailboxes' => $count('SELECT COUNT(*) FROM mail_proxy_mailboxes WHERE active = 1'),
            'mappings' => $count('SELECT COUNT(*) FROM mail_proxy_mappings'),
        ];
    }

    // ------------------------------------------------------------------ Hilfen

    /**
     * @param array<string,mixed> $values
     * @return array<string,mixed>
     */
    private function serverParams(array $values): array
    {
        return [
            'name' => (string) $values['name'],
            'smtp_host' => (string) $values['smtp_host'],
            'smtp_port' => (int) $values['smtp_port'],
            'smtp_security' => (string) $values['smtp_security'],
            'smtp_auth' => !empty($values['smtp_auth']) ? 1 : 0,
            'imap_host' => (string) $values['imap_host'],
            'imap_port' => (int) $values['imap_port'],
            'imap_security' => (string) $values['imap_security'],
            'verify_tls' => !empty($values['verify_tls']) ? 1 : 0,
            'timeout_seconds' => (int) $values['timeout_seconds'],
            'active' => !empty($values['active']) ? 1 : 0,
        ];
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function castServer(array $row): array
    {
        foreach (['id', 'identity_source_id', 'smtp_port', 'imap_port', 'timeout_seconds', 'mailbox_count', 'mapping_count'] as $key) {
            if (array_key_exists($key, $row)) {
                $row[$key] = (int) $row[$key];
            }
        }
        foreach (['smtp_auth', 'verify_tls', 'active'] as $key) {
            $row[$key] = (int) $row[$key] === 1;
        }

        return $row;
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function castMailbox(array $row): array
    {
        $row['id'] = (int) $row['id'];
        $row['server_id'] = (int) $row['server_id'];
        $row['active'] = (int) $row['active'] === 1;
        $row['password_set'] = (int) $row['password_set'] === 1;
        if (array_key_exists('mapping_id', $row)) {
            $row['mapping_id'] = $row['mapping_id'] === null ? null : (int) $row['mapping_id'];
        }
        unset($row['password_encrypted']);

        return $row;
    }

    private static function escapeLike(string $term): string
    {
        return str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $term);
    }
}
