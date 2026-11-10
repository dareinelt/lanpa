<?php

declare(strict_types=1);

/**
 * View-Hilfsfunktionen (nur Darstellung, keine Geschäftslogik).
 */

if (!function_exists('e')) {
    function e(mixed $value): string
    {
        return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

if (!function_exists('fmt_date')) {
    function fmt_date(?string $value, string $fallback = '–'): string
    {
        if ($value === null || $value === '' || str_starts_with($value, '0000')) {
            return $fallback;
        }
        $ts = strtotime($value);

        return $ts === false ? $fallback : date('d.m.Y', $ts);
    }
}

if (!function_exists('fmt_datetime')) {
    function fmt_datetime(?string $value, string $fallback = '–'): string
    {
        if ($value === null || $value === '') {
            return $fallback;
        }
        $dt = date_create($value, new DateTimeZone('UTC'));
        if ($dt === false) {
            return $fallback;
        }
        $dt->setTimezone(new DateTimeZone(date_default_timezone_get()));

        return $dt->format('d.m.Y H:i');
    }
}

if (!function_exists('sla_progress_attrs')) {
    /**
     * Datenattribute für die Live-Berechnung des SLA-Zählers im Browser.
     * Nur bei Geschäftszeiten-Regeln gesetzt; ohne sie rechnet das JS kalendarisch.
     *
     * @param array{elapsed:int,total:int,windows:string,tz:string,at?:int}|null $progress
     */
    function sla_progress_attrs(?array $progress): string
    {
        if ($progress === null) {
            return '';
        }

        return ' data-hd-sla-bh="1"'
            . ' data-hd-sla-elapsed="' . (int) $progress['elapsed'] . '"'
            . ' data-hd-sla-total="' . (int) $progress['total'] . '"'
            . ' data-hd-sla-at="' . (int) ($progress['at'] ?? time()) . '"'
            . ' data-hd-sla-windows="' . e((string) $progress['windows']) . '"'
            . ' data-hd-sla-tz="' . e((string) $progress['tz']) . '"';
    }
}

if (!function_exists('sla_hours')) {
    /**
     * Dienstzeiten einer SLA-Regel je Wochentag (1 = Mo … 7 = So) als "HH:MM".
     *
     * @param array<string,mixed> $sla
     * @return array<int,array{0:string,1:string}>
     */
    function sla_hours(array $sla): array
    {
        $hours = [];
        foreach (\App\Services\Helpdesk\TicketSlaService::businessWindows($sla) as $day => $span) {
            $hours[$day] = [
                \App\Services\Helpdesk\TicketSlaService::minutesToTime($span[0]),
                \App\Services\Helpdesk\TicketSlaService::minutesToTime($span[1]),
            ];
        }

        return $hours;
    }
}

if (!function_exists('sla_hours_label')) {
    /**
     * Dienstzeiten kompakt für Listen: aufeinanderfolgende Tage mit gleichen Zeiten werden
     * zusammengefasst, z. B. „Mo–Fr 08:00–17:00, Sa 09:00–12:00“.
     *
     * @param array<string,mixed> $sla
     */
    function sla_hours_label(array $sla): string
    {
        $hours = sla_hours($sla);
        if ($hours === []) {
            return '–';
        }
        $names = [1 => 'Mo', 2 => 'Di', 3 => 'Mi', 4 => 'Do', 5 => 'Fr', 6 => 'Sa', 7 => 'So'];
        $groups = [];
        foreach ($hours as $day => [$start, $end]) {
            $last = count($groups) - 1;
            if ($last >= 0 && $groups[$last][1] === $day - 1 && $groups[$last][2] === $start && $groups[$last][3] === $end) {
                $groups[$last][1] = $day;
                continue;
            }
            $groups[] = [$day, $day, $start, $end];
        }

        return implode(', ', array_map(
            static fn (array $group): string => ($group[0] === $group[1] ? $names[$group[0]] : $names[$group[0]] . '–' . $names[$group[1]]) . ' ' . $group[2] . '–' . $group[3],
            $groups
        ));
    }
}

if (!function_exists('fmt_money')) {
    function fmt_money(mixed $value, string $fallback = '–'): string
    {
        if ($value === null || $value === '') {
            return $fallback;
        }

        return number_format((float) $value, 2, ',', '.') . ' €';
    }
}

if (!function_exists('fmt_bytes')) {
    function fmt_bytes(int $bytes): string
    {
        if ($bytes >= 1048576) {
            return number_format($bytes / 1048576, 1, ',', '.') . ' MB';
        }
        if ($bytes >= 1024) {
            return number_format($bytes / 1024, 0, ',', '.') . ' KB';
        }

        return $bytes . ' B';
    }
}

if (!function_exists('old')) {
    function old(string $key, mixed $default = ''): string
    {
        $old = $_SESSION['_old_input'] ?? [];
        $value = $old[$key] ?? $default;

        return is_scalar($value) ? (string) $value : '';
    }
}

if (!function_exists('field_error')) {
    function field_error(string $key): string
    {
        $errors = $_SESSION['_errors'] ?? [];
        if (empty($errors[$key])) {
            return '';
        }

        return '<span class="form-error">' . e($errors[$key]) . '</span>';
    }
}

if (!function_exists('has_error')) {
    function has_error(string $key): bool
    {
        return !empty(($_SESSION['_errors'] ?? [])[$key]);
    }
}

if (!function_exists('csrf_field')) {
    function csrf_field(): string
    {
        return '<input type="hidden" name="_csrf" value="' . e($_SESSION['_csrf_token'] ?? '') . '">';
    }
}

if (!function_exists('selected')) {
    function selected(mixed $a, mixed $b): string
    {
        return (string) $a === (string) $b && (string) $a !== '' ? ' selected' : '';
    }
}

if (!function_exists('checked')) {
    function checked(mixed $value): string
    {
        return filter_var($value, FILTER_VALIDATE_BOOL) ? ' checked' : '';
    }
}

if (!function_exists('badge')) {
    /** Status-Badge mit Farbklasse (success, info, warning, danger, neutral). */
    function badge(?string $label, string $color = 'neutral'): string
    {
        $allowed = ['success', 'info', 'warning', 'danger', 'neutral'];
        $color = in_array($color, $allowed, true) ? $color : 'neutral';

        return '<span class="badge badge-' . $color . '">' . e($label ?? '–') . '</span>';
    }
}

if (!function_exists('query_url')) {
    /** Baut die aktuelle URL mit geänderten Query-Parametern (z. B. Paginierung/Sortierung). */
    function query_url(string $path, array $current, array $changes): string
    {
        $params = array_filter(array_merge($current, $changes), static fn ($v) => $v !== null && $v !== '');

        return $path . ($params === [] ? '' : '?' . http_build_query($params));
    }
}

if (!function_exists('icon')) {
    function icon(string $name, string $class = 'icon'): string
    {
        return '<svg class="' . e($class) . '" aria-hidden="true"><use href="#icon-' . e($name) . '"/></svg>';
    }
}

if (!function_exists('attachment_is_image')) {
    /** Bildanhang? Maßgeblich ist der MIME-Typ aus `documents`. */
    function attachment_is_image(array $document): bool
    {
        return str_starts_with((string) ($document['mime_type'] ?? ''), 'image/');
    }
}

if (!function_exists('attachment_icon')) {
    /** Auge für Bilder (öffnen im Overlay), Büroklammer für alles andere. */
    function attachment_icon(array $document, string $class = 'icon icon-xs'): string
    {
        return icon(attachment_is_image($document) ? 'eye' : 'paperclip', $class);
    }
}

if (!function_exists('attachment_overlay_attrs')) {
    /**
     * Öffnet Bildanhänge im Overlay von `public/js/ui.js`; ohne JavaScript bleibt der Link normal.
     *
     * @param array<string,mixed> $document
     */
    function attachment_overlay_attrs(array $document): string
    {
        if (!attachment_is_image($document)) {
            return '';
        }

        return ' data-image-overlay="1" data-image-title="' . e($document['original_name'] ?? '') . '"';
    }
}

if (!function_exists('nl2br_e')) {
    function nl2br_e(?string $value): string
    {
        return nl2br(e($value));
    }
}

if (!function_exists('rich_text')) {
    /** Freitext maskiert mit Zeilenumbrüchen und eingebetteten Base64-Bildern. */
    function rich_text(?string $value): string
    {
        return \App\Support\RichText::toHtml($value);
    }
}

if (!function_exists('mail_body')) {
    /**
     * Nachrichtentext für die Anzeige: Liegt eine HTML-Fassung einer E-Mail vor, wird sie isoliert
     * in einem `<iframe sandbox>` aus `$frameUrl` geladen (eigene CSP, Layout wie im Mailprogramm);
     * sonst der maskierte Klartext. Die Höhe passt `public/js/helpdesk-mail-frame.js` an.
     */
    function mail_body(?string $text, ?string $html, string $frameUrl): string
    {
        if ($html === null || trim($html) === '') {
            return rich_text($text);
        }

        return '<iframe class="mail-frame" src="' . e($frameUrl) . '" sandbox="' . e(\App\Support\MailFrame::SANDBOX) . '"'
            . ' title="E-Mail-Inhalt" loading="lazy" referrerpolicy="no-referrer" data-hd-mail-frame></iframe>';
    }
}

if (!function_exists('missing_labels')) {
    /** @param array<int,string>|string|null $missing */
    function missing_labels(array|string|null $missing): array
    {
        if (is_string($missing)) {
            $missing = json_decode($missing, true) ?: [];
        }
        $labels = [
            'location' => 'Standort fehlt',
            'employee' => 'Mitarbeiter fehlt',
            'cost_center' => 'Kostenstelle fehlt',
            'condition' => 'Zustand fehlt',
        ];

        return array_map(static fn (string $key): string => $labels[$key] ?? $key, $missing ?? []);
    }
}

if (!function_exists('form_value')) {
    /** Formularwert: zuerst alte Eingabe, sonst Datensatz, sonst Standard. */
    function form_value(?array $row, string $key, mixed $default = ''): string
    {
        $old = $_SESSION['_old_input'] ?? [];
        if (array_key_exists($key, $old)) {
            return is_scalar($old[$key]) ? (string) $old[$key] : '';
        }
        $value = $row[$key] ?? $default;

        return is_scalar($value) ? (string) $value : '';
    }
}

if (!function_exists('form_checked')) {
    /** Checkbox-Zustand: alte Eingabe, sonst Datensatz, sonst Standard (bei neuen Datensätzen). */
    function form_checked(?array $row, string $key, bool $default = true): string
    {
        $old = $_SESSION['_old_input'] ?? [];
        if ($old !== []) {
            return checked($old[$key] ?? false);
        }
        if ($row === null) {
            return $default ? ' checked' : '';
        }

        return checked($row[$key] ?? false);
    }
}

if (!function_exists('active_badge')) {
    function active_badge(mixed $isActive): string
    {
        return (int) $isActive === 1 ? badge('Aktiv', 'success') : badge('Inaktiv', 'neutral');
    }
}

if (!function_exists('sort_link')) {
    /** Sortierbarer Spaltenkopf. */
    function sort_link(string $path, array $query, string $column, string $label): string
    {
        $current = $query['sort'] ?? '';
        $dir = ($query['dir'] ?? 'asc') === 'asc' ? 'asc' : 'desc';
        $isActive = $current === $column;
        $nextDir = $isActive && $dir === 'asc' ? 'desc' : 'asc';
        $arrow = $isActive ? ($dir === 'asc' ? ' ▲' : ' ▼') : '';

        return '<a href="' . e(query_url($path, $query, ['sort' => $column, 'dir' => $nextDir, 'page' => null])) . '">' . e($label) . $arrow . '</a>';
    }
}

if (!function_exists('asset_url')) {
    /**
     * Statische Datei mit Cache-Busting-Parameter aus dem Dateiinhalt.
     *
     * Bewusst kein Zeitstempel: Deploys, die Dateizeiten bewahren (rsync -a,
     * Docker-Layer, entpackte Archive), würden sonst dieselbe Adresse behalten und
     * der Browser liefe mit einer veralteten Fassung weiter. CSS wird über den
     * gesamten Baum gehasht, weil die Kinder nur per @import geladen werden.
     */
    function asset_url(string $path): string
    {
        static $versions = [];
        if (isset($versions[$path])) {
            return $path . '?v=' . $versions[$path];
        }
        $file = dirname(__DIR__, 3) . '/public' . $path;
        $version = '0';
        if (str_ends_with($path, '.css')) {
            $context = hash_init('sha256');
            $base = dirname($file);
            $children = [];
            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS)) as $f) {
                $children[] = $f->getPathname();
            }
            sort($children); // stabile Reihenfolge unabhängig von der Verzeichnisausgabe
            foreach ($children as $child) {
                hash_update($context, substr($child, strlen($base)) . ':' . hash_file('sha256', $child) . "\n");
            }
            $version = substr(hash_final($context), 0, 12);
        } elseif (is_file($file)) {
            $version = substr((string) hash_file('sha256', $file), 0, 12);
        }

        return $path . '?v=' . ($versions[$path] = $version);
    }
}
