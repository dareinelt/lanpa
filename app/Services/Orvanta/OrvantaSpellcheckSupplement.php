<?php

declare(strict_types=1);

namespace App\Services\Orvanta;

/**
 * Mitgelieferte Ergaenzungen zum deutschen Woerterbuch (LibreOffice
 * de_DE_frami). Enthalten sind Woerter und Abkuerzungen, die im Buero- und
 * Mailalltag ueblich sind, dort aber fehlen. Persoenliche Woerter legen die
 * Benutzer selbst an ({@see OrvantaSpellcheckUserWords}).
 *
 * Schreibweisen gelten wie angegeben; ein kleingeschriebener Eintrag ist am
 * Satzanfang auch grossgeschrieben korrekt, jeder Eintrag zudem in
 * GROSSBUCHSTABEN. Bindestrich-Zusammensetzungen ("Homeoffice-Tag") ergeben
 * sich aus den Teilen.
 */
final class OrvantaSpellcheckSupplement
{
    /** @var list<string> */
    public const WORDS = [
        // Anreden und Wochentage
        'Hr.', 'Hrn.', 'Mo.', 'Di.', 'Mi.', 'Do.',
        // Gruss- und Mailformeln
        'MfG', 'LG', 'VG', 'AW', 'WG', 'Fwd', 'cc', 'Cc', 'bcc', 'Bcc',
        // Zustimmung
        'OK', 'ok',
        // Arbeitsalltag
        'Homeoffice', 'Homeoffices', 'Home-Office',
        'Telko', 'Telkos',
        'To-do', 'To-dos',
        'Webinar', 'Webinare', 'Webinaren', 'Webinars',
        'Onboarding',
        'Admin', 'Admins',
        'Logout', 'Logouts',
        'Tablet', 'Tablets',
        'KW', 'Kw.',
        'DSGVO',
        // Programme und Dienste der Office-Umgebung
        'Outlook', 'Nextcloud', 'Exchange', 'Orvanta',
    ];
}
