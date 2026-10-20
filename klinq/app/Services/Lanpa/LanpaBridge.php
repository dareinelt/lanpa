<?php

declare(strict_types=1);

namespace App\Services\Lanpa;

use App\Core\Config;
use App\Services\Helpdesk\Ai\AiTransportInterface;

/**
 * Bruecke zum Intranet (LANPA): Einbindung in dessen Gestaltung.
 *
 * KLINQ uebernimmt die Designfarben des Intranets ueber ein zusaetzliches
 * Stylesheet (/klinq-design.css). Die Adresse wird protokollrelativ gebildet,
 * damit sie hinter dem Proxy unabhaengig vom Schema funktioniert.
 *
 * Zusaetzlich holt KLINQ die KI-Konfiguration aus dem Intranet
 * (GET /internal/klinq/ki, Dienst-Token im Kopf X-Intranet-Sso-Token). Der
 * Endpunkt wird nur einmal je Anfrage abgefragt.
 */
final class LanpaBridge
{
    /** Antwort der internen Schnittstelle, je Anfrage einmal abgefragt. */
    private ?array $aiConfig = null;

    private bool $aiConfigLoaded = false;

    public function __construct(
        private readonly Config $config,
        private readonly ?AiTransportInterface $transport = null
    ) {}

    public function enabled(): bool
    {
        return (bool) $this->config->get('lanpa.enabled', false);
    }

    /** Adresse des Designfarben-Stylesheets; leer, wenn die Anbindung inaktiv ist. */
    public function designCssUrl(): string
    {
        if (!$this->enabled()) {
            return '';
        }

        $configured = trim((string) $this->config->get('lanpa.design_css', ''));
        if ($configured !== '') {
            return $configured;
        }

        $host = self::currentHost();

        return $host === '' ? '' : '//' . $host . '/klinq-design.css';
    }

    /** Basisadresse des Intranets (z. B. https://intranet.example.local); leer, wenn unbekannt. */
    public function baseUrl(): string
    {
        $configured = rtrim(trim((string) $this->config->get('lanpa.base_url', '')), '/');
        if ($configured !== '') {
            return $configured;
        }

        $host = self::currentHost();

        return $host === '' ? '' : '//' . $host;
    }

    /** Rueckweg ins Intranet, z. B. "/" oder "/admin". */
    public function intranetUrl(string $path = '/'): string
    {
        $base = $this->baseUrl();
        if ($base === '') {
            return '';
        }

        return $base . '/' . ltrim($path, '/');
    }

    /** Hostname der aktuellen Anfrage (hinter dem Proxy der Name des Intranets). */
    private static function currentHost(): string
    {
        $host = (string) ($_SERVER['HTTP_X_FORWARDED_HOST'] ?? $_SERVER['HTTP_HOST'] ?? '');
        $host = trim(explode(',', $host)[0]);

        return preg_match('/^[A-Za-z0-9.\-:\[\]]{1,190}$/', $host) === 1 ? $host : '';
    }

    /**
     * Basisadresse fuer Aufrufe aus dem Container zum Intranet (ohne Proxy).
     * Leer, wenn keine interne Adresse hinterlegt ist.
     */
    public function internalUrl(string $path = '/'): string
    {
        $base = rtrim(trim((string) $this->config->get('lanpa.internal_url', '')), '/');
        if ($base === '') {
            return '';
        }

        return $base . '/' . ltrim($path, '/');
    }

    /** Gemeinsames Dienst-Token fuer die internen Schnittstellen des Intranets. */
    public function serviceToken(): string
    {
        $token = trim((string) $this->config->get('lanpa.service_token', ''));
        if ($token !== '') {
            return $token;
        }

        // Im Container liegt das Token im gemeinsamen Volume des Intranets
        // (/run/intranet-sso/token, schreibgeschuetzt eingebunden).
        $file = trim((string) $this->config->get('lanpa.service_token_file', ''));
        if ($file === '' || !is_readable($file)) {
            return '';
        }

        return trim((string) @file_get_contents($file));
    }

    /**
     * KI-Konfiguration aus dem Intranet.
     *
     * @return array{enabled:bool,name:string,url:string,model:string,timeout:int,api_key:string}|null
     *         null, wenn die Anbindung inaktiv ist oder das Intranet nicht antwortet
     */
    public function aiConfig(): ?array
    {
        if ($this->aiConfigLoaded) {
            return $this->aiConfig;
        }
        $this->aiConfigLoaded = true;

        if (!$this->enabled() || $this->transport === null) {
            return $this->aiConfig = null;
        }
        $url = $this->internalUrl('/internal/klinq/ki');
        if ($url === '') {
            return $this->aiConfig = null;
        }

        $headers = ['Accept' => 'application/json'];
        $token = $this->serviceToken();
        if ($token !== '') {
            $headers['X-Intranet-Sso-Token'] = $token;
        }

        $response = $this->transport->request('GET', $url, $headers, null, 5);
        if ($response['error'] !== null || $response['status'] !== 200) {
            return $this->aiConfig = null;
        }
        $data = json_decode($response['body'], true);
        if (!is_array($data) || !isset($data['url'], $data['model'])) {
            return $this->aiConfig = null;
        }

        return $this->aiConfig = [
            'enabled' => ($data['enabled'] ?? false) === true,
            'name' => trim((string) ($data['name'] ?? '')),
            'url' => rtrim(trim((string) $data['url']), '/'),
            'model' => trim((string) $data['model']),
            'timeout' => (int) ($data['timeout'] ?? 60),
            'api_key' => trim((string) ($data['api_key'] ?? '')),
        ];
    }

    /** Das Intranet gibt die KI-Einstellungen vor; die lokale Administration ist dann nur lesend. */
    public function managesAi(): bool
    {
        return $this->aiConfig() !== null;
    }

    /**
     * Melderdaten aus dem Intranet nachfragen (POST /internal/klinq/melder).
     *
     * Regulaer uebergibt das Intranet die Kontaktdaten bereits mit dem
     * Einstiegsschein. Dieser Weg greift nur, wenn die Sitzung keine Angaben
     * mehr enthaelt (z. B. abgelaufene Sitzung), und liefert dann Anmeldename,
     * Anzeigename, E-Mail, Telefon, Abteilung und Rechnername nach.
     *
     * @return array{username:string,display_name:string,first_name:string,last_name:string,email:string,
     *               phone:string,mobile:string,department:string,position:string,ip:string,host:string,
     *               report_only:bool,received_at:int}|null
     */
    public function reporter(string $username, string $ip = ''): ?array
    {
        if (!$this->enabled() || $this->transport === null) {
            return null;
        }
        $username = trim($username);
        if ($username === '') {
            return null;
        }

        $url = $this->internalUrl('/internal/klinq/melder');
        if ($url === '') {
            return null;
        }

        $headers = [
            'Accept' => 'application/json',
            'Content-Type' => 'application/x-www-form-urlencoded',
        ];
        $token = $this->serviceToken();
        if ($token !== '') {
            $headers['X-Intranet-Sso-Token'] = $token;
        }

        $body = http_build_query(['username' => $username, 'ip' => $ip]);
        $response = $this->transport->request('POST', $url, $headers, $body, 5);
        if ($response['error'] !== null || $response['status'] !== 200) {
            return null;
        }

        $data = json_decode($response['body'], true);
        if (!is_array($data)) {
            return null;
        }

        return [
            'username' => trim((string) ($data['username'] ?? '')),
            'display_name' => trim((string) ($data['display_name'] ?? '')),
            'first_name' => trim((string) ($data['first_name'] ?? '')),
            'last_name' => trim((string) ($data['last_name'] ?? '')),
            'email' => trim((string) ($data['email'] ?? '')),
            'phone' => trim((string) ($data['phone'] ?? '')),
            'mobile' => trim((string) ($data['mobile'] ?? '')),
            'department' => trim((string) ($data['department'] ?? '')),
            'position' => trim((string) ($data['title'] ?? '')),
            'ip' => trim((string) ($data['ip'] ?? '')),
            'host' => trim((string) ($data['host'] ?? '')),
            'report_only' => false,
            'received_at' => time(),
        ];
    }
}
