<?php

declare(strict_types=1);

namespace OCA\IntranetIntegration\Storage;

/**
 * Ausgelagerte Datei derzeit nicht verfuegbar (Rueckholung fehlgeschlagen,
 * dauert noch an oder storage-sync laeuft nicht). Im Wrapper wird daraus
 * eine StorageNotAvailableException (WebDAV: 503, Clients wiederholen).
 */
class TieringException extends \RuntimeException {
}
