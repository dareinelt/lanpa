<?php

declare(strict_types=1);

namespace App\Services\Storage\Agent;

/**
 * Speicherziel waehrend der Uebertragung weggefallen (Verbindung, Einbindung).
 */
final class TargetUnavailable extends \RuntimeException
{
}
