<?php

declare(strict_types=1);

namespace App\Services\Tls;

use App\Exceptions\ValidationException;

/**
 * Liest X.509-Zertifikate (PEM oder CRT, letzteres Base64/PEM- oder
 * DER-kodiert) und liefert ihre Eigenschaften fuer Vorschau, Speicherung und
 * Statusanzeige. Die Konvertierung erfolgt mit der PHP-Erweiterung openssl.
 */
final class CertificateInspector
{
    public const EXPIRY_WARNING_DAYS = 30;

    public const STATUS_NONE = 'none';
    public const STATUS_VALID = 'valid';
    public const STATUS_EXPIRING = 'expiring';
    public const STATUS_EXPIRED = 'expired';
    public const STATUS_NOT_YET_VALID = 'not_yet_valid';

    private const MAX_BYTES = 256 * 1024;

    private const PEM_PATTERN = '/-----BEGIN (?:X509 |TRUSTED )?CERTIFICATE-----\s*([A-Za-z0-9+\/=\s]+?)\s*-----END (?:X509 |TRUSTED )?CERTIFICATE-----/';

    /**
     * Zerlegt eine hochgeladene Datei bzw. eingefuegten Text in einzelne
     * Zertifikate (normalisiertes PEM). Akzeptiert PEM/CRT mit einem oder
     * mehreren Zertifikaten (Zertifikat + Zwischenzertifikate) sowie
     * DER-kodierte CRT-Dateien.
     *
     * @return list<string>
     */
    public static function extractCertificates(string $raw): array
    {
        if (trim($raw) === '') {
            throw new ValidationException(['certificate' => 'Bitte eine Zertifikatsdatei (PEM oder CRT) auswählen oder den Inhalt einfügen.']);
        }
        if (strlen($raw) > self::MAX_BYTES) {
            throw new ValidationException(['certificate' => 'Die Datei ist zu groß (maximal 256 KB).']);
        }
        if (preg_match('/-----BEGIN [A-Z ]*PRIVATE KEY-----/', $raw) === 1) {
            throw new ValidationException(['certificate' => 'Die Datei enthält einen privaten Schlüssel. Bitte nur das Zertifikat importieren – der Schlüssel liegt bereits sicher in der Anwendung.']);
        }

        $certificates = [];
        if (preg_match_all(self::PEM_PATTERN, $raw, $matches) > 0) {
            foreach ($matches[1] as $body) {
                $der = base64_decode((string) preg_replace('/\s+/', '', $body), true);
                if ($der === false || $der === '') {
                    throw new ValidationException(['certificate' => 'Ein Zertifikat in der Datei ist beschädigt (ungültiges Base64).']);
                }
                $certificates[] = self::derToPem($der);
            }
        } elseif (str_contains($raw, '-----BEGIN')) {
            throw new ValidationException(['certificate' => 'Die Datei enthält kein Zertifikat (erwartet: „BEGIN CERTIFICATE“). Ein CSR oder Schlüssel kann nicht importiert werden.']);
        } else {
            // CRT im DER-Format (binaer) bzw. reines Base64 ohne Kopfzeilen.
            $compact = (string) preg_replace('/\s+/', '', $raw);
            $decoded = preg_match('/^[A-Za-z0-9+\/]+={0,2}$/', $compact) === 1 ? base64_decode($compact, true) : false;
            $der = is_string($decoded) && $decoded !== '' && ord($decoded[0]) === 0x30 ? $decoded : $raw;
            if (ord($der[0]) !== 0x30) {
                throw new ValidationException(['certificate' => 'Das Format wurde nicht erkannt. Unterstützt werden PEM- und CRT-Dateien (Base64 oder DER).']);
            }
            $certificates[] = self::derToPem($der);
        }

        foreach ($certificates as $pem) {
            if (@openssl_x509_read($pem) === false) {
                throw new ValidationException(['certificate' => 'Die Datei enthält kein gültiges X.509-Zertifikat.']);
            }
        }

        return $certificates;
    }

    public static function derToPem(string $der): string
    {
        return "-----BEGIN CERTIFICATE-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END CERTIFICATE-----\n";
    }

    /**
     * @return array{
     *     subject:string, subject_fields:array<string,string>, common_name:string, issuer:string, issuer_cn:string,
     *     san:list<string>, serial:string, not_before:int, not_after:int, fingerprint:string,
     *     public_key_hash:string, key_type:string, signature:string, self_signed:bool, is_ca:bool
     * }
     */
    public static function details(string $pem): array
    {
        $parsed = @openssl_x509_parse($pem);
        $public = @openssl_pkey_get_public($pem);
        if (!is_array($parsed) || $public === false) {
            throw new ValidationException(['certificate' => 'Das Zertifikat kann nicht gelesen werden.']);
        }

        $subjectFields = self::flatten((array) ($parsed['subject'] ?? []));
        $issuerFields = self::flatten((array) ($parsed['issuer'] ?? []));
        $extensions = (array) ($parsed['extensions'] ?? []);

        return [
            'subject' => self::dnString($subjectFields),
            'subject_fields' => $subjectFields,
            'common_name' => $subjectFields['CN'] ?? '',
            'issuer' => self::dnString($issuerFields),
            'issuer_cn' => $issuerFields['CN'] ?? ($issuerFields['O'] ?? ''),
            'san' => self::parseSan((string) ($extensions['subjectAltName'] ?? '')),
            'serial' => strtoupper((string) ($parsed['serialNumberHex'] ?? '')),
            'not_before' => (int) ($parsed['validFrom_time_t'] ?? 0),
            'not_after' => (int) ($parsed['validTo_time_t'] ?? 0),
            'fingerprint' => strtoupper((string) openssl_x509_fingerprint($pem, 'sha256')),
            'public_key_hash' => self::publicKeyHash($public),
            'key_type' => self::keyType($public),
            'signature' => (string) ($parsed['signatureTypeSN'] ?? ''),
            'self_signed' => $subjectFields === $issuerFields,
            'is_ca' => str_contains(strtoupper((string) ($extensions['basicConstraints'] ?? '')), 'CA:TRUE'),
        ];
    }

    /**
     * Eindeutiger Wert fuer einen oeffentlichen Schluessel (Zuordnung
     * Zertifikat <-> CSR/privater Schluessel).
     */
    public static function publicKeyHash(\OpenSSLAsymmetricKey $key): string
    {
        $details = openssl_pkey_get_details($key);
        $pem = is_array($details) ? (string) ($details['key'] ?? '') : '';

        return hash('sha256', (string) preg_replace('/\s+/', '', $pem));
    }

    public static function keyType(\OpenSSLAsymmetricKey $key): string
    {
        $details = openssl_pkey_get_details($key);
        if (!is_array($details)) {
            return 'unbekannt';
        }

        $bits = (int) ($details['bits'] ?? 0);

        return match ((int) ($details['type'] ?? -1)) {
            OPENSSL_KEYTYPE_RSA => 'RSA ' . $bits,
            OPENSSL_KEYTYPE_EC => 'ECDSA ' . match ((string) ($details['ec']['curve_name'] ?? '')) {
                'prime256v1' => 'P-256',
                'secp384r1' => 'P-384',
                'secp521r1' => 'P-521',
                default => $bits . ' Bit',
            },
            default => $bits . ' Bit',
        };
    }

    /**
     * Ordnet die uebrigen Zertifikate als Kette hinter dem Serverzertifikat an
     * (Aussteller-Reihenfolge); nicht zuordenbare werden angehaengt.
     *
     * @param list<string> $others
     *
     * @return list<string>
     */
    public static function orderChain(string $leaf, array $others): array
    {
        $remaining = [];
        foreach ($others as $pem) {
            $info = @openssl_x509_parse($pem);
            $remaining[] = [
                'pem' => $pem,
                'subject' => is_array($info) ? self::flatten((array) ($info['subject'] ?? [])) : [],
                'issuer' => is_array($info) ? self::flatten((array) ($info['issuer'] ?? [])) : [],
            ];
        }

        $chain = [];
        $current = @openssl_x509_parse($leaf);
        $issuer = is_array($current) ? self::flatten((array) ($current['issuer'] ?? [])) : [];
        while ($remaining !== []) {
            $found = null;
            foreach ($remaining as $index => $candidate) {
                if ($candidate['subject'] === $issuer) {
                    $found = $index;
                    break;
                }
            }
            if ($found === null) {
                break;
            }
            $chain[] = $remaining[$found]['pem'];
            $selfSigned = $remaining[$found]['subject'] === $remaining[$found]['issuer'];
            $issuer = $remaining[$found]['issuer'];
            unset($remaining[$found]);
            if ($selfSigned) {
                break;
            }
        }

        foreach ($remaining as $candidate) {
            $chain[] = $candidate['pem'];
        }

        return $chain;
    }

    public static function status(?int $notBefore, ?int $notAfter, int $now): string
    {
        if ($notBefore === null || $notAfter === null || $notAfter === 0) {
            return self::STATUS_NONE;
        }
        if ($now < $notBefore) {
            return self::STATUS_NOT_YET_VALID;
        }
        if ($now >= $notAfter) {
            return self::STATUS_EXPIRED;
        }

        return $notAfter - $now <= self::EXPIRY_WARNING_DAYS * 86400 ? self::STATUS_EXPIRING : self::STATUS_VALID;
    }

    public static function isUsable(string $status): bool
    {
        return $status === self::STATUS_VALID || $status === self::STATUS_EXPIRING;
    }

    /**
     * Deckt das Zertifikat den Hostnamen ab (inkl. Wildcard *.domain)?
     *
     * @param list<string> $san
     */
    public static function coversHost(array $san, string $commonName, string $host): bool
    {
        $host = strtolower(trim($host, '[]'));
        if ($host === '') {
            return true;
        }

        $names = $san !== [] ? $san : [$commonName];
        foreach ($names as $name) {
            $name = strtolower($name);
            if ($name === $host) {
                return true;
            }
            if (str_starts_with($name, '*.') && filter_var($host, FILTER_VALIDATE_IP) === false) {
                $dot = strpos($host, '.');
                if ($dot !== false && substr($host, $dot + 1) === substr($name, 2)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    public static function parseSan(string $value): array
    {
        $names = [];
        foreach (explode(',', $value) as $part) {
            $part = trim($part);
            if (str_starts_with($part, 'DNS:')) {
                $names[] = substr($part, 4);
            } elseif (str_starts_with($part, 'IP Address:')) {
                $names[] = substr($part, 11);
            }
        }

        return $names;
    }

    /**
     * @param array<string,mixed> $fields
     *
     * @return array<string,string>
     */
    private static function flatten(array $fields): array
    {
        $result = [];
        foreach ($fields as $name => $value) {
            $result[(string) $name] = is_array($value) ? implode(', ', array_map('strval', $value)) : (string) $value;
        }

        return $result;
    }

    /**
     * @param array<string,string> $fields
     */
    private static function dnString(array $fields): string
    {
        $parts = [];
        foreach ($fields as $name => $value) {
            $parts[] = $name . '=' . $value;
        }

        return implode(', ', $parts);
    }
}
