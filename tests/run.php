<?php

declare(strict_types=1);

/**
 * Dependency-freier Testlauf: php tests/run.php
 */

require_once dirname(__DIR__) . '/bootstrap.php';

use Tests\Support\Runner;

require_once __DIR__ . '/Support/Runner.php';
require_once __DIR__ . '/Support/Assert.php';
require_once __DIR__ . '/Support/Fakes.php';

$files = glob(__DIR__ . '/Unit/*Test.php') ?: [];
sort($files);

foreach ($files as $file) {
    require_once $file;
}

exit(Runner::run());
