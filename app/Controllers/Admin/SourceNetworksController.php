<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Container;
use App\Core\Request;
use App\Core\Response;
use App\Security\Session;
use App\Support\IpNetwork;
use App\Support\SourceNetworks;

/**
 * Admin → System → Bekannte Quellnetze.
 *
 * Der auth-Container meldet die Anfragen je Quellnetz verfeinert (/24 bei
 * IPv4). Hier lassen sich groessere Netze eintragen, deren Adressbereich aus
 * dem Praefix errechnet wird; die enthaltenen gemeldeten Netze werden in der
 * Statistik zu dem bekannten Netz zusammengefasst.
 */
final class SourceNetworksController extends AdminController
{
    /** Laengenbegrenzung der Eingabe (Zeichen). */
    private const MAX_INPUT_LENGTH = 2000;

    public function index(Request $request): Response
    {
        return $this->render();
    }

    public function update(Request $request): Response
    {
        $this->requireValidCsrf($request);

        $input = trim((string) $request->input('auth_known_source_networks', ''));
        $errors = [];

        if (strlen($input) > self::MAX_INPUT_LENGTH) {
            $errors['auth_known_source_networks'] = sprintf(
                'Die Eingabe ist zu lang (höchstens %d Zeichen).',
                self::MAX_INPUT_LENGTH
            );
        } else {
            $parsed = IpNetwork::parseListDetailed($input);
            if ($parsed['invalid'] !== []) {
                $errors['auth_known_source_networks'] = sprintf(
                    '„%s“ ist kein gültiges Netz (Beispiel: 192.168.200.0/21).',
                    $parsed['invalid'][0]
                );
            } elseif (count($parsed['networks']) > SourceNetworks::MAX_NETWORKS) {
                $errors['auth_known_source_networks'] = sprintf(
                    'Es sind höchstens %d Netze möglich.',
                    SourceNetworks::MAX_NETWORKS
                );
            }
        }

        if ($errors !== []) {
            Session::flash('error', 'Bitte prüfen Sie die bekannten Quellnetze.');

            return $this->render($errors, $input, 422);
        }

        $networks = SourceNetworks::fromSetting($input);
        Container::settings()->update([
            'auth_known_source_networks' => $networks === [] ? 'none' : implode("\n", $networks),
        ]);
        app_logger()->info('Bekannte Quellnetze geändert.', [
            'admin' => Container::auth()->username(),
            'networks' => $networks,
        ]);
        Session::flash('success', $networks === []
            ? 'Die bekannten Quellnetze wurden entfernt.'
            : 'Die bekannten Quellnetze wurden gespeichert.');

        return $this->redirect('/admin/quellnetze');
    }

    /**
     * @param array<string,string> $errors
     */
    private function render(array $errors = [], ?string $input = null, int $status = 200): Response
    {
        $stored = SourceNetworks::fromSetting(Container::settings()->get('auth_known_source_networks'));

        $networks = [];
        foreach ($stored as $network) {
            $described = SourceNetworks::describe($network);
            if ($described !== null) {
                $networks[] = $described;
            }
        }

        return $this->adminView('admin.source-networks', [
            'pageTitle' => 'Bekannte Quellnetze',
            'activeNav' => 'source_networks',
            'value' => $input ?? implode("\n", $stored),
            'networks' => $networks,
            'preview' => Container::authMetrics()->groupingPreview(),
            'errors' => $errors,
        ], $status);
    }
}
