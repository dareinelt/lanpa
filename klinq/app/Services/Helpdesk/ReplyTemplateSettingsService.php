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
 */
final class ReplyTemplateSettingsService
{
    /** Präfix der gespeicherten Einstellungen in der `settings`-Tabelle. */
    public const PREFIX = 'helpdesk.reply_template.';

    /** Höchstlänge des Präfix-Textes in Zeichen. */
    public const MAX_PREFIX_LENGTH = 4000;

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
     * @return array{prefix_text:string}
     */
    public function all(): array
    {
        $stored = $this->settings->withPrefix(self::PREFIX);
        $text = array_key_exists('prefix_text', $stored)
            ? (string) $stored['prefix_text']
            : (string) $this->config->get('helpdesk.reply_template.prefix_text', '');

        return ['prefix_text' => trim($text)];
    }

    /**
     * Fertiges HTML des Präfix-Textes für den Versand (leer, wenn kein Präfix hinterlegt ist).
     */
    public function prefixHtml(): string
    {
        return self::toHtml($this->all()['prefix_text']);
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
     * Speichert die Vorlageneinstellungen.
     *
     * @param array<string,mixed> $input Rohdaten aus dem Formular
     * @return array{prefix_text:string}
     */
    public function save(array $input): array
    {
        $this->currentUser->require('helpdesk.admin');

        $before = $this->all();
        $data = $this->validate($input);
        $this->settings->setMany([self::PREFIX . 'prefix_text' => $data['prefix_text']], $this->currentUser->id());
        $after = $this->all();

        $this->audit->log('update', 'settings', null, 'Help-Desk-Vorlageneinstellungen', $before, $after);

        return $after;
    }

    /**
     * Prüft und normalisiert die Eingaben.
     *
     * @param array<string,mixed> $input
     * @return array{prefix_text:string}
     */
    public function validate(array $input): array
    {
        $validator = new Validator($input);
        $validator->text('prefix_text', 'Präfix-Text', false, self::MAX_PREFIX_LENGTH);
        $clean = $validator->validated();

        return ['prefix_text' => trim((string) ($clean['prefix_text'] ?? ''))];
    }

    private static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
