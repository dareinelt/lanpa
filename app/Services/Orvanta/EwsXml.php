<?php

declare(strict_types=1);

namespace App\Services\Orvanta;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;

/**
 * Hilfsfunktionen fuer EWS-SOAP (Exchange Web Services): Aufbau des Umschlags
 * mit Impersonation-Header, XML-Escaping und Auswertung der Antworten mit
 * DOMXPath (Namensraeume t = types, m = messages).
 */
final class EwsXml
{
    public const NS_SOAP = 'http://schemas.xmlsoap.org/soap/envelope/';
    public const NS_TYPES = 'http://schemas.microsoft.com/exchange/services/2006/types';
    public const NS_MESSAGES = 'http://schemas.microsoft.com/exchange/services/2006/messages';

    public static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    /**
     * Vollstaendiger SOAP-Umschlag. $impersonate = SMTP-Adresse oder UPN des
     * Benutzers, in dessen Postfach gearbeitet wird ('' = Dienstkonto selbst).
     */
    public static function envelope(string $body, string $version, string $impersonate = '', string $timeZone = 'W. Europe Standard Time'): string
    {
        $header = '<t:RequestServerVersion Version="' . self::escape($version) . '"/>';
        if ($impersonate !== '') {
            $kind = str_contains($impersonate, '@') ? 'PrimarySmtpAddress' : 'PrincipalName';
            $header .= '<t:ExchangeImpersonation><t:ConnectingSID><t:' . $kind . '>' . self::escape($impersonate) . '</t:' . $kind . '></t:ConnectingSID></t:ExchangeImpersonation>';
        }
        $header .= '<t:TimeZoneContext><t:TimeZoneDefinition Id="' . self::escape($timeZone) . '"/></t:TimeZoneContext>';

        return '<?xml version="1.0" encoding="utf-8"?>'
            . '<soap:Envelope xmlns:soap="' . self::NS_SOAP . '" xmlns:t="' . self::NS_TYPES . '" xmlns:m="' . self::NS_MESSAGES . '">'
            . '<soap:Header>' . $header . '</soap:Header>'
            . '<soap:Body>' . $body . '</soap:Body>'
            . '</soap:Envelope>';
    }

    /**
     * Parst eine EWS-Antwort. Liefert XPath mit registrierten Namensraeumen
     * oder null bei ungueltigem XML.
     */
    public static function parse(string $xml): ?DOMXPath
    {
        if (trim($xml) === '') {
            return null;
        }
        $document = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $loaded = $document->loadXML($xml, LIBXML_NONET | LIBXML_NOCDATA);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if (!$loaded) {
            return null;
        }
        $xpath = new DOMXPath($document);
        $xpath->registerNamespace('soap', self::NS_SOAP);
        $xpath->registerNamespace('t', self::NS_TYPES);
        $xpath->registerNamespace('m', self::NS_MESSAGES);

        return $xpath;
    }

    /**
     * Fehlertext aus SOAP-Fault oder fehlerhafter ResponseMessage, sonst null.
     */
    public static function error(DOMXPath $xpath): ?string
    {
        $fault = $xpath->query('//soap:Fault');
        if ($fault !== false && $fault->length > 0) {
            $text = self::text($xpath, 'faultstring', $fault->item(0));
            $code = self::text($xpath, './/m:ResponseCode | .//*[local-name()="ResponseCode"]', $fault->item(0));

            return trim($text . ($code !== '' ? ' (' . $code . ')' : '')) ?: 'SOAP-Fehler';
        }

        $errors = $xpath->query('//m:ResponseMessages/*[@ResponseClass="Error"]');
        if ($errors !== false && $errors->length > 0) {
            $node = $errors->item(0);
            $text = self::text($xpath, 'm:MessageText', $node);
            $code = self::text($xpath, 'm:ResponseCode', $node);

            return trim($text . ($code !== '' ? ' (' . $code . ')' : '')) ?: 'Exchange hat die Anfrage abgelehnt.';
        }

        return null;
    }

    public static function text(DOMXPath $xpath, string $query, ?DOMNode $context = null): string
    {
        $nodes = $xpath->query($query, $context);
        if ($nodes === false || $nodes->length === 0) {
            return '';
        }

        return trim((string) $nodes->item(0)?->textContent);
    }

    public static function attr(DOMXPath $xpath, string $query, string $attribute, ?DOMNode $context = null): string
    {
        $nodes = $xpath->query($query, $context);
        $node = $nodes !== false && $nodes->length > 0 ? $nodes->item(0) : null;

        return $node instanceof DOMElement ? $node->getAttribute($attribute) : '';
    }

    public static function bool(DOMXPath $xpath, string $query, ?DOMNode $context = null): bool
    {
        return strtolower(self::text($xpath, $query, $context)) === 'true';
    }

    /**
     * @return list<DOMElement>
     */
    public static function elements(DOMXPath $xpath, string $query, ?DOMNode $context = null): array
    {
        $nodes = $xpath->query($query, $context);
        $result = [];
        if ($nodes !== false) {
            foreach ($nodes as $node) {
                if ($node instanceof DOMElement) {
                    $result[] = $node;
                }
            }
        }

        return $result;
    }

    /**
     * @return array{id:string,change_key:string}
     */
    public static function itemId(DOMXPath $xpath, DOMNode $context, string $element = 't:ItemId'): array
    {
        return [
            'id' => self::attr($xpath, $element, 'Id', $context),
            'change_key' => self::attr($xpath, $element, 'ChangeKey', $context),
        ];
    }

    /**
     * @return array{name:string,email:string}
     */
    public static function mailbox(DOMXPath $xpath, string $query, ?DOMNode $context = null): array
    {
        return [
            'name' => self::text($xpath, $query . '/t:Mailbox/t:Name', $context),
            'email' => self::text($xpath, $query . '/t:Mailbox/t:EmailAddress', $context),
        ];
    }

    /**
     * @return list<array{name:string,email:string}>
     */
    public static function mailboxes(DOMXPath $xpath, string $query, ?DOMNode $context = null): array
    {
        $list = [];
        foreach (self::elements($xpath, $query . '/t:Mailbox', $context) as $mailbox) {
            $list[] = [
                'name' => self::text($xpath, 't:Name', $mailbox),
                'email' => self::text($xpath, 't:EmailAddress', $mailbox),
            ];
        }

        return $list;
    }

    /**
     * XML-Fragment fuer eine Liste von Empfaengern.
     *
     * @param list<string> $addresses
     */
    public static function recipients(string $element, array $addresses): string
    {
        if ($addresses === []) {
            return '';
        }
        $xml = '<t:' . $element . '>';
        foreach ($addresses as $address) {
            $xml .= '<t:Mailbox><t:EmailAddress>' . self::escape($address) . '</t:EmailAddress></t:Mailbox>';
        }

        return $xml . '</t:' . $element . '>';
    }

    /**
     * XML-Fragment fuer ItemId-Listen.
     *
     * @param list<array{id:string,change_key?:string}> $ids
     */
    public static function itemIds(array $ids): string
    {
        $xml = '<m:ItemIds>';
        foreach ($ids as $id) {
            $xml .= '<t:ItemId Id="' . self::escape($id['id']) . '"' . (($id['change_key'] ?? '') !== '' ? ' ChangeKey="' . self::escape((string) $id['change_key']) . '"' : '') . '/>';
        }

        return $xml . '</m:ItemIds>';
    }

    /**
     * Ordnerreferenz: Distinguished (inbox, calendar, …) oder konkrete FolderId.
     */
    public static function folderId(string $folder): string
    {
        if (preg_match('/^[a-z]+$/', $folder) === 1) {
            return '<t:DistinguishedFolderId Id="' . self::escape($folder) . '"/>';
        }

        return '<t:FolderId Id="' . self::escape($folder) . '"/>';
    }

    /**
     * EWS-Zeitstempel (UTC, ISO 8601 mit Z).
     */
    public static function dateTime(int $timestamp): string
    {
        return gmdate('Y-m-d\TH:i:s\Z', $timestamp);
    }

    /**
     * Zeitstempel aus EWS-Antwort in Unix-Zeit (0 bei leer/ungueltig).
     */
    public static function timestamp(string $value): int
    {
        if ($value === '') {
            return 0;
        }
        $time = strtotime($value);

        return $time === false ? 0 : $time;
    }
}
