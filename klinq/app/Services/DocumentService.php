<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use App\Exceptions\ValidationException;
use App\Repositories\DocumentRepository;
use App\Security\CurrentUser;

/**
 * Speichert Uploads außerhalb von public/ (storage/uploads/JJJJ/MM/<uuid>.<ext>) und prüft Typ/Größe serverseitig.
 * Die Auslieferung läuft ausschließlich über DocumentController (Berechtigungsprüfung).
 */
final class DocumentService
{
    public const ENTITY_TYPES = ['asset', 'purchase_order', 'license', 'movement', 'supplier', 'import', 'handover', 'ticket', 'knowledge_article'];
    public const DOCUMENT_TYPES = [
        'order' => 'Bestellung', 'order_confirmation' => 'Auftragsbestätigung', 'delivery_note' => 'Lieferschein',
        'invoice' => 'Rechnung', 'license' => 'Lizenz', 'photo' => 'Foto', 'signature' => 'Unterschrift',
        'handover_protocol' => 'Übergabeprotokoll', 'movement_receipt' => 'Entnahme-/Retourennachweis',
        'attachment' => 'Anhang', 'other' => 'Sonstiges',
    ];
    /** Typen, die Benutzer manuell hochladen dürfen (ohne systemerzeugte Unterschrift/Protokoll). */
    public const UPLOAD_TYPES = [
        'order' => 'Bestellung', 'order_confirmation' => 'Auftragsbestätigung', 'delivery_note' => 'Lieferschein',
        'invoice' => 'Rechnung', 'license' => 'Lizenz', 'photo' => 'Foto', 'other' => 'Sonstiges',
    ];

    public function __construct(
        private readonly DocumentRepository $documents,
        private readonly Config $config,
        private readonly CurrentUser $currentUser,
        private readonly AuditLogService $audit
    ) {}

    /**
     * Speichert eine hochgeladene Datei ($_FILES-Eintrag) und legt den Datensatz an.
     * @param array<string,mixed> $upload
     * @param array<int,string>|null $allowedExtensions Einschränkung (z. B. nur Bilder für Fotos)
     */
    public function store(string $entityType, int $entityId, string $documentType, array $upload, ?string $note = null, ?array $allowedExtensions = null, string $field = 'file'): int
    {
        if (!in_array($entityType, self::ENTITY_TYPES, true) || !isset(self::DOCUMENT_TYPES[$documentType])) {
            throw new \InvalidArgumentException('Unbekannter Dokumenttyp.');
        }
        $error = (int) ($upload['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE) {
            throw ValidationException::single($field, 'Die Datei ist zu groß.');
        }
        if ($error !== UPLOAD_ERR_OK) {
            throw ValidationException::single($field, 'Die Datei konnte nicht hochgeladen werden.');
        }
        $maxBytes = (int) $this->config->get('uploads.max_bytes', 20 * 1024 * 1024);
        $size = (int) ($upload['size'] ?? 0);
        if ($size <= 0 || $size > $maxBytes) {
            throw ValidationException::single($field, sprintf('Die Datei darf höchstens %d MB groß sein.', (int) round($maxBytes / 1024 / 1024)));
        }

        $originalName = basename(str_replace('\\', '/', (string) ($upload['name'] ?? 'datei')));
        $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        $tmp = (string) ($upload['tmp_name'] ?? '');
        $mime = $this->detectMime($tmp);
        $extension = $this->resolveExtension($extension, $mime);
        $allowed = $allowedExtensions ?? (array) $this->config->get('uploads.allowed_extensions', []);
        if ($extension === null || !in_array($extension, $allowed, true)) {
            throw ValidationException::single($field, 'Dieser Dateityp ist nicht erlaubt (' . ($mime ?? 'unbekannt') . ').');
        }

        $base = rtrim((string) $this->config->get('uploads.path'), '/');
        $relativeDir = date('Y') . '/' . date('m');
        $dir = $base . '/' . $relativeDir;
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException('Upload-Verzeichnis konnte nicht angelegt werden.');
        }
        $storedName = $relativeDir . '/' . $this->uuid() . '.' . $extension;
        $target = $base . '/' . $storedName;
        if (!(is_uploaded_file($tmp) ? move_uploaded_file($tmp, $target) : rename($tmp, $target))) {
            throw new \RuntimeException('Datei konnte nicht gespeichert werden.');
        }
        @chmod($target, 0640);

        $id = $this->documents->create([
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'document_type' => $documentType,
            'original_name' => mb_substr($originalName, 0, 255),
            'stored_name' => $storedName,
            'mime_type' => $mime ?? 'application/octet-stream',
            'size_bytes' => $size,
            'sha256' => hash_file('sha256', $target) ?: null,
            'note' => $note !== null && trim($note) !== '' ? mb_substr(trim($note), 0, 500) : null,
            'uploaded_by' => $this->currentUser->id(),
            'uploaded_by_name' => $this->currentUser->displayName(),
        ]);
        $this->audit->log('upload', 'document', $id, $originalName, null, ['entity_type' => $entityType, 'entity_id' => $entityId, 'document_type' => $documentType]);

        return $id;
    }

    /**
     * Legt serverseitig erzeugte Inhalte (PDF, Unterschrift-PNG) wie einen Upload ab.
     * Der MIME-Typ wird aus dem Inhalt bestimmt; Endung muss zu den erlaubten Typen passen.
     */
    public function storeGenerated(string $entityType, int $entityId, string $documentType, string $content, string $fileName, ?string $note = null): int
    {
        $tmp = tempnam(sys_get_temp_dir(), 'gen');
        if ($tmp === false || file_put_contents($tmp, $content) === false) {
            throw new \RuntimeException('Temporäre Datei konnte nicht geschrieben werden.');
        }
        try {
            return $this->store($entityType, $entityId, $documentType, [
                'name' => $fileName,
                'tmp_name' => $tmp,
                'size' => strlen($content),
                'error' => UPLOAD_ERR_OK,
            ], $note, ['pdf', 'png']);
        } finally {
            if (is_file($tmp)) {
                @unlink($tmp);
            }
        }
    }

    /** @param array<string,mixed> $document */
    public function path(array $document): string
    {
        return rtrim((string) $this->config->get('uploads.path'), '/') . '/' . $this->safeRelative((string) $document['stored_name']);
    }

    /** @param array<string,mixed> $document */
    public function delete(array $document): void
    {
        $path = $this->path($document);
        if (is_file($path)) {
            @unlink($path);
        }
        $this->documents->delete((int) $document['id']);
        $this->audit->log('delete', 'document', (int) $document['id'], (string) $document['original_name']);
    }

    /** @param array<string,mixed> $document */
    public function isImage(array $document): bool
    {
        return str_starts_with((string) $document['mime_type'], 'image/');
    }

    /** @return array<int,string> */
    public function imageExtensions(): array
    {
        return (array) $this->config->get('uploads.image_extensions', ['png', 'jpg', 'jpeg', 'webp', 'heic']);
    }

    private function safeRelative(string $storedName): string
    {
        // Nur JJJJ/MM/<uuid>.<ext> zulassen – kein Pfad-Traversal aus der Datenbank
        if (!preg_match('#^\d{4}/\d{2}/[a-f0-9-]{36}\.[a-z0-9]{1,8}$#', $storedName)) {
            throw new \RuntimeException('Ungültiger Dokumentpfad.');
        }

        return $storedName;
    }

    private function detectMime(string $path): ?string
    {
        if ($path === '' || !is_file($path)) {
            return null;
        }
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($path);
        if ($mime === false) {
            return null;
        }
        // HEIC/HEIF erkennt libmagic nicht immer – Signatur prüfen
        if ($mime === 'application/octet-stream') {
            $head = (string) file_get_contents($path, false, null, 0, 16);
            if (substr($head, 4, 4) === 'ftyp' && in_array(substr($head, 8, 4), ['heic', 'heix', 'hevc', 'mif1', 'msf1', 'heif'], true)) {
                return 'image/heic';
            }
        }

        return $mime;
    }

    /** Endung anhand des tatsächlichen MIME-Typs bestimmen bzw. prüfen. */
    private function resolveExtension(string $extension, ?string $mime): ?string
    {
        if ($mime === null) {
            return null;
        }
        /** @var array<string,array<int,string>> $map */
        $map = (array) $this->config->get('uploads.mime_map', []);
        if ($extension !== '' && isset($map[$extension]) && in_array($mime, $map[$extension], true)) {
            return $extension === 'jpeg' ? 'jpg' : $extension;
        }
        // Endung fehlt oder passt nicht (z. B. Foto von der Kamera als "image.jpg" mit MIME png) → aus MIME ableiten
        foreach ($map as $ext => $mimes) {
            if (in_array($mime, $mimes, true) && $ext !== 'jpeg' && $ext !== 'txt') {
                return $ext;
            }
        }

        return null;
    }

    private function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
