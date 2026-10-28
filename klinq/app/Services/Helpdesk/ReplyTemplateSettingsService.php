<?php

declare(strict_types=1);

namespace App\Services\Helpdesk;

use App\Core\Config;
use App\Security\CurrentUser;
use App\Services\AuditLogService;
use App\Services\SettingsService;
use App\Support\Validator;

/**
 * Vorlageneinstellungen des Help-Desk (Help-Desk-Einstellungen → „Vorlageneinstellungen“).
 *
 * Der Präfix-Text wird bei Agenten-Mails aus dem Ticket **erst beim Senden** serverseitig vor
 * den Text des Agenten gesetzt. Er steht bewusst nicht im Mail-Dialog: der Agent sieht nur
 * seinen eigenen Text, die Vorlage kommt ausschließlich beim Versand dazu.
 *
 * Der Bestätigungstext ist die Eingangsbestätigung für Meldungen aus dem Störungsformular und
 * dem Störungspostfach (sowie für Agenten-Tickets mit ausdrücklicher Bestätigung). Er darf
 * Platzhalter enthalten, die erst beim Versand je Empfänger gefüllt werden.
 *
 * Der Schließungstext ist die Benachrichtigung an den Melder, wenn ein Ticket geschlossen wird;
 * er kennt zusätzlich den Schließungsgrund und die Lösung. Ein leerer Text lässt die allgemeine
 * Statusmeldung („Ihr Ticket wurde geschlossen.“) stehen.
 *
 * Der Fuß-Hinweis ist der automatische Hinweis unter jeder Benachrichtigungsmail
 * (`TicketNotificationService::wrap()`); ein leerer Wert blendet ihn aus.
 */
final class ReplyTemplateSettingsService
{
    /** Präfix der gespeicherten Einstellungen in der `settings`-Tabelle. */
    public const PREFIX = 'helpdesk.reply_template.';

    /** Höchstlänge des Präfix-Textes in Zeichen. */
    public const MAX_PREFIX_LENGTH = 4000;

    /** Höchstlänge des Bestätigungstextes in Zeichen. */
    public const MAX_CONFIRMATION_LENGTH = 4000;

    /** Höchstlänge des Schließungstextes in Zeichen. */
    public const MAX_CLOSE_LENGTH = 4000;

    /** Höchstlänge des Fuß-Hinweises in Zeichen. */
    public const MAX_FOOTER_LENGTH = 1000;

    /** Fuß-Hinweis, solange weder in der Datenbank noch in der Konfiguration etwas hinterlegt ist. */
    public const DEFAULT_FOOTER_TEXT = 'Diese E-Mail wurde automatisch vom IT Help Desk ({firma}) erzeugt. Antworten auf diese E-Mail werden nicht verarbeitet.';

    /**
     * Platzhalter der Eingangsbestätigung mit Beschreibung für die Administration.
     * @var array<string,string>
     */
    public const PLACEHOLDERS = [
        '{anrede}' => 'Anrede, z. B. „Guten Tag“',
        '{name}' => 'Name des Empfängers',
        '{ticketnummer}' => 'Ticketnummer, z. B. HD-2026-000123',
        '{betreff}' => 'Betreff des Tickets',
        '{datum}' => 'Eingangsdatum und -uhrzeit',
        '{link}' => 'Link zum Ticket im Self-Service-Portal',
        '{firma}' => 'Firmenname aus der Konfiguration',
    ];

    /**
     * Platzhalter der Schließungsbenachrichtigung mit Beschreibung für die Administration.
     * @var array<string,string>
     */
    public const CLOSE_PLACEHOLDERS = [
        '{anrede}' => 'Anrede, z. B. „Guten Tag“',
        '{name}' => 'Name des Empfängers',
        '{ticketnummer}' => 'Ticketnummer, z. B. HD-2026-000123',
        '{betreff}' => 'Betreff des Tickets',
        '{datum}' => 'Eingangsdatum und -uhrzeit',
        '{geschlossen_am}' => 'Zeitpunkt der Schließung',
        '{schliessungsgrund}' => 'Schließungsgrund aus dem Statusdialog',
        '{loesung}' => 'Lösung aus dem Ticket',
        '{link}' => 'Link zum Ticket im Self-Service-Portal',
        '{firma}' => 'Firmenname aus der Konfiguration',
    ];

    /**
     * Platzhalter des Fuß-Hinweises (er gilt für alle Mails, nicht nur für ein Ticket).
     * @var array<string,string>
     */
    public const FOOTER_PLACEHOLDERS = [
        '{firma}' => 'Firmenname aus der Konfiguration',
    ];

    /** @param SettingsService $settings Zugriff auf die `settings`-Tabelle (mit Präfix) */
    public function __construct(
        private readonly SettingsService $settings,
        private readonly Config $config,
        private readonly AuditLogService $audit,
        private readonly CurrentUser $currentUser
    ) {
    }

    /**
     * Aktuelle Vorlageneinstellungen (Datenbank vor Konfiguration).
     *
     * @return array{prefix_text:string,confirmation_text:string,close_text:string,footer_text:string}
     */
    public function all(): array
    {
        $stored = $this->settings->withPrefix(self::PREFIX);
        $text = array_key_exists('prefix_text', $stored)
            ? (string) $stored['prefix_text']
            : (string) $this->config->get('helpdesk.reply_template.prefix_text', '');
        $confirmation = array_key_exists('confirmation_text', $stored)
            ? (string) $stored['confirmation_text']
            : (string) $this->config->get('helpdesk.reply_template.confirmation_text', '');
        $close = array_key_exists('close_text', $stored)
            ? (string) $stored['close_text']
            : (string) $this->config->get('helpdesk.reply_template.close_text', '');
        // Ohne gespeicherten Wert gilt der Startwert aus der Konfiguration, sonst der Standardtext:
        // bestehende Installationen behalten den gewohnten Hinweis am Fuß.
        $footer = array_key_exists('footer_text', $stored)
            ? (string) $stored['footer_text']
            : $this->configuredFooter();

        return [
            'prefix_text' => trim($text),
            'confirmation_text' => trim($confirmation),
            'close_text' => trim($close),
            'footer_text' => trim($footer),
        ];
    }

    /**
     * Fertiges HTML des Präfix-Textes für den Versand (leer, wenn kein Präfix hinterlegt ist).
     */
    public function prefixHtml(): string
    {
        return self::toHtml($this->all()['prefix_text']);
    }

    /** Roh-Text der Eingangsbestätigung (leer = es wird keine Bestätigung versendet). */
    public function confirmationText(): string
    {
        return $this->all()['confirmation_text'];
    }

    /**
     * Roh-Text der Schließungsbenachrichtigung (leer = es gilt die allgemeine Statusmeldung).
     */
    public function closeText(): string
    {
        return $this->all()['close_text'];
    }

    /**
     * Fertiges HTML des Fuß-Hinweises für den Mailrahmen (leer = kein Hinweis).
     */
    public function footerHtml(): string
    {
        $text = $this->all()['footer_text'];
        if ($text === '') {
            return '';
        }
        $company = trim((string) $this->config->get('app.company_name', ''));
        if ($company === '') {
            // Ohne Firmennamen bliebe sonst ein leeres Klammerpaar im Satz stehen.
            $text = str_replace([' ({firma})', '({firma}) ', '({firma})'], '', $text);
        }

        return self::render($text, ['{firma}' => $company]);
    }

    /**
     * Wandelt den Präfix-Text in abgesichertes HTML um: Fließtext wird maskiert, Absätze
     * (Leerzeilen) werden zu `<p>`, einfache Zeilenumbrüche zu `<br>`.
     */
    public static function toHtml(string $text): string
    {
        $text = trim(str_replace(["\r\n", "\r"], "\n", $text));
        if ($text === '') {
            return '';
        }

        $html = '';
        foreach (preg_split('/\n{2,}/', $text) ?: [] as $paragraph) {
            $paragraph = trim($paragraph);
            if ($paragraph === '') {
                continue;
            }
            $html .= '<p>' . str_replace("\n", '<br>', self::escape($paragraph)) . '</p>';
        }

        return $html;
    }

    /**
     * Setzt die Platzhalter in einen Vorlagentext ein und wandelt das Ergebnis in HTML um.
     *
     * Der Text selbst wird maskiert (siehe toHtml), die eingesetzten Werte ebenfalls – aus der
     * Administration oder aus Stammdaten kann also kein HTML in die Mail gelangen.
     *
     * @param array<string,string> $values Platzhalter inklusive Klammern → Wert
     */
    public static function render(string $template, array $values): string
    {
        $replace = [];
        foreach ($values as $placeholder => $value) {
            $value = (string) $value;
            if ($value === '') {
                // Leere Werte hinterlassen keine doppelten Leerzeichen („{anrede} {name}“).
                $template = str_replace([$placeholder . ' ', ' ' . $placeholder], '', $template);
                $template = str_replace($placeholder, '', $template);
            }
            $replace[$placeholder] = self::escape($value);
        }

        return strtr(self::toHtml($template), $replace);
    }

    /**
     * Speichert die Vorlageneinstellungen.
     *
     * @param array<string,mixed> $input Rohdaten aus dem Formular
     * @return array{prefix_text:string,confirmation_text:string,close_text:string,footer_text:string}
     */
    public function save(array $input): array
    {
        $this->currentUser->require('helpdesk.admin');

        $before = $this->all();
        $data = $this->validate($input);
        $this->settings->setMany([
            self::PREFIX . 'prefix_text' => $data['prefix_text'],
            self::PREFIX . 'confirmation_text' => $data['confirmation_text'],
            self::PREFIX . 'close_text' => $data['close_text'],
            self::PREFIX . 'footer_text' => $data['footer_text'],
        ], $this->currentUser->id());
        $after = $this->all();

        $this->audit->log('update', 'settings', null, 'Help-Desk-Vorlageneinstellungen', $before, $after);

        return $after;
    }

    /**
     * Prüft und normalisiert die Eingaben.
     *
     * @param array<string,mixed> $input
     * @return array{prefix_text:string,confirmation_text:string,close_text:string,footer_text:string}
     */
    public function validate(array $input): array
    {
        $validator = new Validator($input);
        $validator->text('prefix_text', 'Präfix-Text', false, self::MAX_PREFIX_LENGTH);
        $validator->text('confirmation_text', 'Bestätigungstext', false, self::MAX_CONFIRMATION_LENGTH);
        $validator->text('close_text', 'Schließungstext', false, self::MAX_CLOSE_LENGTH);
        $validator->text('footer_text', 'Fuß-Hinweis', false, self::MAX_FOOTER_LENGTH);
        $clean = $validator->validated();

        return [
            'prefix_text' => trim((string) ($clean['prefix_text'] ?? '')),
            'confirmation_text' => trim((string) ($clean['confirmation_text'] ?? '')),
            'close_text' => trim((string) ($clean['close_text'] ?? '')),
            'footer_text' => trim((string) ($clean['footer_text'] ?? '')),
        ];
    }

    /** Startwert des Fuß-Hinweises aus der Konfiguration (leer = Standardtext). */
    private function configuredFooter(): string
    {
        $value = trim((string) $this->config->get('helpdesk.reply_template.footer_text', ''));

        return $value !== '' ? $value : self::DEFAULT_FOOTER_TEXT;
    }

    private static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
