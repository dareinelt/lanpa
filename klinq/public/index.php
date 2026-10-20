<?php

declare(strict_types=1);

use App\Core\ApplicationFactory;

require __DIR__ . '/../bootstrap/autoload.php';

ApplicationFactory::create(dirname(__DIR__))->run();
