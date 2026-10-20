<?php

declare(strict_types=1);

namespace App\Services\Ad;

/** Interne Ausnahme, um die Transaktion eines Testlaufs zurückzurollen. */
final class DryRunRollback extends \RuntimeException {}
