<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Container;
use App\Core\Request;
use App\Core\Response;
use App\Exceptions\ValidationException;
use App\Security\Session;
use App\Services\Tls\TlsCertificateService;

/**
 * Adminbereich „Zertifikate (HTTPS)“: CSR erstellen, Zertifikat importieren
 * (Vorschau im Overlay + Bestaetigung), aktives Zertifikat waehlen, Quellnetze
 * fuer reines HTTP.
 */
final class CertificateController extends AdminController
{
    private const PENDING_KEY = 'tls_pending_import';

    public function index(Request $request): Response
    {
        return $this->render($request);
    }

    public function createRequest(Request $request): Response
    {
        $this->requireValidCsrf($request);

        $fields = ['common_name', 'san', 'organization', 'organizational_unit', 'locality', 'state', 'country', 'email', 'key_type'];
        $input = [];
        foreach ($fields as $field) {
            $input[$field] = (string) $request->input($field, '');
        }

        try {
            $id = Container::tlsCertificates()->createRequest($input, (string) Container::auth()->username());
        } catch (ValidationException $exception) {
            Session::flash('error', 'Der Request konnte nicht erstellt werden. Bitte die Angaben prüfen.');

            return $this->render($request, ['csrErrors' => $exception->errors(), 'csrValues' => $input], 422);
        }

        app_logger()->info('CSR für den auth-Container erstellt.', ['id' => $id, 'admin' => Container::auth()->username()]);
        Session::flash('success', 'Der Request wurde erstellt. Laden Sie den CSR herunter und lassen Sie ihn von Ihrer Zertifizierungsstelle signieren.');

        return $this->redirect('/admin/zertifikate?neu=' . $id . '#neuer-request');
    }

    public function downloadCsr(Request $request): Response
    {
        $download = Container::tlsCertificates()->csrDownload($request->queryInt('id'));
        if ($download === null) {
            Session::flash('error', 'Der Request wurde nicht gefunden.');

            return $this->redirect('/admin/zertifikate');
        }

        return Response::download($download['content'], $download['filename'], 'application/pkcs10')
            ->withHeader('Cache-Control', 'no-store');
    }

    public function previewImport(Request $request): Response
    {
        $this->requireValidCsrf($request);

        $raw = (string) ($request->post['certificate_text'] ?? '');
        $file = $request->files['certificate_file'] ?? ['error' => UPLOAD_ERR_NO_FILE];
        $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($error === UPLOAD_ERR_OK) {
            $name = strtolower((string) ($file['name'] ?? ''));
            if (preg_match('/\.(pem|crt|cer)$/', $name) !== 1) {
                return $this->importError($request, 'Bitte eine Datei im Format PEM oder CRT auswählen (.pem, .crt).');
            }
            $tmp = (string) ($file['tmp_name'] ?? '');
            $raw = is_uploaded_file($tmp) || is_file($tmp) ? (string) file_get_contents($tmp, false, null, 0, 262145) : '';
        } elseif ($error !== UPLOAD_ERR_NO_FILE) {
            return $this->importError($request, 'Die Datei konnte nicht hochgeladen werden.');
        }

        try {
            $preview = Container::tlsCertificates()->previewImport($raw);
        } catch (ValidationException $exception) {
            return $this->importError($request, (string) ($exception->errors()['certificate'] ?? 'Das Zertifikat konnte nicht gelesen werden.'));
        }

        Session::put(self::PENDING_KEY, $preview['pending']);

        return $this->redirect('/admin/zertifikate?vorschau=1');
    }

    public function confirmImport(Request $request): Response
    {
        $this->requireValidCsrf($request);

        $pending = Session::get(self::PENDING_KEY);
        Session::forget(self::PENDING_KEY);
        if (!is_array($pending)) {
            Session::flash('error', 'Es liegt kein Zertifikat zur Bestätigung vor. Bitte erneut importieren.');

            return $this->redirect('/admin/zertifikate');
        }

        try {
            $result = Container::tlsCertificates()->confirmImport($pending, $request->input('activate') === '1', (string) Container::auth()->username());
        } catch (ValidationException $exception) {
            Session::flash('error', (string) ($exception->errors()['certificate'] ?? 'Das Zertifikat konnte nicht übernommen werden.'));

            return $this->redirect('/admin/zertifikate');
        }

        app_logger()->info('Zertifikat für den auth-Container importiert.', [
            'id' => $result['id'],
            'activated' => $result['activated'],
            'admin' => Container::auth()->username(),
        ]);
        Session::flash('success', $result['activated']
            ? 'Das Zertifikat wurde importiert und ist aktiv. Der auth-Container übernimmt es innerhalb einer Minute; HTTP wird dann auf HTTPS umgeleitet.'
            : 'Das Zertifikat wurde importiert. Aktivieren Sie es in der Tabelle, sobald es verwendet werden soll.');

        return $this->redirect('/admin/zertifikate#request-' . $result['id']);
    }

    public function discardImport(Request $request): Response
    {
        $this->requireValidCsrf($request);
        Session::forget(self::PENDING_KEY);
        Session::flash('success', 'Der Import wurde abgebrochen. Es wurde nichts geändert.');

        return $this->redirect('/admin/zertifikate');
    }

    public function activate(Request $request): Response
    {
        $this->requireValidCsrf($request);
        $id = $request->inputInt('id');

        try {
            Container::tlsCertificates()->activate($id);
        } catch (ValidationException $exception) {
            Session::flash('error', (string) ($exception->errors()['certificate'] ?? 'Das Zertifikat kann nicht aktiviert werden.'));

            return $this->redirect('/admin/zertifikate');
        }

        app_logger()->info('Aktives HTTPS-Zertifikat gewechselt.', ['id' => $id, 'admin' => Container::auth()->username()]);
        Session::flash('success', 'Das Zertifikat ist jetzt aktiv. Der auth-Container übernimmt es innerhalb einer Minute.');

        return $this->redirect('/admin/zertifikate#request-' . $id);
    }

    public function deactivate(Request $request): Response
    {
        $this->requireValidCsrf($request);
        Container::tlsCertificates()->deactivate();

        app_logger()->info('HTTPS-Zertifikat deaktiviert.', ['admin' => Container::auth()->username()]);
        Session::flash('success', 'Das Zertifikat wurde deaktiviert. Bis zur Aktivierung eines anderen Zertifikats wird das Notfall-Zertifikat verwendet.');

        return $this->redirect('/admin/zertifikate');
    }

    public function delete(Request $request): Response
    {
        $this->requireValidCsrf($request);

        try {
            Container::tlsCertificates()->deleteRequest($request->inputInt('id'));
        } catch (ValidationException $exception) {
            Session::flash('error', (string) ($exception->errors()['certificate'] ?? 'Der Request kann nicht gelöscht werden.'));

            return $this->redirect('/admin/zertifikate');
        }

        Session::flash('success', 'Der Request wurde gelöscht.');

        return $this->redirect('/admin/zertifikate');
    }

    public function updateNetworks(Request $request): Response
    {
        $this->requireValidCsrf($request);
        $input = (string) $request->input('tls_http_networks', '');

        try {
            $networks = Container::tlsCertificates()->updateHttpNetworks($input);
        } catch (ValidationException $exception) {
            Session::flash('error', 'Bitte die Quellnetze prüfen.');

            return $this->render($request, ['networkErrors' => $exception->errors(), 'networkInput' => $input], 422);
        }

        app_logger()->info('Quellnetze für HTTP-Zugriff geändert.', ['networks' => $networks, 'admin' => Container::auth()->username()]);
        Session::flash('success', 'Die Quellnetze wurden gespeichert. Der auth-Container übernimmt sie innerhalb einer Minute.');

        return $this->redirect('/admin/zertifikate#http-zugriff');
    }

    private function importError(Request $request, string $message): Response
    {
        Session::forget(self::PENDING_KEY);
        Session::flash('error', $message);

        return $this->redirect('/admin/zertifikate#import');
    }

    /**
     * @param array<string,mixed> $data
     */
    private function render(Request $request, array $data = [], int $status = 200): Response
    {
        $service = Container::tlsCertificates();

        $preview = null;
        $pending = Session::get(self::PENDING_KEY);
        if ($request->query('vorschau') === '1' && is_array($pending)) {
            try {
                $preview = $service->previewImport(implode("\n", array_filter((array) ($pending['certificates'] ?? []), 'is_string')));
            } catch (ValidationException $exception) {
                Session::forget(self::PENDING_KEY);
                Session::flash('error', (string) ($exception->errors()['certificate'] ?? 'Die Vorschau ist nicht mehr gültig.'));
            }
        }

        $networks = $service->httpNetworks();
        $highlightId = $request->queryInt('neu');
        $newCsr = $highlightId > 0 ? $service->csrDownload($highlightId) : null;

        return $this->adminView('admin.certificates', array_merge([
            'pageTitle' => 'Zertifikate (HTTPS)',
            'activeNav' => 'certificates',
            'pageScript' => 'admin-certificates.js',
            'state' => $service->state(),
            'rows' => $service->overview(),
            'keyTypes' => TlsCertificateService::keyTypes(),
            'csrValues' => $service->csrDefaults(),
            'csrErrors' => [],
            'networkInput' => implode("\n", $networks),
            'networkErrors' => [],
            'highlightId' => $highlightId,
            'newCsr' => $newCsr['content'] ?? null,
            'preview' => $preview,
        ], $data), $status);
    }
}
