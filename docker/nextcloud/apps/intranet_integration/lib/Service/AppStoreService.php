<?php

declare(strict_types=1);

namespace OCA\IntranetIntegration\Service;

use OCP\IConfig;
use Psr\Log\LoggerInterface;

/**
 * App-Store ein- bzw. ausblenden (im Intranet festgelegt).
 *
 * Setzt die Systemeinstellung "appstoreenabled": Bei false zeigt Nextcloud
 * unter "Apps" nur noch installierte Apps; Kategorien, App-Pakete sowie
 * Installation und Aktualisierung aus dem App-Store entfallen. Eingeblendet
 * wird durch Entfernen des Eintrags (Nextcloud-Standard: true).
 */
class AppStoreService {
    public const KEY = 'appstoreenabled';

    public function __construct(
        private IConfig $config,
        private LoggerInterface $logger,
    ) {
    }

    public function enabled(): bool {
        return $this->config->getSystemValueBool(self::KEY, true);
    }

    /**
     * @return array{enabled: bool}
     */
    public function status(): array {
        return ['enabled' => $this->enabled()];
    }

    /**
     * @param array<string,mixed> $payload vom Intranet (signiert)
     *
     * @return array{ok: bool, message: string, changed: bool}
     */
    public function apply(array $payload): array {
        $enabled = $payload['enabled'] ?? null;
        if ((int) ($payload['version'] ?? 0) !== 1 || !is_bool($enabled)) {
            return ['ok' => false, 'message' => 'Ungueltige Einstellung.', 'changed' => false];
        }

        $label = $enabled ? 'eingeblendet' : 'ausgeblendet';
        if ($enabled === $this->enabled()) {
            return ['ok' => true, 'message' => 'App-Store unveraendert ' . $label . '.', 'changed' => false];
        }

        try {
            if ($enabled) {
                $this->config->deleteSystemValue(self::KEY);
            } else {
                $this->config->setSystemValue(self::KEY, false);
            }
        } catch (\Throwable $e) {
            $this->logger->error('appstoreenabled konnte nicht gesetzt werden', ['exception' => $e]);
            return ['ok' => false, 'message' => 'config.php ist nicht beschreibbar.', 'changed' => false];
        }

        if ($this->enabled() !== $enabled) {
            // Wert stammt aus einer weiteren *.config.php mit Vorrang.
            return ['ok' => false, 'message' => 'appstoreenabled wird durch eine andere Konfigurationsdatei festgelegt.', 'changed' => false];
        }

        $this->logger->info('App-Store aus dem Intranet ' . $label);

        return ['ok' => true, 'message' => 'App-Store ' . $label . '.', 'changed' => true];
    }
}
