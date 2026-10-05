<?php

declare(strict_types=1);

namespace App\Contracts;

/**
 * HTTP-Transport fuer Exchange Web Services (SOAP). Austauschbar fuer Tests
 * (Fake mit vorbereiteten Antworten) und fuer den Produktivbetrieb (cURL mit
 * Negotiate/Kerberos, NTLM oder Basic).
 */
interface ExchangeTransportInterface
{
    /**
     * @param array{auth:string,username:string,password:string,timeout:int,verify_tls:bool,headers?:array<string,string>} $options
     *
     * @return array{status:int,body:string,error:?string,auth_offered?:list<string>}
     */
    public function post(string $url, string $xml, array $options): array;
}
