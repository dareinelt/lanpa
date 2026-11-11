<?php

declare(strict_types=1);

namespace App\Controllers\Helpdesk;

use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Security\CurrentUser;
use App\Services\Helpdesk\HelpdeskContactService;

/**
 * Kontaktverzeichnis des Help Desk: gemeinsames Adressbuch aller Agenten mit Suche (inkl. Ähnlichkeit)
 * und Stichwortfilter; alphabetisch nach Firma sortiert.
 */
final class HelpdeskContactController extends HelpdeskBaseController
{
    public function __construct(
        View $view,
        CurrentUser $currentUser,
        private readonly HelpdeskContactService $contacts
    ) {
        parent::__construct($view, $currentUser);
    }

    public function index(Request $request): Response
    {
        $query = $request->queryString('q');
        $tag = $request->queryString('tag');
        $page = max(1, (int) $request->queryString('page', '1'));
        $result = $this->contacts->search($query, $tag, $page);

        return $this->render('helpdesk.kontakte.index', [
            'title' => 'Kontaktverzeichnis',
            'activeNav' => 'helpdesk-kontakte',
            'areaLabel' => 'Help Desk',
            'rows' => $result['rows'],
            'paginator' => $result['paginator'],
            'similarCount' => $result['similar'],
            'filters' => ['q' => $query, 'tag' => $tag],
            'allTags' => $this->contacts->tags(),
            'canEdit' => $this->currentUser->can('helpdesk.update'),
            'scripts' => ['/js/helpdesk-contacts.js'],
        ]);
    }

    public function create(Request $request): Response
    {
        $this->currentUser->require('helpdesk.update');

        return $this->renderForm(null, HelpdeskContactService::formValues(null));
    }

    public function store(Request $request): Response
    {
        $contact = null;
        $response = $this->attempt($request, function () use ($request, &$contact): void {
            $contact = $this->contacts->create($request->all());
        }, '/helpdesk/kontakte/neu');

        return $response ?? $this->respond($request, 'Kontakt angelegt.', $this->listUrl($contact));
    }

    public function edit(Request $request): Response
    {
        $contact = $this->contacts->get($request->paramInt('id'));

        return $this->renderForm($contact, HelpdeskContactService::formValues($contact));
    }

    public function update(Request $request): Response
    {
        $id = $request->paramInt('id');
        $contact = null;
        $response = $this->attempt($request, function () use ($request, $id, &$contact): void {
            $contact = $this->contacts->update($id, $request->all());
        }, '/helpdesk/kontakte/' . $id);

        return $response ?? $this->respond($request, 'Kontakt gespeichert.', $this->listUrl($contact));
    }

    public function delete(Request $request): Response
    {
        $this->contacts->delete($request->paramInt('id'));

        return $this->respond($request, 'Kontakt gelöscht.', '/helpdesk/kontakte');
    }

    /**
     * @param array<string,mixed>|null $contact
     * @param array<string,string> $values
     */
    private function renderForm(?array $contact, array $values): Response
    {
        $old = $_SESSION['_old_input'] ?? [];
        foreach (array_keys($values) as $key) {
            if (array_key_exists($key, $old)) {
                $values[$key] = (string) $old[$key];
            }
        }

        return $this->render('helpdesk.kontakte.form', [
            'title' => $contact === null ? 'Neuer Kontakt' : HelpdeskContactService::label($contact),
            'activeNav' => 'helpdesk-kontakte',
            'areaLabel' => 'Help Desk',
            'contact' => $contact,
            'values' => $values,
            'allTags' => $this->contacts->tags(),
            'canEdit' => $this->currentUser->can('helpdesk.update'),
            'scripts' => ['/js/helpdesk-contacts.js'],
        ]);
    }

    /** Rücksprung in die Liste, gefiltert auf die Firma des Kontakts. @param array<string,mixed>|null $contact */
    private function listUrl(?array $contact): string
    {
        if ($contact === null) {
            return '/helpdesk/kontakte';
        }

        return '/helpdesk/kontakte?q=' . rawurlencode((string) $contact['company']);
    }
}
