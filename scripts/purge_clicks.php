<?php

declare(strict_types=1);

/**
 * Loescht Klickdaten, die aelter als die angegebene Aufbewahrungsfrist sind.
 *
 * Aufruf: php scripts/purge_clicks.php [tage]
 */

require_once dirname(__DIR__) . '/bootstrap.php';

use App\Core\Container;
use App\Core\Env;

$days = (int) ($argv[1] ?? Env::int('CLICK_RETENTION_DAYS', 400));
$days = max(30, min(3650, $days));

$deleted = Container::clickRepository()->purgeOlderThan($days);

fwrite(STDOUT, sprintf('%d Klickereignisse älter als %d Tage gelöscht.%s', $deleted, $days, PHP_EOL));
