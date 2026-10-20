#!/usr/bin/env php
<?php
/**
 * Help-Desk-Wartungsjobs per Kommandozeile (manuell oder per Cron/Scheduler).
 *
 * Aufruf: php bin/helpdesk.php <befehl> [--quiet] [--limit=N] [--due]
 *   process  SLA-Zustände neu bewerten (Warnung/Verletzung), Eskalationen ausführen,
 *            gelöste Tickets nach HELPDESK_AUTO_CLOSE_DAYS automatisch schließen,
 *            Zuständigkeiten beenden und 2nd-Level-Tagesabschluss (23:59) verarbeiten.
 *   mail     E-Mail-Eingang abholen (IMAP oder .eml-Verzeichnis) und Nachrichten als neues Ticket
 *            bzw. als Kommentar (Antwort per Ticketnummer/In-Reply-To) verarbeiten. Die Konfiguration
 *            stammt aus der Administration („Auswertung & System → Administration → E-Mail-Postfach“).
 *            Mit --due wird nur abgeholt, wenn das dort eingestellte Intervall abgelaufen ist (Scheduler).
 *
 * Exit-Code 0 bei Erfolg, 1 bei Fehler, 2 wenn das Modul bzw. der E-Mail-Eingang deaktiviert (oder nicht fällig) ist.
 */

declare(strict_types=1);

require __DIR__ . '/../bootstrap/autoload.php';

use App\Core\ApplicationFactory;
use App\Core\Config;
use App\Services\Helpdesk\HelpdeskSchedulerService;
use App\Services\Helpdesk\MailboxSettingsService;
use App\Services\Helpdesk\TicketMailIngestionService;

$args = array_slice($argv, 1);
$quiet = in_array('--quiet', $args, true);
$onlyDue = in_array('--due', $args, true);
$limit = null;
$command = 'process';
foreach ($args as $arg) {
    if (str_starts_with($arg, '--limit=')) {
        $limit = max(1, (int) substr($arg, 8));
    } elseif (!str_starts_with($arg, '--')) {
        $command = $arg;
    }
}

if (!in_array($command, ['process', 'mail'], true)) {
    fwrite(STDERR, "Unbekannter Befehl „{$command}“. Verfügbar: process [--quiet] | mail [--quiet] [--due] [--limit=N]\n");
    exit(1);
}

$container = ApplicationFactory::container(dirname(__DIR__));
$config = $container->get(Config::class);
if (!(bool) $config->get('helpdesk.enabled', true)) {
    if (!$quiet) {
        fwrite(STDERR, "Help Desk ist deaktiviert (HELPDESK_ENABLED=false).\n");
    }
    exit(2);
}

if ($command === 'mail') {
    try {
        $mailSettings = $container->get(MailboxSettingsService::class);
        $ingestion = $container->get(TicketMailIngestionService::class);
        $active = $ingestion->enabled();
        $due = !$onlyDue || $mailSettings->due();
    } catch (Throwable $e) {
        // Konfiguration nicht lesbar (z. B. Datenbank noch nicht erreichbar) – kein Abbruch des Schedulers
        if (!$quiet) {
            fwrite(STDERR, 'Konfiguration des E-Mail-Eingangs nicht lesbar: ' . $e->getMessage() . "\n");
        }
        exit(2);
    }
    if (!$active) {
        if (!$quiet) {
            fwrite(STDERR, "E-Mail-Eingang ist deaktiviert (Administration → E-Mail-Postfach).\n");
        }
        exit(2);
    }
    if (!$due) {
        exit(2);
    }
    try {
        $result = $ingestion->pull($limit);
    } catch (Throwable $e) {
        try {
            $mailSettings->recordError($e->getMessage());
        } catch (Throwable) {
            // Protokollierung des Fehlers darf den Exit-Code nicht verändern
        }
        fwrite(STDERR, 'Fehler beim E-Mail-Eingang: ' . $e->getMessage() . "\n");
        exit(1);
    }
    if (!$quiet) {
        printf(
            "OK (%s): %d Nachrichten, %d neue Tickets, %d Kommentare, %d ignoriert, %d fehlgeschlagen.\n",
            $result['source'],
            $result['fetched'],
            $result['created'],
            $result['comments'],
            $result['ignored'],
            $result['failed']
        );
        foreach ($result['items'] as $item) {
            printf("  [%s] %s%s%s\n", $item['action'], $item['uid'], $item['ticket'] !== null ? ' → ' . $item['ticket'] : '', $item['detail'] !== null ? ' – ' . $item['detail'] : '');
        }
    }
    exit($result['failed'] > 0 && $result['created'] + $result['comments'] + $result['ignored'] === 0 && $result['fetched'] > 0 ? 1 : 0);
}

try {
    $summary = $container->get(HelpdeskSchedulerService::class)->run();
} catch (Throwable $e) {
    fwrite(STDERR, 'Fehler: ' . $e->getMessage() . "\n");
    exit(1);
}

if (!$quiet) {
    printf(
        "OK: %d Tickets geprüft, %d Warnungen, %d SLA-Verletzungen, %d Eskalationen, %d automatisch geschlossen, %d Zuständigkeiten beendet, %d Erinnerungskommentare.\n",
        $summary['checked'] ?? 0,
        $summary['warnings'] ?? 0,
        $summary['breaches'] ?? 0,
        $summary['escalated'] ?? 0,
        $summary['auto_closed'] ?? 0,
        $summary['shifts_ended'] ?? 0,
        $summary['reminders_created'] ?? 0
    );
}
exit(0);
