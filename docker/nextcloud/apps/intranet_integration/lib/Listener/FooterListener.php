<?php

declare(strict_types=1);

namespace OCA\IntranetIntegration\Listener;

use OCA\IntranetIntegration\AppInfo\Application;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IConfig;
use OCP\Util;

/**
 * Laedt den kleinen Loader, der die Intranet-Fusszeile nachlaedt.
 *
 * Inhalt, Transparenz, Farben und Verhalten der Fusszeile kommen zur Laufzeit
 * aus dem Intranet (/api/office/footer, gleicher Host). Aenderungen im
 * Intranet-Adminbereich wirken dadurch ohne Neustart von Nextcloud.
 *
 * @template-implements IEventListener<Event>
 */
class FooterListener implements IEventListener {
    private static bool $added = false;

    public function __construct(
        private IConfig $config,
    ) {
    }

    public function handle(Event $event): void {
        if (self::$added) {
            return;
        }
        self::$added = true;

        $settings = $this->config->getSystemValue(Application::APP_ID, []);
        $base = is_array($settings) && is_string($settings['intranet_base'] ?? null)
            ? $settings['intranet_base'] : '/';
        if (!str_starts_with($base, '/') || str_starts_with($base, '//')) {
            $base = '/';
        }
        $base = rtrim($base, '/') . '/';

        // Konfiguration ohne Inline-Skript (CSP-konform) als meta-Element.
        Util::addHeader('meta', [
            'name' => 'intranet-office-footer-api',
            'content' => $base . 'api/office/footer',
        ]);
        Util::addScript(Application::APP_ID, 'loader');
    }
}
