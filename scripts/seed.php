<?php

declare(strict_types=1);

/**
 * Legt die initialen Navigationselemente und Standardeinstellungen an.
 *
 * WICHTIG: Die URLs sind ausdrueckliche Platzhalter fuer die Entwicklung und
 * muessen im Adminbereich durch die produktiven Adressen ersetzt werden.
 *
 * Aufruf: php scripts/seed.php
 */

require_once dirname(__DIR__) . '/bootstrap.php';

use App\Core\Database;

$pdo = Database::connection();

/** @var list<array{title:string,url:string,type:string,icon:string,short:string,description:string}> $items */
$items = [
    [
        'title' => 'DMS',
        'url' => 'https://dms.example.internal',
        'type' => 'external',
        'icon' => 'document',
        'short' => 'Dokumente zentral verwalten',
        'description' => 'Zentrales Dokumentenmanagementsystem für die Ablage, Recherche und Versionierung von Dokumenten. PLATZHALTER-URL – bitte im Adminbereich anpassen.',
    ],
    [
        'title' => 'Flip',
        'url' => 'https://flip.example.internal',
        'type' => 'external',
        'icon' => 'app',
        'short' => 'Mitarbeiter-App',
        'description' => 'Interne Kommunikations- und Mitarbeiter-App. PLATZHALTER-URL – bitte im Adminbereich anpassen.',
    ],
    [
        'title' => 'Telefonliste',
        'url' => '/telefonliste',
        'type' => 'internal',
        'icon' => 'phone',
        'short' => 'Ansprechpartner schnell finden',
        'description' => 'Durchsuchbare Telefonliste auf Basis der Active-Directory-Daten. Die Suche berücksichtigt Name, Vorname, Nachname, Abteilung und Telefonnummer.',
    ],
    [
        'title' => 'Störmeldung IT',
        'url' => 'https://stoerung-it.example.internal',
        'type' => 'external',
        'icon' => 'alert',
        'short' => 'IT-Störung melden',
        'description' => 'Meldung von Störungen an IT-Systemen, Hardware und Software. PLATZHALTER-URL – bitte im Adminbereich anpassen.',
    ],
    [
        'title' => 'Störmeldung Technik',
        'url' => 'https://stoerung-technik.example.internal',
        'type' => 'external',
        'icon' => 'tools',
        'short' => 'Technische Störung melden',
        'description' => 'Meldung von Störungen an Gebäudetechnik und technischen Anlagen. PLATZHALTER-URL – bitte im Adminbereich anpassen.',
    ],
    [
        'title' => 'KHWF KI',
        'url' => 'https://ki.example.internal',
        'type' => 'external',
        'icon' => 'robot',
        'short' => 'KI-Assistenz',
        'description' => 'Interner KI-Assistent für Recherche und Textarbeit. PLATZHALTER-URL – bitte im Adminbereich anpassen.',
    ],
];

$statement = $pdo->prepare(
    'INSERT INTO navigation_items (title, url, type, icon, short_description, description, sort_order, active)
     SELECT :title, :url, :type, :icon, :short_description, :description, :sort_order, 1
       FROM DUAL
      WHERE NOT EXISTS (SELECT 1 FROM navigation_items WHERE title = :existing_title)'
);

$existing = (int) ($pdo->query('SELECT COUNT(*) FROM navigation_items')?->fetchColumn() ?: 0);
if ($existing > 0) {
    // Bereits gepflegte Navigation nicht erneut mit Platzhaltern befüllen.
    fwrite(STDOUT, 'Navigation ist bereits vorhanden – Seeder übersprungen.' . PHP_EOL);
    $items = [];
}

$created = 0;
foreach ($items as $index => $item) {
    $statement->execute([
        'title' => $item['title'],
        'url' => $item['url'],
        'type' => $item['type'],
        'icon' => $item['icon'],
        'short_description' => $item['short'],
        'description' => $item['description'],
        'sort_order' => $index + 1,
        'existing_title' => $item['title'],
    ]);
    $created += $statement->rowCount();
}

$settings = [
    'site_title' => 'Intranet',
    'site_subtitle' => 'Zentraler Einstieg zu internen Anwendungen',
    'site_subtitle_visible' => '1',
    'footer_text' => 'Intranet',
    'description_mode' => 'both',
    'color_primary' => '#1f4e79',
    'color_secondary' => '#37718e',
    'color_accent' => '#c8102e',
    'color_background' => '#f4f6f8',
    'color_text' => '#1b1f23',
    'color_background_dark' => '#12161c',
    'color_text_dark' => '#e8eaed',
];

$settingStatement = $pdo->prepare(
    'INSERT IGNORE INTO settings (setting_key, setting_value) VALUES (:key, :value)'
);

foreach ($settings as $key => $value) {
    $settingStatement->execute(['key' => $key, 'value' => $value]);
}

fwrite(STDOUT, sprintf('Seed abgeschlossen. %d Navigationselement(e) angelegt.%s', $created, PHP_EOL));
fwrite(STDOUT, 'Hinweis: Alle example.internal-URLs sind Platzhalter und müssen ersetzt werden.' . PHP_EOL);
