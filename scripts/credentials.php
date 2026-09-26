<?php

declare(strict_types=1);

/**
 * Verwaltung der verschluesselt gespeicherten Zugangsdaten (CLI).
 *
 *   php scripts/credentials.php                 Schluessel sicherstellen und
 *                                               Altwerte aus der Umgebung
 *                                               einmalig uebernehmen
 *   php scripts/credentials.php --key           nur Schluessel sicherstellen
 *   php scripts/credentials.php --ldap-password Bind-Passwort der Hauptquelle
 *                                               ausgeben (fuer office-setup.sh)
 *   php scripts/credentials.php --set-primary   Zugangsdaten der Hauptquelle
 *                                               von stdin setzen (Zeilen
 *                                               NAME=base64(wert); NAME:
 *                                               ldap_bind_password, sso_domain,
 *                                               sso_dcs, sso_join_user,
 *                                               sso_join_password; fuer
 *                                               install.sh)
 *   php scripts/credentials.php --sso-sources   Quellen mit Windows-Anmeldung
 *                                               ("KENNUNG dienst" je Zeile,
 *                                               fuer sso-domains.sh)
 *
 * Uebernahme von Altwerten: Frühere Versionen lasen die Zugangsdaten aus der
 * .env (LDAP_PASSWORD, LDAP_PASSWORD_<KENNUNG>, SSO_DOMAIN, SSO_DC,
 * SSO_DC_IP, SSO_JOIN_USER, SSO_JOIN_PASSWORD, SSO_<KENNUNG>_*). Sind sie
 * noch gesetzt und in der Datenbank noch nichts hinterlegt, werden sie
 * verschluesselt uebernommen. Danach koennen sie aus der .env entfernt werden.
 */

require_once dirname(__DIR__) . '/bootstrap.php';

use App\Core\Container;
use App\Core\Env;
use App\Services\IdentitySourceService;

$mode = $argv[1] ?? '';
$service = Container::identitySources();

try {
    Container::secretBox()->ensureKey();
} catch (\Throwable $exception) {
    fwrite(STDERR, $exception->getMessage() . PHP_EOL);
    exit(1);
}

if ($mode === '--key') {
    exit(0);
}

if ($mode === '--ldap-password') {
    fwrite(STDOUT, (string) $service->primaryConfig()['password']);
    exit(0);
}

if ($mode === '--sso-sources') {
    foreach ($service->ssoWorkers() as $worker) {
        fwrite(STDOUT, $worker['key'] . ' ' . $worker['service'] . PHP_EOL);
    }
    exit(0);
}

if ($mode === '--set-primary') {
    $input = [];
    foreach (preg_split('/\R/', (string) stream_get_contents(STDIN)) ?: [] as $line) {
        if (preg_match('/^(ldap_bind_password|sso_domain|sso_dcs|sso_join_user|sso_join_password)=([A-Za-z0-9+\/=]*)$/', trim($line), $match) === 1) {
            $input[$match[1]] = (string) base64_decode($match[2], true);
        }
    }

    [$changes, $errors] = IdentitySourceService::secretInput($input);
    $ssoValues = [];
    if (array_key_exists('sso_domain', $input)) {
        [$ssoValues, $ssoErrors] = IdentitySourceService::validateSso($input, false);
        $errors += $ssoErrors;
    }
    if ($errors !== []) {
        fwrite(STDERR, implode(PHP_EOL, $errors) . PHP_EOL);
        exit(1);
    }

    if ($ssoValues !== []) {
        Container::settings()->update($ssoValues);
    }
    $service->savePrimarySecrets($changes);
    fwrite(STDOUT, 'Zugangsdaten der Hauptquelle verschlüsselt gespeichert.' . PHP_EOL);
    exit(0);
}

if ($mode !== '') {
    fwrite(STDERR, 'Unbekannte Option: ' . $mode . PHP_EOL);
    exit(2);
}

/**
 * Wert aus der Umgebung (inkl. <NAME>_FILE), ohne Steuerzeichen.
 */
$legacy = static function (string $name): string {
    $value = trim((string) Env::get($name, ''));

    return preg_match('/[\x00-\x1F\x7F]/', $value) === 1 ? '' : $value;
};

/**
 * Formt SSO_DC / SSO_DC_IP (paarweise Listen) in Zeilen "host [ip]" um.
 */
$dcLines = static function (string $dcs, string $ips): string {
    $hosts = preg_split('/[\s,;]+/', $dcs, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    $addresses = preg_split('/[\s,;]+/', $ips, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    $lines = [];
    foreach ($hosts as $index => $host) {
        $ip = $addresses[$index] ?? '';
        $lines[] = trim($host . ' ' . ($ip === '-' ? '' : $ip));
    }

    return implode("\n", $lines);
};

$settings = Container::settings();
$imported = [];

try {
    // Hauptquelle
    $primary = [];
    $changes = [];
    $password = $legacy('LDAP_PASSWORD');
    if ($password !== '' && $settings->get('ldap_bind_password') === '') {
        $changes['ldap_bind_password'] = $password;
        $imported[] = 'LDAP_PASSWORD';
    }

    $domain = strtoupper($legacy('SSO_DOMAIN'));
    if ($domain !== '' && $domain !== 'WORKGROUP' && $settings->get('sso_domain') === '') {
        [$values, $errors] = IdentitySourceService::validateSso([
            'sso_domain' => $domain,
            'sso_dcs' => $dcLines($legacy('SSO_DC'), $legacy('SSO_DC_IP')),
            'sso_join_user' => $legacy('SSO_JOIN_USER'),
        ], false);
        unset($errors['sso_join_password']);
        if ($errors === []) {
            $primary = $values;
            $joinPassword = $legacy('SSO_JOIN_PASSWORD');
            if ($joinPassword !== '' && $settings->get('sso_join_password') === '') {
                $changes['sso_join_password'] = $joinPassword;
            }
            $imported[] = 'SSO_DOMAIN/SSO_DC/SSO_JOIN_*';
        } else {
            fwrite(STDERR, '[credentials] SSO-Altwerte der Hauptquelle nicht übernommen: ' . implode(' ', $errors) . PHP_EOL);
        }
    }

    if ($primary !== []) {
        $settings->update($primary);
    }
    $service->savePrimarySecrets($changes);

    // Weitere Quellen (nur bereits angelegte)
    foreach ($service->additionalRows(false) as $row) {
        $key = IdentitySourceService::normalizeKey((string) $row['source_key']);
        $values = IdentitySourceService::formValues($row);
        $changes = [];
        $update = false;

        $password = $legacy('LDAP_PASSWORD_' . $key);
        if ($password !== '' && (string) ($row['bind_password'] ?? '') === '') {
            $changes['ldap_bind_password'] = $password;
            $imported[] = 'LDAP_PASSWORD_' . $key;
        }

        $domain = strtoupper($legacy('SSO_' . $key . '_DOMAIN'));
        if ($domain !== '' && (string) ($row['sso_domain'] ?? '') === '') {
            [$sso, $errors] = IdentitySourceService::validateSso([
                'sso_enabled' => '1',
                'sso_domain' => $domain,
                'sso_dcs' => $dcLines($legacy('SSO_' . $key . '_DC'), $legacy('SSO_' . $key . '_DC_IP')),
                'sso_join_user' => $legacy('SSO_' . $key . '_JOIN_USER'),
                'sso_networks' => $legacy('SSO_' . $key . '_NETWORKS'),
                'sso_hostnames' => $legacy('SSO_' . $key . '_HOSTNAMES'),
            ], true);
            if ($errors === []) {
                $values = array_merge($values, $sso);
                $update = true;
                $joinPassword = $legacy('SSO_' . $key . '_JOIN_PASSWORD');
                if ($joinPassword !== '' && (string) ($row['sso_join_password'] ?? '') === '') {
                    $changes['sso_join_password'] = $joinPassword;
                }
                $imported[] = 'SSO_' . $key . '_*';
            } else {
                fwrite(STDERR, '[credentials] SSO-Altwerte der Quelle ' . $key . ' nicht übernommen: ' . implode(' ', $errors) . PHP_EOL);
            }
        }

        if ($update || $changes !== []) {
            $service->saveAdditional((int) $row['id'], $values, $changes);
        }
    }
} catch (\PDOException $exception) {
    fwrite(STDERR, '[credentials] Übernahme übersprungen: ' . $exception->getMessage() . PHP_EOL);
    exit(0);
}

if ($imported !== []) {
    fwrite(STDOUT, '[credentials] Aus der Umgebung verschlüsselt übernommen: ' . implode(', ', $imported)
        . '. Bitte diese Einträge aus der .env entfernen; gepflegt werden sie jetzt unter Verwaltung → Active Directory.' . PHP_EOL);
}
