<?php

declare(strict_types=1);

namespace App\Services\Office;

/**
 * Feste Liste der Office-Apps unter der Office-Kachel.
 *
 * - kind "editor": Euro-Office-Webapp (github.com/Euro-Office/web-apps), die
 *   ueber den Nextcloud-Connector mit einer neuen, leeren Datei geoeffnet wird
 *   (GET apps/eurooffice/new). Die Datei landet in den eigenen Dateien.
 * - kind "files":  eigene Dateien in Nextcloud.
 * - kind "external": frei konfigurierbarer Link (Outlook Web App).
 */
final class OfficeAppCatalog
{
    /**
     * @var array<string,array{title:string,short_description:string,icon:string,kind:string,webapp?:string,file_name?:string}>
     */
    public const APPS = [
        'document' => [
            'title' => 'Textdokument',
            'short_description' => 'Neues Dokument im Euro-Office Document Editor',
            'icon' => 'document',
            'kind' => 'editor',
            'webapp' => 'documenteditor',
            'file_name' => 'Neues Dokument.docx',
        ],
        'spreadsheet' => [
            'title' => 'Tabelle',
            'short_description' => 'Neue Tabelle im Euro-Office Spreadsheet Editor',
            'icon' => 'spreadsheet',
            'kind' => 'editor',
            'webapp' => 'spreadsheeteditor',
            'file_name' => 'Neue Tabelle.xlsx',
        ],
        'presentation' => [
            'title' => 'Präsentation',
            'short_description' => 'Neue Präsentation im Euro-Office Presentation Editor',
            'icon' => 'presentation',
            'kind' => 'editor',
            'webapp' => 'presentationeditor',
            'file_name' => 'Neue Präsentation.pptx',
        ],
        'pdf' => [
            'title' => 'PDF-Formular',
            'short_description' => 'Neues PDF-Formular im Euro-Office PDF Editor',
            'icon' => 'pdf',
            'kind' => 'editor',
            'webapp' => 'pdfeditor',
            'file_name' => 'Neues Formular.pdf',
        ],
        'files' => [
            'title' => 'Dateien',
            'short_description' => 'Eigene Dateien in Nextcloud',
            'icon' => 'folder',
            'kind' => 'files',
        ],
        'owa' => [
            'title' => 'Outlook Web App',
            'short_description' => 'E-Mail, Kalender und Kontakte',
            'icon' => 'mail',
            'kind' => 'external',
        ],
    ];

    public static function exists(string $key): bool
    {
        return isset(self::APPS[$key]);
    }

    /**
     * @return list<string>
     */
    public static function keys(): array
    {
        return array_keys(self::APPS);
    }

    /**
     * Gueltige App-Schluessel aus einer beliebigen Eingabe (Reihenfolge des Katalogs).
     *
     * @param array<mixed> $keys
     *
     * @return list<string>
     */
    public static function filterKeys(array $keys): array
    {
        $wanted = [];
        foreach ($keys as $key) {
            if (is_string($key)) {
                $wanted[$key] = true;
            }
        }

        return array_values(array_filter(self::keys(), static fn (string $key): bool => isset($wanted[$key])));
    }
}
