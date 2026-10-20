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
    'helpdesk.reports', 'helpdesk.export', 'helpdesk.view_history',
    'helpdesk.categories', 'helpdesk.templates', 'helpdesk.admin',
    'knowledgebase.view', 'knowledgebase.manage',
];

// Alle Leserechte – außer dem Audit-Log (enthält Benutzer- und IP-Daten; nur Admin und Assetmanagement)
// und dem Help-Desk-Agentenbereich (fremde Tickets sehen nur Help-Desk-Rollen)
$readOnly = array_values(array_filter($all, static fn (string $p): bool => str_ends_with($p, '.view') && $p !== 'audit.view' && $p !== 'helpdesk.view'));

// Rechte des Help-Desk-Agenten (Tickets bearbeiten, ohne Stammdaten/SLA-Pflege)
// helpdesk.reports (Ticket-Berichte) ist bewusst nicht enthalten: das Recht wird nur über
// die Berechtigungsgruppe "Statistik" vergeben (siehe $groups unten), ausgenommen Admins.
$helpdeskAgent = [
    'portal.view', 'portal.create',
    'helpdesk.view', 'helpdesk.create', 'helpdesk.update', 'helpdesk.assign',
    'helpdesk.comment', 'helpdesk.internal_note', 'helpdesk.close', 'helpdesk.reopen',
    'helpdesk.merge', 'helpdesk.worklog', 'helpdesk.delete',
    'knowledgebase.view', 'knowledgebase.manage',
];
$helpdeskLead = array_merge($helpdeskAgent, [
    'helpdesk.escalate', 'helpdesk.sla', 'helpdesk.export', 'helpdesk.categories', 'helpdesk.templates',
]);
$helpdeskAdmin = array_merge($helpdeskLead, ['helpdesk.admin', 'documents.manage']);
// Alle Mitarbeiter dürfen im Portal eigene Tickets erstellen und Wissensartikel lesen
$portal = ['portal.view', 'portal.create', 'knowledgebase.view'];

return [
    'all' => $all,
    'roles' => [
        'admin' => $all,
        'assetmanagement' => array_values(array_unique(array_merge($readOnly, $portal, [
            'assets.manage', 'assets.retire', 'labels.print',
            'movements.checkout', 'movements.return', 'movements.complete',
            'handover.manage', 'handover.sign',
            'employees.manage', 'employees.sync',
            'locations.manage', 'costcenters.manage',
            'manufacturers.manage', 'articles.manage',
            'documents.manage', 'licenses.manage',
            'reports.export', 'imports.manage', 'audit.view',
        ]))),
        'lager' => array_values(array_unique(array_merge($readOnly, $portal, [
            'labels.print',
            'movements.checkout', 'movements.return', 'movements.complete',
            'handover.manage', 'handover.sign',
            'orders.receive', 'assets.manage', 'documents.manage',
        ]))),
        'einkauf' => array_values(array_unique(array_merge($readOnly, $portal, [
            'suppliers.manage', 'orders.manage', 'orders.receive',
            'manufacturers.manage', 'articles.manage', 'documents.manage',
            'licenses.manage', 'reports.export',
        ]))),
        'helpdesk_admin' => array_values(array_unique(array_merge($readOnly, $helpdeskAdmin))),
        'helpdesk_lead' => array_values(array_unique(array_merge($readOnly, $helpdeskLead))),
        'helpdesk_agent' => array_values(array_unique(array_merge($readOnly, $helpdeskAgent))),
        'readonly' => $readOnly,
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
    ],
    // Berechtigungsgruppen: zusätzlich zur Rolle zuweisbare Rechte, einem Benutzer können
    // mehrere Gruppen zugeordnet werden. "Statistik" gewährt Zugriff auf die
    // Help-Desk-Ticket-Berichte (helpdesk.reports); "ticketaufruf" erlaubt das Auswerten der
    // Ticketaufrufe (helpdesk.view_history). Admins haben diese Rechte ohnehin über ihre Rolle.
    'groups' => [
        'statistik' => ['helpdesk.reports'],
        'ticketaufruf' => ['helpdesk.view_history'],
    ],
    'group_labels' => [
        'statistik' => 'Statistik',
        'ticketaufruf' => 'Darf Ticketaufruf auswerten',
    ],
];
