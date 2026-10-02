<?php

declare(strict_types=1);

namespace App\Services\Storage;

/**
 * Einstellungen der Erkennung auffaelligen Ueberschreibens (Ransomware-Schutz)
 * im Speicher-Tiering (Tabelle settings, Praefix incident_).
 *
 * Wird vom Adminbereich (Vorfaelle) und vom Container storage-sync gelesen.
 */
final class IncidentSettings
{
    /**
     * @var array<string,array{default:string,min:int,max:int}>
     */
    public const NUMERIC = [
        'incident_window_minutes' => ['default' => '10', 'min' => 1, 'max' => 1440],
        'incident_overwrite_files' => ['default' => '200', 'min' => 10, 'max' => 1000000],
        'incident_content_files' => ['default' => '20', 'min' => 1, 'max' => 1000000],
        'incident_extension_files' => ['default' => '1', 'min' => 1, 'max' => 100000],
        'incident_protect_target' => ['default' => '0', 'min' => 0, 'max' => 4294967295],
    ];

    public const BOOLEAN = [
        'incident_detection_enabled' => '1',
        // 1 = zusaetzlich ein Cold-Ziel schreibgeschuetzt aus dem Sync nehmen, 0 = nur den Benutzer einschraenken
        'incident_freeze_target' => '1',
    ];

    public const MAX_PATTERNS = 1000;
    public const MAX_PATTERN_LENGTH = 100;
    public const SUPPORT_MAX_LENGTH = 200;

    /**
     * Bekannte Dateiendungen und Dateinamen nach einem Ransomware-Befall
     * (Endung ohne Platzhalter, sonst Muster mit * und ? fuer den Dateinamen).
     */
    public const DEFAULT_PATTERNS = [
        // Makop, Phobos, Dharma, STOP/Djvu, LockBit, Conti, Ryuk u. a.
        'makop', 'mkp', 'crypt', 'crypted', 'cryptolocker', 'crypz', 'cryp1', 'encrypted', 'enciphered', 'locked', 'lockbit',
        'locky', 'zepto', 'odin', 'thor', 'aesir', 'osiris', 'cerber', 'cerber2', 'cerber3', 'wnry', 'wncry', 'wncryt', 'wcry',
        'onion', 'conti', 'ryk', 'ryuk', 'phobos', 'eking', 'eight', 'dharma', 'wallet', 'arena', 'basta', 'akira', 'royal',
        'rhysida', 'babyk', 'hive', 'maze', 'medusa', '8base', 'cuba', 'nefilim', 'nemty', 'karma', 'djvu', 'djvuu', 'uudjvu',
        'lockfile', 'blackbyte', 'micro', 'vvv', 'ccc', 'zzz', 'ecc', 'ezz', 'exx', 'ttt', 'crinf', 'r5a', 'xrtn', 'xort',
        'rrk', 'kraken', 'darkness', 'nochance', 'vault', 'zzzzz', 'lechiffre', 'ctbl', 'ctb2', 'ha3', 'toxcrypt', 'pzdc',
        'kimcilware', 'rokku', 'lesli', 'sage', 'globe', 'purge', 'exotic', 'payrms', 'paymts', 'paym', 'payransom', 'cl0p',
        'clop', 'ragnar', 'lockergoga', 'mallox', 'trigona', 'blackhunt', 'bianlian', 'qilin', 'agenda', 'nokoyawa',
        // Typische Namensmuster (Kennung und Kontakt-Adresse im Dateinamen, z. B. Makop/Phobos)
        '*.id-*.[*@*].*', '*.id[*].[*@*].*', '*.[*@*].*',
        // Erpresserschreiben
        'readme-warning.txt', '+readme-warning+.txt', 'how_to_decrypt*', '*decrypt_instruction*', '*decrypt-instruction*',
        'restore_my_files*', 'restore-my-files*', '*recover_your_files*', 'readme_for_decrypt*', '!!!*decrypt*',
    ];

    /** @var array<string,string> */
    private array $values;

    /** @var list<string>|null */
    private ?array $patterns = null;

    /** @var list<string>|null Regulaere Ausdruecke (Muster) */
    private ?array $regexes = null;

    /** @var array<string,true>|null Endungen ohne Platzhalter */
    private ?array $extensions = null;

    /**
     * @param array<string,string> $settings Einstellungen (settings-Tabelle)
     */
    public function __construct(array $settings)
    {
        $known = self::defaults();
        $this->values = array_merge($known, array_intersect_key($settings, $known));
    }

    /**
     * @return array<string,string>
     */
    public static function defaults(): array
    {
        $defaults = self::BOOLEAN;
        foreach (self::NUMERIC as $key => $meta) {
            $defaults[$key] = $meta['default'];
        }
        $defaults['incident_extensions'] = implode("\n", self::DEFAULT_PATTERNS);
        $defaults['incident_support_contact'] = '';

        return $defaults;
    }

    /**
     * Prueft Formulareingaben.
     *
     * @param array<string,mixed> $input
     *
     * @return array{values:array<string,string>,errors:array<string,string>}
     */
    public static function validate(array $input): array
    {
        $values = [];
        $errors = [];
        foreach (array_keys(self::BOOLEAN) as $key) {
            $values[$key] = !empty($input[$key]) && $input[$key] !== '0' ? '1' : '0';
        }
        foreach (self::NUMERIC as $key => $meta) {
            $raw = trim((string) ($input[$key] ?? $meta['default']));
            if (preg_match('/^\d{1,10}$/', $raw) !== 1 || (int) $raw < $meta['min'] || (int) $raw > $meta['max']) {
                $errors[$key] = sprintf('Bitte eine ganze Zahl zwischen %d und %d angeben.', $meta['min'], $meta['max']);
                $values[$key] = $raw;
                continue;
            }
            $values[$key] = (string) (int) $raw;
        }

        $patterns = [];
        $invalid = [];
        foreach (self::splitPatterns((string) ($input['incident_extensions'] ?? '')) as $pattern) {
            if (mb_strlen($pattern) > self::MAX_PATTERN_LENGTH || preg_match('#[\x00-\x1F\x7F/\\\\]#', $pattern) === 1
                || trim($pattern, '*?.') === '') {
                $invalid[] = $pattern;
                continue;
            }
            $patterns[$pattern] = true;
        }
        $values['incident_extensions'] = implode("\n", array_keys($patterns));
        if ($invalid !== []) {
            $errors['incident_extensions'] = 'Ungültige Einträge (keine Schrägstriche, nicht nur Platzhalter, höchstens '
                . self::MAX_PATTERN_LENGTH . ' Zeichen): ' . implode(', ', array_slice($invalid, 0, 5));
        } elseif (count($patterns) > self::MAX_PATTERNS) {
            $errors['incident_extensions'] = sprintf('Höchstens %d Einträge.', self::MAX_PATTERNS);
        } elseif ($patterns === []) {
            $errors['incident_extensions'] = 'Bitte mindestens eine Dateiendung angeben (z. B. „makop“).';
        }

        $support = trim(preg_replace('/\s+/u', ' ', (string) ($input['incident_support_contact'] ?? '')) ?? '');
        if (mb_strlen($support) > self::SUPPORT_MAX_LENGTH) {
            $errors['incident_support_contact'] = sprintf('Höchstens %d Zeichen.', self::SUPPORT_MAX_LENGTH);
        }
        $values['incident_support_contact'] = mb_substr($support, 0, self::SUPPORT_MAX_LENGTH);

        return ['values' => $values, 'errors' => $errors];
    }

    /**
     * Eintraege aus Textfeld (eine Zeile oder durch Komma/Semikolon getrennt),
     * in Kleinschreibung, ohne Dubletten.
     *
     * @return list<string>
     */
    public static function splitPatterns(string $raw): array
    {
        $result = [];
        foreach (preg_split('/[\r\n,;]+/', $raw) ?: [] as $entry) {
            $entry = mb_strtolower(trim($entry));
            if ($entry === '') {
                continue;
            }
            // "*.makop" und ".makop" bedeuten dasselbe wie "makop".
            if (preg_match('/^\*?\.([^.*?]+)$/u', $entry, $match) === 1) {
                $entry = $match[1];
            }
            $result[$entry] = true;
        }

        return array_keys($result);
    }

    public function enabled(): bool
    {
        return $this->values['incident_detection_enabled'] === '1';
    }

    public function int(string $key): int
    {
        return (int) ($this->values[$key] ?? self::NUMERIC[$key]['default'] ?? 0);
    }

    public function windowSeconds(): int
    {
        return $this->int('incident_window_minutes') * 60;
    }

    /** Geaenderte Dateien eines Benutzers im Zeitfenster, ab denen ein Vorfall ausgeloest wird. */
    public function overwriteThreshold(): int
    {
        return $this->int('incident_overwrite_files');
    }

    /** Ueberschriebene Dateien mit verdaechtigem (verschluesseltem) Inhalt im Zeitfenster. */
    public function contentThreshold(): int
    {
        return $this->int('incident_content_files');
    }

    /** Geschriebene Dateien mit Ransomware-Endung im Zeitfenster. */
    public function extensionThreshold(): int
    {
        return $this->int('incident_extension_files');
    }

    /** Soll bei einem Vorfall zusaetzlich ein Cold-Ziel schreibgeschuetzt aus dem Sync genommen werden? */
    public function freezeTarget(): bool
    {
        return $this->values['incident_freeze_target'] === '1';
    }

    /** 0 = Schutzziel automatisch waehlen. */
    public function protectTargetId(): int
    {
        return $this->int('incident_protect_target');
    }

    public function supportContact(): string
    {
        return $this->values['incident_support_contact'];
    }

    /**
     * @return list<string>
     */
    public function patterns(): array
    {
        return $this->patterns ??= self::splitPatterns($this->values['incident_extensions']);
    }

    /**
     * Passt der Dateiname auf eine Ransomware-Endung bzw. ein Muster?
     * Liefert den passenden Eintrag oder null.
     */
    public function matchName(string $name): ?string
    {
        $name = mb_strtolower($name);
        if ($name === '') {
            return null;
        }
        $this->compile();
        $dot = strrpos($name, '.');
        if ($dot !== false && $dot > 0) {
            $extension = substr($name, $dot + 1);
            if (isset($this->extensions[$extension])) {
                return $extension;
            }
        }
        foreach ($this->regexes as $pattern => $regex) {
            if (preg_match($regex, $name) === 1) {
                return $pattern;
            }
        }

        return null;
    }

    /**
     * Meldung fuer betroffene Benutzer in Nextcloud.
     */
    public function userMessage(): string
    {
        $support = $this->supportContact();

        return 'Der Zugriff auf Ihre Dateien wurde aus Sicherheitsgründen vorübergehend eingeschränkt. '
            . 'Sie können Dateien weiterhin öffnen und herunterladen, aber nicht ändern, hochladen, umbenennen oder löschen. '
            . 'Bitte melden Sie sich beim Support' . ($support !== '' ? ' (' . $support . ')' : '') . '.';
    }

    /**
     * @return array<string,string>
     */
    public function all(): array
    {
        return $this->values;
    }

    private function compile(): void
    {
        if ($this->extensions !== null && $this->regexes !== null) {
            return;
        }
        $this->extensions = [];
        $this->regexes = [];
        foreach ($this->patterns() as $pattern) {
            if (!str_contains($pattern, '*') && !str_contains($pattern, '?') && !str_contains($pattern, '.')) {
                $this->extensions[$pattern] = true;
                continue;
            }
            $regex = '';
            foreach (preg_split('/([*?])/', $pattern, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) ?: [] as $part) {
                $regex .= match ($part) {
                    '*' => '.*',
                    '?' => '.',
                    default => preg_quote($part, '/'),
                };
            }
            $this->regexes[$pattern] = '/^' . $regex . '$/su';
        }
    }
}
