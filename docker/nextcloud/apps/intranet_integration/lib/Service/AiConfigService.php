<?php

declare(strict_types=1);

namespace OCA\IntranetIntegration\Service;

use OCA\IntranetIntegration\AppInfo\Application;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use OCP\ICacheFactory;
use OCP\IConfig;
use OCP\TaskProcessing\IManager as ITaskProcessingManager;
use Psr\Log\LoggerInterface;

/**
 * Richtet den lokalen KI-Endpunkt des Intranets fuer alle Nextcloud-Benutzer
 * ein: integration_openai (OpenAI-kompatibler Anbieter fuer Text,
 * Zusammenfassung und Uebersetzung) und assistant (Oberflaeche).
 *
 * Audio- und Bildfunktionen (Sprache, Transkription, Bilderzeugung,
 * Bildanalyse, Sticker ...) sind nur verfuegbar, wenn das Intranet sie
 * freigibt: Die Anbieter von integration_openai werden entsprechend
 * geschaltet und die Aufgabentypen in der Nextcloud-KI-Konfiguration
 * (ai.taskprocessing_type_preferences) ausgeblendet. Damit verschwinden auch
 * die Schaltflaechen „Mit Audio arbeiten“ und „Mit Bildern arbeiten“ im
 * Assistant. Nur selbst ausgeblendete Typen werden wieder freigegeben.
 *
 * Unterstuetzt integration_openai 4.x/5.x (eine Admin-Konfiguration) und
 * 6.x (mehrere Dienste; der vom Intranet verwaltete Dienst wird ueber seine
 * ID wiedergefunden, andere Dienste bleiben unberuehrt).
 */
class AiConfigService {
    public const PROVIDER_APP = 'integration_openai';
    public const UI_APP = 'assistant';

    private const LEGACY_SETTINGS = 'OCA\\OpenAi\\Service\\OpenAiSettingsService';
    private const SERVICES = 'OCA\\OpenAi\\Service\\ServicesService';

    private const TYPE_PREFERENCES = 'ai.taskprocessing_type_preferences';

    /** Bekannte Aufgabentypen; weitere werden am Namen erkannt (wie im Assistant). */
    private const AUDIO_TASK_TYPES = [
        'core:audio2text',
        'core:text2speech',
        'core:audio2audio:chat',
        'core:contextagent:audio-interaction',
        'assistant:audio2audio:chat',
    ];
    private const IMAGE_TASK_TYPES = [
        'core:text2image',
        'core:analyze-images',
        'core:image2text:ocr',
        'assistant:text2sticker',
        'assistant:image2text:translate',
    ];

    public function __construct(
        private IConfig $config,
        private IAppManager $appManager,
        private IAppConfig $appConfig,
        private ICacheFactory $cacheFactory,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @param array<string,mixed> $payload vom Intranet (signiert)
     *
     * @return array{ok:bool,message:string}
     */
    public function apply(array $payload): array {
        $settings = $this->normalize($payload);
        if ($settings === null) {
            return $this->finish(false, 'Ungueltige KI-Einstellungen.', '');
        }

        if (!$settings['enabled']) {
            foreach ([self::UI_APP, self::PROVIDER_APP] as $app) {
                if ($this->appManager->isEnabledForAnyone($app)) {
                    $this->appManager->disableApp($app);
                }
            }
            try {
                $this->applyTaskTypeVisibility(true, true);
            } catch (\Throwable $e) {
                $this->logger->warning('Ausgeblendete KI-Aufgabentypen konnten nicht freigegeben werden', ['exception' => $e]);
            }
            return $this->finish(true, 'KI in Nextcloud deaktiviert.', $settings['fingerprint']);
        }

        if (!$this->isPresent(self::PROVIDER_APP)) {
            return $this->finish(false, 'Nextcloud-App integration_openai ist nicht installiert (NEXTCLOUD_AI_APPS).', '');
        }

        try {
            $this->enable(self::PROVIDER_APP);
            $this->appManager->loadApp(self::PROVIDER_APP);

            if (class_exists(self::SERVICES)) {
                $this->configureServices($settings);
            } elseif (class_exists(self::LEGACY_SETTINGS)) {
                $this->configureLegacy($settings);
            } else {
                return $this->finish(false, 'Unbekannte Version von integration_openai.', '');
            }
        } catch (\Throwable $e) {
            $this->logger->error('KI-Einrichtung fehlgeschlagen', ['exception' => $e]);
            return $this->finish(false, 'Einrichtung von integration_openai fehlgeschlagen: ' . $e->getMessage(), '');
        }

        $message = 'KI für alle Nextcloud-Benutzer eingerichtet.';
        if ($this->isPresent(self::UI_APP)) {
            try {
                $this->enable(self::UI_APP);
            } catch (\Throwable $e) {
                $this->logger->warning('Assistant konnte nicht aktiviert werden', ['exception' => $e]);
                $message .= ' Assistant konnte nicht aktiviert werden.';
            }
        } else {
            $message .= ' Die App assistant ist nicht installiert.';
        }

        try {
            $this->applyTaskTypeVisibility($settings['audio'], $settings['images']);
        } catch (\Throwable $e) {
            $this->logger->error('Audio-/Bildfunktionen konnten nicht umgeschaltet werden', ['exception' => $e]);
            return $this->finish(false, 'Audio-/Bildfunktionen konnten nicht umgeschaltet werden: ' . $e->getMessage(), '');
        }

        return $this->finish(true, $message, $settings['fingerprint']);
    }

    /**
     * Zustand fuer die Diagnose (ohne Geheimnisse).
     *
     * @return array<string,mixed>
     */
    public function status(): array {
        $apps = [];
        foreach ([self::PROVIDER_APP, self::UI_APP] as $app) {
            $installed = $this->isPresent($app);
            $apps[$app] = [
                'installed' => $installed,
                'enabled' => $installed && $this->appManager->isEnabledForAnyone($app),
                'version' => $installed ? (string) $this->appManager->getAppVersion($app, false) : '',
            ];
        }

        $hidden = $this->hiddenTaskTypes();

        return [
            'audio' => array_intersect($hidden, self::AUDIO_TASK_TYPES) === [],
            'images' => array_intersect($hidden, self::IMAGE_TASK_TYPES) === [],
            'fingerprint' => (string) $this->config->getAppValue(Application::APP_ID, 'ai_fingerprint', ''),
            'error' => (string) $this->config->getAppValue(Application::APP_ID, 'ai_error', ''),
            'apps' => $apps,
        ];
    }

    /**
     * @param array<string,mixed> $payload
     *
     * @return array{enabled:bool,name:string,url:string,model:string,api_key:string,timeout:int,audio:bool,images:bool,fingerprint:string}|null
     */
    private function normalize(array $payload): ?array {
        $url = rtrim(trim((string) ($payload['url'] ?? '')), '/');
        $model = trim((string) ($payload['model'] ?? ''));
        $fingerprint = (string) ($payload['fingerprint'] ?? '');
        if ($url !== '' && preg_match('#^https?://[^\s/?\#@]+(/[^\s?\#]*)?$#i', $url) !== 1) {
            return null;
        }
        if ($model !== '' && preg_match('#^[A-Za-z0-9][A-Za-z0-9._:/@+-]{0,199}$#', $model) !== 1) {
            return null;
        }
        if (preg_match('/^[a-f0-9]{64}$/', $fingerprint) !== 1) {
            return null;
        }
        $name = trim((string) ($payload['name'] ?? ''));

        return [
            'enabled' => !empty($payload['enabled']) && $url !== '' && $model !== '',
            'name' => mb_substr($name !== '' ? $name : 'Lokale KI', 0, 60),
            'url' => $url,
            'model' => $model,
            'api_key' => (string) ($payload['api_key'] ?? ''),
            'timeout' => max(10, min(900, (int) ($payload['timeout'] ?? 120))),
            'audio' => !empty($payload['audio']),
            'images' => !empty($payload['images']),
            'fingerprint' => $fingerprint,
        ];
    }

    /**
     * Vorhanden, auch wenn deaktiviert (isInstalled meldet nur aktive Apps).
     */
    private function isPresent(string $app): bool {
        try {
            return $this->appManager->getAppPath($app) !== '';
        } catch (\Throwable) {
            return false;
        }
    }

    private function enable(string $app): void {
        if (!$this->appManager->isEnabledForAnyone($app)) {
            $this->appManager->enableApp($app);
        }
    }

    /**
     * integration_openai 4.x/5.x: gemeinsame Admin-Konfiguration.
     *
     * @param array{name:string,url:string,model:string,api_key:string,timeout:int,audio:bool,images:bool} $s
     */
    private function configureLegacy(array $s): void {
        $values = [
            'url' => $s['url'],
            'service_name' => $s['name'],
            'api_key' => $s['api_key'],
            'use_basic_auth' => false,
            'default_completion_model_id' => $s['model'],
            'chat_endpoint_enabled' => true,
            'request_timeout' => $s['timeout'],
            'llm_provider_enabled' => true,
            'translation_provider_enabled' => true,
            't2i_provider_enabled' => $s['images'],
            'stt_provider_enabled' => $s['audio'],
            'tts_provider_enabled' => $s['audio'],
            'analyze_image_provider_enabled' => $s['images'],
        ];

        // Unbekannte Schluessel lehnt setAdminConfig ab (je nach Version).
        try {
            $known = (new \ReflectionClassConstant(self::LEGACY_SETTINGS, 'ADMIN_CONFIG_TYPES'))->getValue();
            if (is_array($known)) {
                $values = array_intersect_key($values, $known);
            }
        } catch (\ReflectionException) {
        }

        /** @var object $service */
        $service = \OCP\Server::get(self::LEGACY_SETTINGS);
        $service->setAdminConfig($values);
        if (method_exists($service, 'invalidateModelsCache')) {
            $service->invalidateModelsCache();
        }
    }

    /**
     * integration_openai 6.x: eigener, vom Intranet verwalteter Dienst.
     *
     * @param array{name:string,url:string,model:string,api_key:string,timeout:int,audio:bool,images:bool} $s
     */
    private function configureServices(array $s): void {
        $values = [
            'name' => $s['name'],
            'url' => $s['url'],
            'api_key' => $s['api_key'],
            'use_basic_auth' => false,
            'request_timeout' => $s['timeout'],
            'chat_endpoint_enabled' => true,
            'text_enabled' => true,
            'translation_enabled' => true,
            'image_enabled' => $s['images'],
            'stt_enabled' => $s['audio'],
            'tts_enabled' => $s['audio'],
            'text_models' => [$s['model']],
        ];
        try {
            $known = (new \ReflectionClassConstant('OCA\\OpenAi\\Service\\ServiceConfig', 'PROPERTY_TYPES'))->getValue();
            if (is_array($known)) {
                $values = array_intersect_key($values, $known);
            }
        } catch (\ReflectionException) {
        }

        /** @var object $services */
        $services = \OCP\Server::get(self::SERVICES);
        $id = (string) $this->config->getAppValue(Application::APP_ID, 'ai_service_id', '');
        if ($id !== '' && $services->getService($id) !== null) {
            $services->updateService($id, $values);
            return;
        }
        $created = $services->addService($values);
        $this->config->setAppValue(Application::APP_ID, 'ai_service_id', (string) $created->getId());
    }

    /**
     * Blendet Audio- bzw. Bild-Aufgabentypen fuer alle Benutzer aus oder gibt
     * die zuvor vom Intranet ausgeblendeten wieder frei. Vom Nextcloud-Admin
     * selbst gesetzte Werte fuer andere Typen bleiben erhalten.
     */
    private function applyTaskTypeVisibility(bool $audio, bool $images): void {
        $hide = [];
        if (!$audio || !$images) {
            foreach ($this->knownTaskTypeIds() as $id) {
                $isImage = in_array($id, self::IMAGE_TASK_TYPES, true)
                    || str_contains($id, 'image') || str_contains($id, 'sticker');
                $isAudio = !$isImage && (in_array($id, self::AUDIO_TASK_TYPES, true)
                    || str_contains($id, 'audio') || str_contains($id, 'speech'));
                if ((!$images && $isImage) || (!$audio && $isAudio)) {
                    $hide[] = $id;
                }
            }
        }
        $hide = array_values(array_unique($hide));
        sort($hide);

        $previous = $this->hiddenTaskTypes();
        $preferences = $this->typePreferences();
        foreach ($previous as $id) {
            if (($preferences[$id] ?? null) === false) {
                unset($preferences[$id]);
            }
        }
        foreach ($hide as $id) {
            $preferences[$id] = false;
        }

        if ($previous === $hide && $this->typePreferences() === $preferences) {
            return;
        }
        $this->appConfig->setValueString('core', self::TYPE_PREFERENCES, $preferences === [] ? '' : (string) json_encode($preferences), lazy: true);
        $this->config->setAppValue(Application::APP_ID, 'ai_hidden_task_types', (string) json_encode($hide));
        // Die verfuegbaren Aufgabentypen werden kurz zwischengespeichert.
        $this->cacheFactory->createDistributed('task_processing::')->clear();
    }

    /**
     * @return list<string>
     */
    private function knownTaskTypeIds(): array {
        $ids = array_merge(self::AUDIO_TASK_TYPES, self::IMAGE_TASK_TYPES);
        try {
            $ids = array_merge($ids, array_keys(\OCP\Server::get(ITaskProcessingManager::class)->getAvailableTaskTypes(true)));
        } catch (\Throwable $e) {
            $this->logger->warning('Aufgabentypen konnten nicht ermittelt werden', ['exception' => $e]);
        }

        return array_values(array_filter(array_map('strval', $ids), static fn (string $id): bool => !str_starts_with($id, 'chatty') && !str_starts_with($id, 'context_chat')));
    }

    /**
     * @return list<string>
     */
    private function hiddenTaskTypes(): array {
        $list = json_decode((string) $this->config->getAppValue(Application::APP_ID, 'ai_hidden_task_types', '[]'), true);

        return is_array($list) ? array_values(array_filter($list, 'is_string')) : [];
    }

    /**
     * @return array<string,bool>
     */
    private function typePreferences(): array {
        $json = $this->appConfig->getValueString('core', self::TYPE_PREFERENCES, '', lazy: true);
        $preferences = $json !== '' ? json_decode($json, true) : [];

        return is_array($preferences) ? $preferences : [];
    }

    /**
     * @return array{ok:bool,message:string}
     */
    private function finish(bool $ok, string $message, string $fingerprint): array {
        // Ohne Fingerabdruck uebertraegt das Intranet bei der naechsten Pruefung erneut.
        $this->config->setAppValue(Application::APP_ID, 'ai_fingerprint', $ok ? $fingerprint : '');
        $this->config->setAppValue(Application::APP_ID, 'ai_error', $ok ? '' : $message);

        return ['ok' => $ok, 'message' => $message];
    }
}
