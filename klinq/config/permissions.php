<?php

declare(strict_types=1);

/**
 * Rollen → Rechte. Rechte werden serverseitig in der AuthorizationMiddleware
 * (Route) und in Controllern/Services geprüft.
 *
 * Konvention: "<bereich>.view" / "<bereich>.manage".
 */
$all = [
    'dashboard.view',
    'assets.view', 'assets.manage', 'assets.retire',
    'labels.print',
    'movements.view', 'movements.checkout', 'movements.return', 'movements.complete',
    'handover.view', 'handover.manage', 'handover.sign', 'handover.template',
    'employees.view', 'employees.manage', 'employees.sync',
    'locations.view', 'locations.manage',
    'costcenters.view', 'costcenters.manage',
    'manufacturers.view', 'manufacturers.manage',
    'articles.view', 'articles.manage',
    'suppliers.view', 'suppliers.manage',
    'orders.view', 'orders.manage', 'orders.receive',
    'documents.view', 'documents.manage',
    'licenses.view', 'licenses.manage',
    'reports.view', 'reports.export',
    'imports.manage',
    'audit.view',
    'settings.manage',
    'users.manage',
    // Help Desk – Benutzerportal (eigene Tickets) und Agentenbereich
    'portal.view', 'portal.create',
    'helpdesk.view', 'helpdesk.create', 'helpdesk.update', 'helpdesk.assign',
    'helpdesk.comment', 'helpdesk.internal_note', 'helpdesk.close', 'helpdesk.reopen',
    'helpdesk.merge', 'helpdesk.escalate', 'helpdesk.sla', 'helpdesk.worklog', 'helpdesk.delete',
    'helpdesk.cancel', 'helpdesk.reports', 'helpdesk.export', 'helpdesk.view_history',
    'helpdesk.tasks', 'helpdesk.tasks.assign',
    'helpdesk.categories', 'helpdesk.templates', 'helpdesk.admin',
    'knowledgebase.view', 'knowledgebase.manage',
];

// Alle Leserechte – außer dem Audit-Log (enthält Benutzer- und IP-Daten; nur Admin und Assetmanagement)
// und dem Help-Desk-Agentenbereich (fremde Tickets sehen nur Help-Desk-Rollen).
// Grundlage der Rollen mit Assetzugriff, nicht der Rolle "readonly" (siehe unten).
$allViews = array_values(array_filter($all, static fn (string $p): bool => str_ends_with($p, '.view') && $p !== 'audit.view' && $p !== 'helpdesk.view'));

// Rechte des Help-Desk-Agenten (Tickets bearbeiten, ohne Stammdaten/SLA-Pflege)
// helpdesk.reports (Ticket-Berichte), helpdesk.delete (Ticket löschen) und helpdesk.cancel
// (Ticket stornieren) sind bewusst nicht enthalten: diese Rechte werden nur über die
// Berechtigungsgruppen "Statistik", "ticket_loeschen" und "ticket_stornieren" vergeben
// (siehe $groups unten), ausgenommen Admins.
$helpdeskAgent = [
    'portal.view', 'portal.create',
    'helpdesk.view', 'helpdesk.create', 'helpdesk.update', 'helpdesk.assign',
    'helpdesk.comment', 'helpdesk.internal_note', 'helpdesk.close', 'helpdesk.reopen',
    'helpdesk.merge', 'helpdesk.worklog', 'helpdesk.tasks',
    'knowledgebase.view', 'knowledgebase.manage',
];
$helpdeskLead = array_merge($helpdeskAgent, [
    'helpdesk.escalate', 'helpdesk.sla', 'helpdesk.export', 'helpdesk.categories', 'helpdesk.templates',
]);
$helpdeskAdmin = array_merge($helpdeskLead, ['helpdesk.admin', 'documents.manage']);
// Alle Mitarbeiter dürfen im Portal eigene Tickets erstellen und Wissensartikel lesen.
// Die Rolle "portal" enthält bewusst keine Rechte der Assetverwaltung: Mitarbeitende ohne
// ausdrückliche KLINQ-Berechtigung sehen ausschließlich ihren eigenen Help-Desk-Bereich.
$portal = ['portal.view', 'portal.create', 'knowledgebase.view'];

return [
    'all' => $all,
    'roles' => [
        'admin' => $all,
        'assetmanagement' => array_values(array_unique(array_merge($allViews, $portal, [
            'assets.manage', 'assets.retire', 'labels.print',
            'movements.checkout', 'movements.return', 'movements.complete',
            'handover.manage', 'handover.sign',
            'employees.manage', 'employees.sync',
            'locations.manage', 'costcenters.manage',
            'manufacturers.manage', 'articles.manage',
            'documents.manage', 'licenses.manage',
            'reports.export', 'imports.manage', 'audit.view',
        ]))),
        'lager' => array_values(array_unique(array_merge($allViews, $portal, [
            'labels.print',
            'movements.checkout', 'movements.return', 'movements.complete',
            'handover.manage', 'handover.sign',
            'orders.receive', 'assets.manage', 'documents.manage',
        ]))),
        'einkauf' => array_values(array_unique(array_merge($allViews, $portal, [
            'suppliers.manage', 'orders.manage', 'orders.receive',
            'manufacturers.manage', 'articles.manage', 'documents.manage',
            'licenses.manage', 'reports.export',
        ]))),
        'helpdesk_admin' => array_values(array_unique(array_merge($allViews, $helpdeskAdmin))),
        'helpdesk_lead' => array_values(array_unique(array_merge($allViews, $helpdeskLead))),
        'helpdesk_agent' => array_values(array_unique(array_merge($allViews, $helpdeskAgent))),
        // "Nur lesen" ist der Altbestand der frueheren Vorgaberolle und oeffnet bewusst keine
        // Assetverwaltung: ohne ausdrueckliche KLINQ-Berechtigung bleibt nur das Serviceportal
        // mit den eigenen Tickets. Lesenden Assetzugriff gibt es nur mit einer Rolle, die Assets
        // einschliesst (Assetmanagement, Lager, Einkauf, Help Desk).
        'readonly' => $portal,
        'portal' => $portal,
    ],
    'role_labels' => [
        'admin' => 'Administrator',
        'assetmanagement' => 'Assetmanagement',
        'lager' => 'Lager',
        'einkauf' => 'Einkauf',
        'helpdesk_admin' => 'Help Desk Administrator',
        'helpdesk_lead' => 'Help Desk Leitung',
        'helpdesk_agent' => 'Help Desk Agent',
        'readonly' => 'Nur lesen',
        'portal' => 'Serviceportal',
    ],
    // Berechtigungsgruppen: zusätzlich zur Rolle zuweisbare Rechte, einem Benutzer können
    // mehrere Gruppen zugeordnet werden. "Statistik" gewährt Zugriff auf die
    // Help-Desk-Ticket-Berichte (helpdesk.reports); "ticketaufruf" erlaubt das Auswerten der
    // Ticketaufrufe (helpdesk.view_history). "ticket_loeschen" und "ticket_stornieren" geben
    // das Löschen bzw. Stornieren von Tickets frei, "aufgabenverteilung" das Zuweisen von
    // Aufgaben an einen bestimmten Agenten. Admins haben diese Rechte ohnehin über
    // ihre Rolle; alle anderen Rollen benötigen die jeweilige Gruppe.
    'groups' => [
        'statistik' => ['helpdesk.reports'],
        'ticketaufruf' => ['helpdesk.view_history'],
        'ticket_loeschen' => ['helpdesk.delete'],
        'ticket_stornieren' => ['helpdesk.cancel'],
        'aufgabenverteilung' => ['helpdesk.tasks.assign'],
    ],
    'group_labels' => [
        'statistik' => 'Statistik',
        'ticketaufruf' => 'Darf Ticketaufruf auswerten',
        'ticket_loeschen' => 'Darf Tickets löschen',
        'ticket_stornieren' => 'Darf Tickets stornieren',
        'aufgabenverteilung' => 'Darf Aufgaben zuweisen',
    ],
];
