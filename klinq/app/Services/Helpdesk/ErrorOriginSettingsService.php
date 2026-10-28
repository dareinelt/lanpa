<?php

declare(strict_types=1);

namespace App\Services\Helpdesk;

use App\Security\CurrentUser;
use App\Services\AuditLogService;
use App\Services\SettingsService;
use App\Support\Validator;

/**
 * Katalog der Fehlerursprünge (Help-Desk-Einstellungen → Reiter „Fehlerursprung“).
 *
 * Der Agent wählt beim Schließen eines Tickets im Schließen-Dialog einen Eintrag aus diesem
 * Katalog. Der Wert landet in `tickets.error_origin` und steht im Schließungstext als
 * `{fehlerursprung}` zur Verfügung. Gepflegt wird eine Liste (ein Eintrag pro Zeile) in der
 * `settings`-Tabelle unter dem Präfix `helpdesk.error_origin.`; ohne gespeicherten Wert gelten
 * die Standardpunkte.
 */
final class ErrorOriginSettingsService
{
    /** Präfix der gespeicherten Einstellungen in der `settings`-Tabelle. */
    public const PREFIX = 'helpdesk.error_origin.';

    /** Standardpunkte, solange in der Datenbank nichts hinterlegt ist. */
    public const DEFAULT_ORIGINS = ['Anwender', 'Hardware-Fehler', 'Software-Fehler', 'Netzwerk'];

    /** Höchstlänge des Listenfeldes in Zeichen. */
    public const MAX_TEXT_LENGTH = 4000;

    /** Höchstlänge eines einzelnen Eintrags in Zeichen (entspricht `tickets.error_origin`). */
    public const MAX_ORIGIN_LENGTH = 120;

    /** Höchstzahl der Einträge. */
    public const MAX_ORIGINS = 50;

    public function __construct(
        private readonly SettingsService $settings,
        private readonly AuditLogService $audit,
        private readonly CurrentUser $currentUser
    ) {}

    /**
     * Effektive Einstellungen; ein leerer Katalog fällt auf die Standardpunkte zurück.
     * @return array{origins_text:string,origins:list<string>}
     */
    public function all(): array
    {
        $stored = $this->settings->withPrefix(self::PREFIX);
        $origins = self::parse((string) ($stored['catalog'] ?? ''));
        if ($origins === []) {
            $origins = self::DEFAULT_ORIGINS;
        }

        return ['origins_text' => implode("\n", $origins), 'origins' => $origins];
    }

    /** @return list<string> */
    public function origins(): array
    {
        return $this->all()['origins'];
    }

    /**
     * Katalog speichern; protokolliert die Änderung im Audit-Log.
     * @param array<string,mixed> $input
     * @return array{origins_text:string,origins:list<string>}
     */
    public function save(array $input): array
    {
        $this->currentUser->require('helpdesk.admin');
        $before = $this->all();

        $v = new Validator($input);
        $v->text('origins_text', 'Fehlerursprünge', false, self::MAX_TEXT_LENGTH);
        $origins = self::parse((string) ($v->value('origins_text') ?? ''));
        if (count($origins) > self::MAX_ORIGINS) {
            $v->addError('origins_text', 'Es sind höchstens ' . self::MAX_ORIGINS . ' Fehlerursprünge hinterlegbar.');
        }
        foreach ($origins as $origin) {
            if (mb_strlen($origin) > self::MAX_ORIGIN_LENGTH) {
                $v->addError('origins_text', 'Ein Fehlerursprung darf höchstens ' . self::MAX_ORIGIN_LENGTH . ' Zeichen lang sein: ' . $origin);

                break;
            }
        }
        $v->set('origins_text', implode("\n", $origins !== [] ? $origins : self::DEFAULT_ORIGINS));
        /** @var array{origins_text:string} $clean */
        $clean = $v->validated();

        $this->settings->setMany([self::PREFIX . 'catalog' => $clean['origins_text']], $this->currentUser->id());

        $after = $this->all();
        $this->audit->log('update', 'settings', null, 'Help-Desk-Fehlerursprung', $before, $after);

        return $after;
    }

    /**
     * Liste aus Textfeld in Einträge überführen (ein Eintrag pro Zeile, Duplikate entfallen).
     * @return list<string>
     */
    public static function parse(string $raw): array
    {
        $origins = [];
        foreach (preg_split('/[\r\n]+/', $raw) ?: [] as $line) {
            $line = trim($line);
            if ($line !== '' && !in_array($line, $origins, true)) {
                $origins[] = $line;
            }
        }

        return $origins;
    }
}
