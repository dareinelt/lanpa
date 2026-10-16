<?php

declare(strict_types=1);

namespace App\Services\Orvanta;

use App\Support\Html;

/**
 * Betreff und Begleittext der Kalendermails des lokalen Kalenders (Einladung,
 * Absage, Antwort). Beides ist im Adminbereich unter Office -> Orvanta mit
 * Platzhaltern pflegbar (Einstellungen `calendar_mail_*` in
 * `orvanta_settings`); der Termin selbst geht unveraendert als
 * iCalendar-Teil mit.
 *
 * Textwerte werden fuer HTML maskiert, die Beschreibung dagegen als
 * geprueftes HTML eingesetzt (sie ist bereits durch MailHtmlSanitizer
 * gelaufen). Ein Platzhalter ohne Wert (etwa ein fehlender Ort) laesst im
 * Text auch den Zeilenumbruch davor entfallen.
 */
final class OrvantaCalendarMail
{
    /** Methode der Kalendernachricht -> Bezeichnung im Adminbereich. */
    public const METHODS = ['REQUEST' => 'Einladung', 'CANCEL' => 'Absage', 'REPLY' => 'Antwort'];

    /** Platzhalter -> Bezeichnung im Adminbereich (Reihenfolge wie im Formular). */
    public const PLACEHOLDERS = [
        '{Organisator}' => 'Name des Organisators (ersatzweise seine E-Mail-Adresse)',
        '{Organisator-Mail}' => 'E-Mail-Adresse des Organisators',
        '{Betreff}' => 'Betreff des Termins',
        '{Beginn}' => 'Beginn des Termins (TT.MM.JJJJ HH:MM)',
        '{Ende}' => 'Ende des Termins (TT.MM.JJJJ HH:MM)',
        '{Ort}' => 'Ort des Termins',
        '{Teilnehmer}' => 'Teilnehmer des Termins (Namen, durch Komma getrennt)',
        '{Beschreibung}' => 'Beschreibung des Termins (Formatierung bleibt erhalten)',
        '{Antwort}' => 'Antwort des Teilnehmers (zugesagt, mit Vorbehalt geantwortet, abgesagt)',
    ];

    /** Platzhalter, die im Betreff zur Verfuegung stehen (dort ohne Formatierung). */
    public const SUBJECT_PLACEHOLDERS = ['{Organisator}', '{Organisator-Mail}', '{Betreff}', '{Beginn}', '{Ende}', '{Ort}', '{Antwort}'];

    /** Grenzen der im Adminbereich gepflegten Vorlagen. */
    public const MAX_SUBJECT = 255;
    public const MAX_BODY = 20000;

    /** Standardvorlagen; sie ergeben den bisherigen Wortlaut der Nachrichten. */
    public const DEFAULTS = [
        'calendar_mail_request_subject' => 'Einladung: {Betreff}',
        'calendar_mail_request_body' => "<p>{Organisator} lädt zu einem Termin ein.</p>\n<p><strong>{Betreff}</strong><br>\n{Beginn} bis {Ende}<br>\n{Ort}</p>\n{Beschreibung}",
        'calendar_mail_cancel_subject' => 'Absage: {Betreff}',
        'calendar_mail_cancel_body' => "<p>Der Termin „{Betreff}“ wurde abgesagt.</p>\n<p><strong>{Betreff}</strong><br>\n{Beginn} bis {Ende}<br>\n{Ort}</p>\n{Beschreibung}",
        'calendar_mail_reply_subject' => 'Antwort: {Betreff}',
        'calendar_mail_reply_body' => "<p>{Organisator} hat für den Termin „{Betreff}“ {Antwort}.</p>\n<p><strong>{Betreff}</strong><br>\n{Beginn} bis {Ende}<br>\n{Ort}</p>\n{Beschreibung}",
    ];

    /**
     * Einstellungsschluessel einer Vorlage (z. B. calendar_mail_request_subject).
     */
    public static function settingKey(string $method, string $part): string
    {
        return 'calendar_mail_' . strtolower($method) . '_' . $part;
    }

    /**
     * Platzhalter des Betreffs mit ihren Bezeichnungen.
     *
     * @return array<string,string>
     */
    public static function subjectPlaceholders(): array
    {
        return array_intersect_key(self::PLACEHOLDERS, array_flip(self::SUBJECT_PLACEHOLDERS));
    }

    /**
     * Betreff der Kalendernachricht.
     *
     * @param array<string,mixed> $row Terminzeile
     */
    public static function subject(string $template, array $row, string $partstat): string
    {
        $subject = self::header(self::render($template, self::values($row, [], $partstat, false), false));
        if ($subject === '') {
            // Eine Vorlage ohne Inhalt ergibt trotzdem einen brauchbaren Betreff.
            $subject = self::header((string) ($row['subject'] ?? '')) !== '' ? self::header((string) $row['subject']) : 'Termin';
        }

        return $subject;
    }

    /**
     * Begleittext der Kalendernachricht.
     *
     * @param array<string,mixed> $row Terminzeile
     * @param list<array<string,mixed>> $attendees Teilnehmer des Termins
     */
    public static function body(string $template, array $row, string $partstat, array $attendees = []): string
    {
        return self::render($template, self::values($row, $attendees, $partstat, true), true);
    }

    /**
     * Werte der Platzhalter. Fuer HTML werden Textwerte maskiert, die
     * Beschreibung bleibt als geprueftes HTML stehen.
     *
     * @param array<string,mixed> $row
     * @param list<array<string,mixed>> $attendees
     * @return array<string,string>
     */
    private static function values(array $row, array $attendees, string $partstat, bool $html): array
    {
        $organizer = trim((string) ($row['organizer_name'] ?? ''));
        if ($organizer === '') {
            $organizer = trim((string) ($row['organizer_email'] ?? ''));
        }
        $names = [];
        foreach ($attendees as $attendee) {
            $name = trim((string) ($attendee['display_name'] ?? ''));
            if ($name === '') {
                $name = trim((string) ($attendee['email'] ?? ''));
            }
            if ($name !== '') {
                $names[$name] = $name;
            }
        }
        $description = trim((string) ($row['description'] ?? ''));
        $values = [
            '{Organisator}' => $organizer,
            '{Organisator-Mail}' => trim((string) ($row['organizer_email'] ?? '')),
            '{Betreff}' => trim((string) ($row['subject'] ?? '')),
            '{Beginn}' => self::moment((string) ($row['starts_at'] ?? '')),
            '{Ende}' => self::moment((string) ($row['ends_at'] ?? '')),
            '{Ort}' => trim((string) ($row['location'] ?? '')),
            '{Teilnehmer}' => implode(', ', $names),
            '{Antwort}' => self::answer($partstat),
        ];
        if ($html) {
            // Die Beschreibung ist der einzige Wert mit Formatierung.
            $values['{Beschreibung}'] = $description === '' ? '' : MailHtmlSanitizer::clean($description)['html'];
        } else {
            // Im Betreff gibt es keine Formatierung: Auszeichnung entfernen.
            $values['{Beschreibung}'] = trim((string) preg_replace('/\s+/u', ' ', strip_tags($description)));
        }
        if (!$html) {
            return $values;
        }
        foreach ($values as $name => $value) {
            if ($name !== '{Beschreibung}') {
                $values[$name] = Html::e($value);
            }
        }

        return $values;
    }

    /**
     * Setzt die Platzhalter ein. Ein leerer Wert entfernt zusaetzlich den
     * Zeilenumbruch davor, damit etwa eine fehlende Ortsangabe keine leere
     * Zeile hinterlaesst.
     *
     * @param array<string,string> $values
     */
    private static function render(string $template, array $values, bool $html): string
    {
        foreach ($values as $name => $value) {
            if ($html && $value === '') {
                $template = (string) preg_replace('/<br\s*\/?>\s*' . preg_quote($name, '/') . '/i', '', $template);
            }
            $template = str_replace($name, $value, $template);
        }

        return $template;
    }

    /** Antwort des Teilnehmers in Worten. */
    private static function answer(string $partstat): string
    {
        return match ($partstat) {
            'ACCEPTED' => 'zugesagt',
            'TENTATIVE' => 'mit Vorbehalt geantwortet',
            'DECLINED' => 'abgesagt',
            default => 'geantwortet',
        };
    }

    /** Zeitpunkt in der Schreibweise der Oberflaeche. */
    private static function moment(string $value): string
    {
        $time = strtotime($value);

        return $time === false ? '' : date('d.m.Y H:i', $time);
    }

    /** Betreffzeile: ohne Steuerzeichen und Zeilenumbrueche, in der Laenge begrenzt. */
    private static function header(string $value): string
    {
        $value = str_replace(["\r\n", "\r", "\n", "\t"], ' ', $value);
        $value = (string) preg_replace('/[\x00-\x1f\x7f]/', '', $value);
        $value = (string) preg_replace('/\s+/', ' ', $value);

        return trim(mb_substr($value, 0, self::MAX_SUBJECT));
    }
}
