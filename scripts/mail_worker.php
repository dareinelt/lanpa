<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';

use App\Core\Container;

$once = in_array('--once', $argv, true);
do {
    try {
        Container::reset();
        Container::mailQueue()->run();
    } catch (Throwable $exception) {
        app_logger()->error('Mail-Dienst fehlgeschlagen.', ['error' => $exception->getMessage()]);
        fwrite(STDERR, "Mail-Dienst fehlgeschlagen; Anwendungsprotokoll prüfen.\n");
        if ($once) {
            exit(1);
        }
    }
    // Nachzuholende Orvanta-Mails an Exchange-Postfaecher. Ein Fehler hier
    // darf den SMTP-Versand nicht anhalten.
    try {
        Container::orvantaMailQueue()->dispatch();
    } catch (Throwable $exception) {
        app_logger()->warning('Orvanta-Warteschlange fehlgeschlagen.', ['error' => $exception->getMessage()]);
    }
    if (!$once) {
        sleep(5);
    }
} while (!$once);
