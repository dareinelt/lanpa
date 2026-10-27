<?php

declare(strict_types=1);

namespace App\Services\Helpdesk;

use App\Core\Config;
use App\Security\CurrentUser;
use App\Services\AuditLogService;
use App\Services\SettingsService;
use App\Support\Validator;

/**
 * Einstellungen des öffentlichen Störungsformulars, die in der Administration gepflegt werden
 * (Help-Desk-Einstellungen → Reiter „Störungsformular“).
 *
 * Gepflegt wird ein Katalog an AD-/SSO-Anmeldenamen („Sammelkonten“ bzw. anonyme Konten).
 * Meldet sich ein solches Konto am Formular, ist die Angabe eines zusätzlichen Benutzers
 * Pflicht; der zugehörige Erklärungstext ist frei anpassbar. Gespeichert wird in
 * `system_settings` unter dem Präfix `helpdesk.quick_report.`; die Umgebung liefert nur
 * Startwerte, solange in der Datenbank nichts hinterlegt ist.
 */
final class QuickReportSettingsService
{
    public const PREFIX = 'helpdesk.quick_report.';

    /** Erklärungstext, wenn weder Datenbank noch Umgebung einen eigenen Text vorgeben. */
    public const DEFAULT_SHARED_ACCOUNTS_HINT = 'Für dieses Konto ist die Angabe eines zusätzlichen Benutzers erforderlich. Bitte wählen Sie die betroffene Person aus, damit die Meldung zugeordnet werden kann.';

    private const MAX_ACCOUNTS = 500;
    private const MAX_HINT_LENGTH = 1000;

    public function __construct(
        private readonly SettingsService $settings,
        private readonly Config $config,
        private readonly AuditLogService $audit,
        private readonly CurrentUser $currentUser
    ) {}

    /**
     * Effektive Einstellungen (Datenbank vor Umgebung), Konten normalisiert.
     * @return array{shared_accounts:string,shared_accounts_hint:string}
     */
    public function all(): array
    {
        $stored = $this->settings->withPrefix(self::PREFIX);

        $accounts = $stored['shared_accounts'] ?? null;
        if ($accounts === null) {
            $accounts = implode("\n", array_map('strval', (array) $this->config->get('helpdesk.quick_report.shared_accounts', [])));
        }

        $hint = trim((string) ($stored['shared_accounts_hint'] ?? ''));
        if ($hint === '') {
            $hint = trim((string) $this->config->get('helpdesk.quick_report.shared_accounts_hint', ''));
        }
        if ($hint === '') {
            $hint = self::DEFAULT_SHARED_ACCOUNTS_HINT;
        }

        return [
            'shared_accounts' => self::formatAccounts(self::normalizeAccounts($accounts)),
            'shared_accounts_hint' => $hint,
        ];
    }

    /** @return list<string> */
    public function sharedAccounts(): array
    {
        return self::normalizeAccounts((string) $this->all()['shared_accounts']);
    }

    public function sharedAccountsHint(): string
    {
        return (string) $this->all()['shared_accounts_hint'];
    }

    /** Ist für dieses Konto die Angabe eines zusätzlichen Benutzers Pflicht? */
    public function requiresAffectedUser(?string $username): bool
    {
        return self::isSharedAccount($username, $this->sharedAccounts());
    }

    /**
     * Einstellungen speichern; protokolliert die Änderung im Audit-Log.
     * @param array<string,mixed> $input
     * @return array{shared_accounts:string,shared_accounts_hint:string}
     */
    public function save(array $input): array
    {
        $this->currentUser->require('helpdesk.admin');
        $before = $this->all();
        $data = $this->validate($input);

        $this->settings->setMany([
            self::PREFIX . 'shared_accounts' => self::formatAccounts($data['shared_accounts']),
            self::PREFIX . 'shared_accounts_hint' => $data['shared_accounts_hint'],
        ], $this->currentUser->id());

        $after = $this->all();
        $this->audit->log('update', 'settings', null, 'Help-Desk-Störungsformular', $before, $after);

        return $after;
    }

    /**
     * Eingaben prüfen und in typisierte Werte überführen.
     * @param array<string,mixed> $input
     * @return array{shared_accounts:list<string>,shared_accounts_hint:string}
     */
    public function validate(array $input): array
    {
        $v = new Validator($input);
        $v->text('shared_accounts', 'Sammelkonten', false, 20000)
            ->text('shared_accounts_hint', 'Erklärungstext', false, self::MAX_HINT_LENGTH);

        $accounts = self::normalizeAccounts((string) ($v->value('shared_accounts') ?? ''));
        foreach (self::candidates((string) ($input['shared_accounts'] ?? '')) as $candidate) {
            if (ReporterIdentityService::normalize($candidate) === null) {
                $v->addError('shared_accounts', 'Ungültiger Anmeldename: ' . $candidate);

                break;
            }
        }
        if (count($accounts) > self::MAX_ACCOUNTS) {
            $v->addError('shared_accounts', 'Es sind höchstens ' . self::MAX_ACCOUNTS . ' Sammelkonten hinterlegbar.');
        }
        $v->set('shared_accounts', $accounts);

        $hint = trim((string) ($v->value('shared_accounts_hint') ?? ''));
        $v->set('shared_accounts_hint', $hint !== '' ? $hint : self::DEFAULT_SHARED_ACCOUNTS_HINT);

        /** @var array{shared_accounts:list<string>,shared_accounts_hint:string} $clean */
        $clean = $v->validated();

        return $clean;
    }

    /**
     * Liste aus Textfeld/Umgebung in normalisierte Anmeldenamen überführen
     * (ein Eintrag pro Zeile, zusätzlich Komma und Semikolon als Trenner).
     * @return list<string>
     */
    public static function normalizeAccounts(string $raw): array
    {
        $accounts = [];
        foreach (self::candidates($raw) as $candidate) {
            $username = ReporterIdentityService::normalize($candidate);
            if ($username !== null && !in_array($username, $accounts, true)) {
                $accounts[] = $username;
            }
        }

        return $accounts;
    }

    /**
     * Ist der (bereits normalisierte) Anmeldename im Katalog enthalten?
     * @param list<string> $accounts
     */
    public static function isSharedAccount(?string $username, array $accounts): bool
    {
        $normalized = ReporterIdentityService::normalize($username);

        return $normalized !== null && in_array($normalized, $accounts, true);
    }

    /** @param list<string> $accounts */
    public static function formatAccounts(array $accounts): string
    {
        return implode("\n", $accounts);
    }

    /** @return list<string> */
    private static function candidates(string $raw): array
    {
        $parts = preg_split('/[\r\n,;]+/', $raw) ?: [];
        $candidates = [];
        foreach ($parts as $part) {
            $part = trim($part);
            if ($part !== '') {
                $candidates[] = $part;
            }
        }

        return $candidates;
    }
}
