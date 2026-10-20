<?php

declare(strict_types=1);

/**
 * Demo-/Beispieldaten für Screenshots und Dokumentation.
 * Nur für lokale Entwicklungs-/Doku-Umgebungen bestimmt – NICHT produktiv verwenden.
 *
 * Voraussetzung: frisch migrierte Datenbank (php bin/migrate.php).
 * Aufruf im Container:  php bin/seed-demo.php
 */

require __DIR__ . '/../bootstrap/autoload.php';

use App\Core\Config;
use App\Core\Database;
use App\Core\Env;
use App\Security\PasswordHasher;

$basePath = dirname(__DIR__);
Env::load($basePath . '/.env');
$config = new Config($basePath . '/config');
$pdo = Database::connect($config);

$hash = static fn (string $pw): string => (new PasswordHasher())->hash($pw);

/** Führt ein Prepared Statement aus und liefert die letzte Insert-ID. */
function ins(PDO $pdo, string $sql, array $params = []): int
{
    $st = $pdo->prepare($sql);
    $st->execute($params);

    return (int) $pdo->lastInsertId();
}

/** Liefert einen einzelnen Wert (z. B. id). */
function val(PDO $pdo, string $sql, array $params = []): mixed
{
    $st = $pdo->prepare($sql);
    $st->execute($params);
    $v = $st->fetchColumn();

    return $v === false ? null : $v;
}

// Idempotenz-Guard: abbrechen, wenn bereits Demo-Daten vorhanden sind.
if ((int) val($pdo, "SELECT COUNT(*) FROM employees WHERE personnel_number LIKE 'D-%'") > 0) {
    fwrite(STDOUT, "Demo-Daten bereits vorhanden – überspringe.\n");
    exit(0);
}

$pdo->beginTransaction();

try {
    // -------------------------------------------------------------------------
    // 1. Standorte
    // -------------------------------------------------------------------------
    $loc = static function (string $name, string $type, ?int $parentId, ?string $code, string $fullPath, int $depth, int $sort = 0) use ($pdo): int {
        return ins(
            $pdo,
            'INSERT INTO locations (parent_id, name, type, code, full_path, depth, is_active, sort_order) VALUES (?, ?, ?, ?, ?, ?, 1, ?)',
            [$parentId, $name, $type, $code, $fullPath, $depth, $sort]
        );
    };

    $z = $loc('Zentrale', 'site', null, 'Z', 'Zentrale', 0, 10);
    $n = $loc('Außenstelle Nord', 'site', null, 'N', 'Außenstelle Nord', 0, 20);
    $lager = $loc('Zentrallager', 'warehouse', null, 'LAGER', 'Zentrallager', 0, 30);

    $a = $loc('Gebäude A', 'building', $z, 'A', 'Zentrale / Gebäude A', 1, 10);
    $b = $loc('Gebäude B', 'building', $z, 'B', 'Zentrale / Gebäude B', 1, 20);
    $n1 = $loc('Gebäude N1', 'building', $n, 'N1', 'Außenstelle Nord / Gebäude N1', 1, 10);

    $aEg = $loc('Erdgeschoss', 'floor', $a, 'A-EG', 'Zentrale / Gebäude A / Erdgeschoss', 2, 10);
    $aOg = $loc('1. Obergeschoss', 'floor', $a, 'A-OG', 'Zentrale / Gebäude A / 1. Obergeschoss', 2, 20);
    $bEg = $loc('Erdgeschoss', 'floor', $b, 'B-EG', 'Zentrale / Gebäude B / Erdgeschoss', 2, 10);

    $raumA012 = $loc('Raum A-012', 'room', $aEg, 'A-012', 'Zentrale / Gebäude A / Erdgeschoss / Raum A-012', 3, 10);
    $raumA015 = $loc('Raum A-015', 'room', $aEg, 'A-015', 'Zentrale / Gebäude A / Erdgeschoss / Raum A-015', 3, 20);
    $raumA110 = $loc('Raum A-110', 'room', $aOg, 'A-110', 'Zentrale / Gebäude A / 1. Obergeschoss / Raum A-110', 3, 10);
    $raumA115 = $loc('Raum A-115', 'room', $aOg, 'A-115', 'Zentrale / Gebäude A / 1. Obergeschoss / Raum A-115', 3, 20);
    $raumB005 = $loc('Raum B-005', 'room', $bEg, 'B-005', 'Zentrale / Gebäude B / Erdgeschoss / Raum B-005', 3, 10);
    $raumN1 = $loc('Raum N1-001', 'room', $n1, 'N1-001', 'Außenstelle Nord / Gebäude N1 / Raum N1-001', 3, 10);

    // -------------------------------------------------------------------------
    // 2. Kostenstellen
    // -------------------------------------------------------------------------
    $cc = static function (string $number, string $description, ?int $locationId) use ($pdo): int {
        return ins(
            $pdo,
            'INSERT INTO cost_centers (number, description, location_id, is_active) VALUES (?, ?, ?, 1)',
            [$number, $description, $locationId]
        );
    };
    $ccIT = $cc('10010', 'IT-Service', $raumA110);
    $ccFin = $cc('20020', 'Finanzen & Controlling', $raumB005);
    $ccVt = $cc('30030', 'Vertrieb', $raumA015);
    $ccPers = $cc('40040', 'Personalwesen', $raumA115);
    $ccGf = $cc('50050', 'Geschäftsführung', $raumA110);

    // -------------------------------------------------------------------------
    // 3. Hersteller
    // -------------------------------------------------------------------------
    $mfr = static function (string $name) use ($pdo): int {
        return ins($pdo, 'INSERT INTO manufacturers (name, is_active) VALUES (?, 1)', [$name]);
    };
    $mDell = $mfr('Dell');
    $mLenovo = $mfr('Lenovo');
    $mApple = $mfr('Apple');
    $mHP = $mfr('HP');
    $mSamsung = $mfr('Samsung');
    $mCisco = $mfr('Cisco');
    $mMicrosoft = $mfr('Microsoft');
    $mLogitech = $mfr('Logitech');
    $mEpson = $mfr('Epson');
    $mFujitsu = $mfr('Fujitsu');

    // -------------------------------------------------------------------------
    // 4. Asset-Typen / Kategorien (IDs aus Seeder ermitteln)
    // -------------------------------------------------------------------------
    $typePc = (int) val($pdo, "SELECT id FROM asset_types WHERE code = 'PC'");
    $typeMd = (int) val($pdo, "SELECT id FROM asset_types WHERE code = 'MD'");
    $typeNet = (int) val($pdo, "SELECT id FROM asset_types WHERE code = 'NET'");
    $typeZub = (int) val($pdo, "SELECT id FROM asset_types WHERE code = 'ZUB'");

    $cat = static function (int $typeId, string $name) use ($pdo): int {
        return (int) val($pdo, 'SELECT id FROM asset_categories WHERE asset_type_id = ? AND name = ?', [$typeId, $name]);
    };
    $catDesktop = $cat($typePc, 'Desktop');
    $catNotebook = $cat($typePc, 'Notebook');
    $catWorkstation = $cat($typePc, 'Workstation');
    $catSmartphone = $cat($typeMd, 'Smartphone');
    $catTablet = $cat($typeMd, 'Tablet');
    $catSwitch = $cat($typeNet, 'Switch');
    $catAp = $cat($typeNet, 'Access Point');
    $catMonitor = $cat($typeZub, 'Monitor');
    $catDock = $cat($typeZub, 'Dockingstation');
    $catDrucker = $cat($typeZub, 'Drucker');
    $catHeadset = $cat($typeZub, 'Headset');
    $catTastatur = $cat($typeZub, 'Tastatur');
    $catMaus = $cat($typeZub, 'Maus');

    // -------------------------------------------------------------------------
    // 5. Artikel
    // -------------------------------------------------------------------------
    $art = static function (int $mfrId, int $typeId, ?int $catId, string $name, ?string $number, ?string $desc, bool $handover, bool $consumable = false, ?int $min = null, int $stock = 0) use ($pdo): int {
        return ins(
            $pdo,
            'INSERT INTO articles (manufacturer_id, asset_type_id, asset_category_id, name, normalized_name, phonetic_key, article_number, description, is_handover_relevant, is_consumable, minimum_stock, stock_quantity, is_active)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1)',
            [$mfrId, $typeId, $catId, $name, '', '', $number, $desc, $handover ? 1 : 0, $consumable ? 1 : 0, $min, $stock]
        );
    };

    $artOpti = $art($mDell, $typePc, $catDesktop, 'OptiPlex 7010 SFF', '210-BFSD', 'Desktop-PC, Intel Core i5', false);
    $artThinkC = $art($mLenovo, $typePc, $catDesktop, 'ThinkCentre M90q Tiny', '11T30004GE', 'Mini-PC', false);
    $artLat = $art($mDell, $typePc, $catNotebook, 'Latitude 5440', '5440-133', 'Business-Notebook 14 Zoll', true);
    $artThinkT = $art($mLenovo, $typePc, $catNotebook, 'ThinkPad T14 Gen 4', '21HD0043GE', 'Business-Notebook', true);
    $artMacbook = $art($mApple, $typePc, $catNotebook, 'MacBook Pro 14 Zoll', 'MRX33D/A', 'M3 Pro, 18 GB RAM', true);
    $artElitebook = $art($mHP, $typePc, $catNotebook, 'EliteBook 840 G10', '8F3J4EA', 'Business-Notebook', true);
    $artPrec = $art($mDell, $typePc, $catWorkstation, 'Precision 5860', '5860-100', 'CAD-Workstation', false);
    $artIphone = $art($mApple, $typeMd, $catSmartphone, 'iPhone 15', 'A2846', 'Smartphone', true);
    $artGalaxy = $art($mSamsung, $typeMd, $catSmartphone, 'Galaxy S24', 'SM-S921B', 'Smartphone', true);
    $artIpad = $art($mApple, $typeMd, $catTablet, 'iPad Air 11 Zoll', 'MUWC3FD/A', 'Tablet', false);
    $artSwitch = $art($mCisco, $typeNet, $catSwitch, 'Catalyst 9300-48', 'C9300-48P-E', '48-Port PoE+ Switch', false);
    $artAp = $art($mCisco, $typeNet, $catAp, 'Catalyst 9130AXI', 'C9130AXI-E', 'Wi-Fi 6 Access Point', false);
    $artMonitor = $art($mDell, $typeZub, $catMonitor, 'UltraSharp U2723QE', '2723QE', '27 Zoll 4K Monitor', false);
    $artDock = $art($mDell, $typeZub, $catDock, 'WD22TB4 Dockingstation', 'WD22TB4', 'Thunderbolt 4 Dock', true);
    $artDrucker = $art($mHP, $typeZub, $catDrucker, 'LaserJet Pro 4003dn', '2Z629A', 'Netzwerkdrucker', false);
    $artHeadset = $art($mLogitech, $typeZub, $catHeadset, 'Zone Wireless 2', '981-001267', 'Headset', false);
    $artTastatur = $art($mLogitech, $typeZub, $catTastatur, 'MX Keys S', '920-011415', 'Tastatur', false, true, 5, 8);
    $artMaus = $art($mLogitech, $typeZub, $catMaus, 'MX Master 3S', '910-006556', 'Maus', false, true, 5, 6);
    $artKabel = $art($mDell, $typeZub, null, 'USB-C auf DisplayPort Kabel', '470-AEFB', 'Verbrauchsmaterial', false, true, 10, 12);

    // -------------------------------------------------------------------------
    // 6. Mitarbeiter
    // -------------------------------------------------------------------------
    $emp = static function (string $first, string $last, string $personnel, ?string $dept, ?string $pos, ?string $email, ?string $phone, ?int $locId, ?int $ccId) use ($pdo): int {
        $display = "$first $last";

        return ins(
            $pdo,
            "INSERT INTO employees (first_name, last_name, display_name, email, personnel_number, department, position, phone, location_id, cost_center_id, source, is_active)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'manual', 1)",
            [$first, $last, $display, $email, $personnel, $dept, $pos, $phone, $locId, $ccId]
        );
    };

    $empMax = $emp('Max', 'Mustermann', 'D-1001', 'IT-Service', 'Systemadministrator', 'max.mustermann@musterfirma.de', '+49 30 1000-101', $raumA110, $ccIT);
    $empErika = $emp('Erika', 'Mustermann', 'D-1002', 'Geschäftsführung', 'Geschäftsführerin', 'erika.mustermann@musterfirma.de', '+49 30 1000-200', $raumA110, $ccGf);
    $empAnna = $emp('Anna', 'Schmidt', 'D-1003', 'Vertrieb', 'Vertriebsleiterin', 'anna.schmidt@musterfirma.de', '+49 30 1000-301', $raumA015, $ccVt);
    $empJonas = $emp('Jonas', 'Becker', 'D-1004', 'Finanzen', 'Controller', 'jonas.becker@musterfirma.de', '+49 30 1000-401', $raumB005, $ccFin);
    $empLaura = $emp('Laura', 'Hoffmann', 'D-1005', 'Personalwesen', 'Personalreferentin', 'laura.hoffmann@musterfirma.de', '+49 30 1000-501', $raumA115, $ccPers);
    $empDavid = $emp('David', 'Wagner', 'D-1006', 'IT-Service', 'IT-Support', 'david.wagner@musterfirma.de', '+49 30 1000-102', $raumA012, $ccIT);
    $empSarah = $emp('Sarah', 'Braun', 'D-1007', 'Vertrieb', 'Key Account Managerin', 'sarah.braun@musterfirma.de', '+49 30 1000-302', $raumA015, $ccVt);
    $empMelanie = $emp('Melanie', 'Fischer', 'D-1008', 'IT-Service', 'Service Desk Agentin', 'melanie.fischer@musterfirma.de', '+49 30 1000-103', $raumA012, $ccIT);
    $empThomas = $emp('Thomas', 'Berger', 'D-1009', 'IT-Service', 'Help Desk Leitung', 'thomas.berger@musterfirma.de', '+49 30 1000-104', $raumA110, $ccIT);
    $empSabine = $emp('Sabine', 'Wolf', 'D-1010', 'IT-Service', 'Help Desk Administratorin', 'sabine.wolf@musterfirma.de', '+49 30 1000-105', $raumA110, $ccIT);
    $empPeter = $emp('Peter', 'Krüger', 'D-1011', 'Lager', 'Lagermitarbeiter', 'peter.krueger@musterfirma.de', '+49 30 1000-601', $lager, $ccIT);
    $empClaudia = $emp('Claudia', 'Lang', 'D-1012', 'Einkauf', 'Einkäuferin', 'claudia.lang@musterfirma.de', '+49 30 1000-701', $raumB005, $ccFin);

    // -------------------------------------------------------------------------
    // 7. Benutzer
    // -------------------------------------------------------------------------
    $roleId = static function (string $name) use ($pdo): int {
        return (int) val($pdo, 'SELECT id FROM roles WHERE name = ?', [$name]);
    };

    $pw = 'Demo12345!';
    $user = static function (string $username, string $display, int $roleId, ?int $employeeId, bool $canSign = false) use ($pdo, $hash, $pw): int {
        return ins(
            $pdo,
            "INSERT INTO users (username, display_name, email, password_hash, role_id, auth_source, employee_id, is_active, can_sign_electronically)
             VALUES (?, ?, ?, ?, ?, 'local', ?, 1, ?)",
            [$username, $display, $display . '@musterfirma.de', $hash($pw), $roleId, $employeeId, $canSign ? 1 : 0]
        );
    };

    // Rolle nachschlagen
    $roleAdmin = $roleId('admin');
    $roleAm = $roleId('assetmanagement');
    $roleLager = $roleId('lager');
    $roleEinkauf = $roleId('einkauf');
    $roleReadonly = $roleId('readonly');
    $roleHdAdmin = $roleId('helpdesk_admin');
    $roleHdLead = $roleId('helpdesk_lead');
    $roleHdAgent = $roleId('helpdesk_agent');

    $adminId = (int) val($pdo, "SELECT id FROM users WHERE username = 'admin'");
    // Admin mit Mitarbeiter verknüpfen
    $pdo->prepare('UPDATE users SET display_name = ?, email = ?, employee_id = ? WHERE id = ?')
        ->execute(['Max Mustermann', 'admin@musterfirma.de', $empMax, $adminId]);

    $uAgent = $user('m.fischer', 'Melanie Fischer', $roleHdAgent, $empMelanie, true);
    $uLead = $user('t.berger', 'Thomas Berger', $roleHdLead, $empThomas, true);
    $uHdAdmin = $user('s.wolf', 'Sabine Wolf', $roleHdAdmin, $empSabine, true);
    $uLager = $user('p.krueger', 'Peter Krüger', $roleLager, $empPeter, true);
    $uEinkauf = $user('c.lang', 'Claudia Lang', $roleEinkauf, $empClaudia);
    $uAm = $user('d.wagner', 'David Wagner', $roleAm, $empDavid, true);
    $uRead = $user('e.mustermann', 'Erika Mustermann', $roleReadonly, $empErika);

    // Statistik-Gruppe für Help-Desk-Leitung
    $statGroup = (int) val($pdo, "SELECT id FROM permission_groups WHERE name = 'statistik'");
    if ($statGroup > 0) {
        $pdo->prepare('INSERT IGNORE INTO user_permission_groups (user_id, group_id) VALUES (?, ?)')
            ->execute([$uLead, $statGroup]);
    }

    // -------------------------------------------------------------------------
    // 8. Lieferanten
    // -------------------------------------------------------------------------
    $sup = static function (string $name, string $contact, string $email, string $phone) use ($pdo): int {
        return ins(
            $pdo,
            'INSERT INTO suppliers (name, contact_person, email, phone, is_active) VALUES (?, ?, ?, ?, 1)',
            [$name, $contact, $email, $phone]
        );
    };
    $supDell = $sup('Dell GmbH', 'Vertrieb Dell', 'vertrieb@dell.de', '+49 6196 47000');
    $supIngram = $sup('Ingram Micro GmbH', 'Ansprechpartner', 'service@ingrammicro.de', '+49 89 42080');
    $supBechtle = $sup('Bechtle AG', 'Vertrieb', 'it@bechtle.de', '+49 7132 9810');

    // -------------------------------------------------------------------------
    // 9. Bestellungen + Wareneingänge
    // -------------------------------------------------------------------------
    $statusId = static function (string $code) use ($pdo): int {
        return (int) val($pdo, 'SELECT id FROM asset_statuses WHERE code = ?', [$code]);
    };
    $stInStock = $statusId('in_stock');
    $stIssued = $statusId('issued');
    $stReturnExpected = $statusId('return_expected');
    $stDefective = $statusId('defective');
    $stRepair = $statusId('repair');
    $stRetired = $statusId('retired');

    $po = static function (string $number, int $supplierId, string $status, string $orderDate, ?string $expected, ?int $ccId, string $note = '') use ($pdo, $uEinkauf): int {
        return ins(
            $pdo,
            'INSERT INTO purchase_orders (order_number, supplier_id, order_date, ordered_by_user_id, ordered_by_name, status, expected_delivery_date, cost_center_id, note, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$number, $supplierId, $orderDate, $uEinkauf, 'Claudia Lang', $status, $expected, $ccId, $note, $uEinkauf]
        );
    };
    $poItem = static function (int $poId, int $pos, ?int $articleId, ?int $typeId, string $desc, int $qty, int $received, ?string $price, bool $createsAssets, string $note = '') use ($pdo): int {
        return ins(
            $pdo,
            'INSERT INTO purchase_order_items (purchase_order_id, position, article_id, asset_type_id, description, quantity, quantity_received, unit_price, creates_assets, note)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$poId, $pos, $articleId, $typeId, $desc, $qty, $received, $price, $createsAssets ? 1 : 0, $note]
        );
    };

    $po1 = $po('BEST-2024-0001', $supDell, 'delivered', '2024-01-10', '2024-01-20', $ccIT, 'Notebooks für Vertrieb');
    $po1i1 = $poItem($po1, 1, $artLat, $typePc, 'Dell Latitude 5440', 3, 3, '1249.00', true);
    $po1i2 = $poItem($po1, 2, $artDock, $typeZub, 'WD22TB4 Dockingstation', 3, 3, '199.00', true);

    $po2 = $po('BEST-2024-0002', $supIngram, 'partially_delivered', '2024-02-15', '2024-03-01', $ccIT, 'Smartphones Vertrieb & GF');
    $po2i1 = $poItem($po2, 1, $artIphone, $typeMd, 'Apple iPhone 15', 5, 3, '949.00', true);

    $po3 = $po('BEST-2024-0003', $supBechtle, 'ordered', '2024-09-10', '2024-09-25', $ccIT, 'Neue Monitore');
    $po3i1 = $poItem($po3, 1, $artMonitor, $typeZub, 'Dell UltraSharp U2723QE', 6, 0, '559.00', true);

    $po4 = $po('BEST-2024-0004', $supIngram, 'draft', '2024-09-18', null, $ccIT, 'Entwurf – Tastaturen & Mäuse');
    $po4i1 = $poItem($po4, 1, $artTastatur, $typeZub, 'Logitech MX Keys S', 4, 0, '109.00', false);
    $po4i2 = $poItem($po4, 2, $artMaus, $typeZub, 'Logitech MX Master 3S', 4, 0, '99.00', false);

    // -------------------------------------------------------------------------
    // 10. Assets
    // -------------------------------------------------------------------------
    $asset = static function (
        string $inv,
        int $typeId,
        ?int $catId,
        int $mfrId,
        ?int $articleId,
        string $serial,
        ?string $mac,
        ?string $imei,
        string $purchaseDate,
        ?float $price,
        ?string $warranty,
        ?int $locId,
        ?int $ccId,
        ?int $empId,
        int $statusId,
        ?int $poId = null,
        ?int $poItemId = null,
        ?string $name = null,
        ?string $note = null
    ) use ($pdo): int {
        return ins(
            $pdo,
            'INSERT INTO assets (inventory_number, asset_type_id, asset_category_id, manufacturer_id, article_id, serial_number, serial_number_normalized, mac_address, imei, purchase_date, purchase_price, warranty_until, location_id, cost_center_id, employee_id, status_id, purchase_order_id, purchase_order_item_id, name, note, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$inv, $typeId, $catId, $mfrId, $articleId, $serial, strtoupper(trim($serial)), $mac, $imei, $purchaseDate, $price, $warranty, $locId, $ccId, $empId, $statusId, $poId, $poItemId, $name, $note, $adminId]
        );
    };

    // Notebooks (ausgegeben)
    $a1 = $asset('PC24001', $typePc, $catNotebook, $mDell, $artLat, 'DL-5440-001', 'A0:1B:2C:3D:4E:01', null, '2024-01-20', 1249.00, '2027-01-20', $raumA015, $ccVt, $empAnna, $stIssued, $po1, $po1i1, 'Notebook Anna Schmidt');
    $a2 = $asset('PC24002', $typePc, $catNotebook, $mDell, $artLat, 'DL-5440-002', 'A0:1B:2C:3D:4E:02', null, '2024-01-20', 1249.00, '2027-01-20', $raumA015, $ccVt, $empSarah, $stIssued, $po1, $po1i1, 'Notebook Sarah Braun');
    $a3 = $asset('PC24003', $typePc, $catNotebook, $mLenovo, $artThinkT, 'TP-T14-001', 'A0:1B:2C:3D:4E:03', null, '2024-03-05', 1199.00, '2027-03-05', $raumB005, $ccFin, $empJonas, $stIssued, null, null, 'Notebook Jonas Becker');
    $a4 = $asset('PC24004', $typePc, $catNotebook, $mApple, $artMacbook, 'MBP-14-001', 'A0:1B:2C:3D:4E:04', null, '2024-03-10', 2399.00, '2027-03-10', $raumA110, $ccGf, $empErika, $stIssued, null, null, 'MacBook Erika Mustermann');
    $a5 = $asset('PC24005', $typePc, $catNotebook, $mHP, $artElitebook, 'EB-840-001', 'A0:1B:2C:3D:4E:05', null, '2024-04-02', 1299.00, '2027-04-02', $raumA115, $ccPers, $empLaura, $stIssued, null, null, 'Notebook Laura Hoffmann');

    // Rückgabe erwartet
    $a6 = $asset('PC24006', $typePc, $catNotebook, $mDell, $artLat, 'DL-5440-006', 'A0:1B:2C:3D:4E:06', null, '2024-02-01', 1249.00, '2027-02-01', $raumA015, $ccVt, $empAnna, $stReturnExpected, null, null, 'Notebook (alte Version)');

    // Desktop / Workstation
    $a7 = $asset('PC24007', $typePc, $catDesktop, $mDell, $artOpti, 'OP-7010-001', 'A0:1B:2C:3D:4E:07', null, '2024-05-10', 899.00, '2027-05-10', $raumA012, $ccIT, null, $stInStock, null, null, 'Desktop IT-Büro');
    $a8 = $asset('PC24008', $typePc, $catWorkstation, $mDell, $artPrec, 'PR-5860-001', 'A0:1B:2C:3D:4E:08', null, '2024-06-01', 3599.00, '2027-06-01', $raumA110, $ccIT, $empMax, $stIssued, null, null, 'CAD-Workstation');

    // Mobilgeräte
    $a9 = $asset('MD24001', $typeMd, $catSmartphone, $mApple, $artIphone, 'IP15-0001', null, '356789012345001', '2024-02-15', 949.00, '2026-02-15', $raumA110, $ccGf, $empErika, $stIssued, $po2, $po2i1, 'iPhone Erika Mustermann');
    $a10 = $asset('MD24002', $typeMd, $catSmartphone, $mSamsung, $artGalaxy, 'S24-0001', null, '356789012345002', '2024-02-15', 799.00, '2026-02-15', $raumA015, $ccVt, $empAnna, $stIssued, $po2, $po2i1, 'Galaxy Anna Schmidt');
    $a11 = $asset('MD24003', $typeMd, $catSmartphone, $mApple, $artIphone, 'IP15-0003', null, '356789012345003', '2024-02-15', 949.00, '2026-02-15', $lager, $ccIT, null, $stInStock, $po2, $po2i1, 'iPhone (Lager)');
    $a12 = $asset('MD24004', $typeMd, $catTablet, $mApple, $artIpad, 'IPA-11-001', null, '356789012345004', '2024-05-20', 799.00, '2026-05-20', $lager, $ccIT, null, $stInStock, null, null, 'iPad Air (Lager)');

    // Netzwerk
    $a13 = $asset('NET24001', $typeNet, $catSwitch, $mCisco, $artSwitch, 'FCW-9300-001', 'A0:1B:2C:3D:4E:13', null, '2024-01-05', 6899.00, '2029-01-05', $lager, $ccIT, null, $stInStock, null, null, 'Core-Switch');
    $a14 = $asset('NET24002', $typeNet, $catAp, $mCisco, $artAp, 'AP-9130-001', 'A0:1B:2C:3D:4E:14', null, '2024-01-05', 1199.00, '2029-01-05', $raumA012, $ccIT, null, $stInStock, null, null, 'Access Point EG');

    // Zubehör
    $a15 = $asset('ZUB24001', $typeZub, $catMonitor, $mDell, $artMonitor, 'U2723QE-001', null, null, '2024-01-20', 559.00, '2027-01-20', $raumA015, $ccVt, $empAnna, $stIssued, $po1, $po1i2, 'Monitor Anna');
    $a16 = $asset('ZUB24002', $typeZub, $catDock, $mDell, $artDock, 'WD22TB4-001', null, null, '2024-01-20', 199.00, '2027-01-20', $raumA015, $ccVt, $empAnna, $stIssued, $po1, $po1i2, 'Dock Anna');
    $a17 = $asset('ZUB24003', $typeZub, $catDrucker, $mHP, $artDrucker, 'HP4003-001', null, null, '2024-07-01', 699.00, '2027-07-01', $raumA012, $ccIT, null, $stInStock, null, null, 'Etagen-Drucker EG');
    $a18 = $asset('ZUB24004', $typeZub, $catHeadset, $mLogitech, $artHeadset, 'ZW2-001', null, null, '2024-08-01', 199.00, '2027-08-01', $lager, $ccIT, null, $stInStock, null, null, 'Headset (Lager)');

    // Defekt / Reparatur / Ausgemustert
    $a19 = $asset('PC23010', $typePc, $catNotebook, $mDell, $artLat, 'DL-5440-099', 'A0:1B:2C:3D:4E:19', null, '2023-06-01', 1199.00, '2026-06-01', $lager, $ccIT, null, $stDefective, null, null, 'Defektes Notebook', 'Display defekt – zur Reparatur');
    $a20 = $asset('PC23011', $typePc, $catDesktop, $mLenovo, $artThinkC, 'TC-90Q-099', 'A0:1B:2C:3D:4E:20', null, '2023-05-01', 799.00, '2026-05-01', $lager, $ccIT, null, $stRepair, null, null, 'Mini-PC in Reparatur');
    $a21 = $asset('PC22001', $typePc, $catNotebook, $mFujitsu, null, 'FJ-OLD-001', 'A0:1B:2C:3D:4E:21', null, '2022-01-01', 999.00, '2025-01-01', $lager, $ccIT, null, $stRetired, null, null, 'Altes Notebook', 'Ausgemustert');

    // -------------------------------------------------------------------------
    // 11. Bewegungen
    // -------------------------------------------------------------------------
    $mv = static function (string $type, string $status, int $assetId, ?int $empId, ?int $fromLoc, ?int $toLoc, ?int $ccId, string $date, ?string $cond, ?int $createdBy, ?string $note = null) use ($pdo): int {
        return ins(
            $pdo,
            "INSERT INTO movements (type, status, asset_id, employee_id, from_location_id, to_location_id, cost_center_id, movement_date, condition_code, has_damage, accessories_checked, target_status_code, note, source, created_by, created_by_name, completed_by, completed_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 0, 1, NULL, ?, 'web', ?, 'Max Mustermann', ?, NOW())",
            [$type, $status, $assetId, $empId, $fromLoc, $toLoc, $ccId, $date, $cond, $note, $createdBy, $createdBy]
        );
    };

    $mv('checkout', 'completed', $a1, $empAnna, $lager, $raumA015, $ccVt, '2024-01-22', 'ok', $adminId, 'Ausgabe Notebook an Anna Schmidt');
    $mv('checkout', 'completed', $a2, $empSarah, $lager, $raumA015, $ccVt, '2024-01-22', 'ok', $adminId, 'Ausgabe Notebook an Sarah Braun');
    $mv('checkout', 'completed', $a9, $empErika, $lager, $raumA110, $ccGf, '2024-02-16', 'ok', $adminId, 'Ausgabe iPhone');
    $mv('checkout', 'completed', $a10, $empAnna, $lager, $raumA015, $ccVt, '2024-02-16', 'ok', $adminId, 'Ausgabe Galaxy');
    $mv('checkout', 'open', $a12, $empDavid, $lager, $raumA012, $ccIT, '2024-09-17', 'ok', $adminId, 'Offene Ausgabe – wartet auf Unterschrift');

    // -------------------------------------------------------------------------
    // 12. Lizenzen
    // -------------------------------------------------------------------------
    $lic = static function (int $mfrId, string $product, string $type, ?string $key, int $qty, ?string $expires, ?float $cost, ?int $ccId) use ($pdo): int {
        return ins(
            $pdo,
            'INSERT INTO licenses (manufacturer_id, product, license_type, license_key, quantity, purchase_date, expires_at, cost, cost_center_id, is_active)
             VALUES (?, ?, ?, ?, ?, CURDATE(), ?, ?, ?, 1)',
            [$mfrId, $product, $type, $key, $qty, $expires, $cost, $ccId]
        );
    };

    $lic1 = $lic($mMicrosoft, 'Microsoft 365 Business Premium', 'Abonnement', 'M365-XXXX-XXXX-XXXX', 25, '2025-09-30', 254.00, $ccIT);
    $lic2 = $lic($mMicrosoft, 'Windows 11 Pro', 'Volumenlizenz', 'W11P-XXXX-XXXX-XXXX', 50, null, 139.00, $ccIT);
    $lic3 = $lic($mApple, 'macOS', 'OEM', null, 5, null, null, $ccGf);
    $lic4 = $lic($mCisco, 'Cisco DNA Essentials', 'Abonnement', 'DNA-XXXX-XXXX', 2, '2026-01-05', 499.00, $ccIT);

    // Lizenzzuweisungen
    $pdo->prepare('INSERT INTO license_assignments (license_id, asset_id, assigned_at, assigned_by) VALUES (?, ?, NOW(), ?)')
        ->execute([$lic1, $a1, 'Max Mustermann']);
    $pdo->prepare('INSERT INTO license_assignments (license_id, asset_id, assigned_at, assigned_by) VALUES (?, ?, NOW(), ?)')
        ->execute([$lic2, $a7, 'Max Mustermann']);
    $pdo->prepare('INSERT INTO license_assignments (license_id, asset_id, assigned_at, assigned_by) VALUES (?, ?, NOW(), ?)')
        ->execute([$lic3, $a4, 'Max Mustermann']);

    // -------------------------------------------------------------------------
    // 13. Help Desk – Tickets
    // -------------------------------------------------------------------------
    $tt = static function (string $code) use ($pdo): int {
        return (int) val($pdo, 'SELECT id FROM ticket_types WHERE code = ?', [$code]);
    };
    $ts = static function (string $code) use ($pdo): int {
        return (int) val($pdo, 'SELECT id FROM ticket_statuses WHERE code = ?', [$code]);
    };
    $tp = static function (string $code) use ($pdo): int {
        return (int) val($pdo, 'SELECT id FROM ticket_priorities WHERE code = ?', [$code]);
    };
    $tg = static function (string $name) use ($pdo): int {
        return (int) val($pdo, 'SELECT id FROM ticket_groups WHERE name = ?', [$name]);
    };
    $tcat = static function (string $name, ?int $parent = null) use ($pdo): int {
        return (int) val($pdo, 'SELECT id FROM ticket_categories WHERE name = ? AND parent_id ' . ($parent === null ? 'IS NULL' : '= ?'), $parent === null ? [$name] : [$name, $parent]);
    };

    $tInc = $tt('incident');
    $tSr = $tt('service_request');
    $tProblem = $tt('problem');
    $tChange = $tt('change');

    $tsNew = $ts('new');
    $tsOpen = $ts('open');
    $tsProgress = $ts('in_progress');
    $tsWaitUser = $ts('waiting_user');
    $tsResolved = $ts('resolved');
    $tsClosed = $ts('closed');

    $pLow = $tp('low');
    $pNormal = $tp('normal');
    $pHigh = $tp('high');
    $pCritical = $tp('critical');

    $gService = $tg('Service Desk');
    $gClient = $tg('Client Support');
    $gNetwork = $tg('Network');
    $gApp = $tg('Applications');
    $gLeitung = $tg('IT-Leitung');

    $catHardware = $tcat('Hardware');
    $catPcNb = $tcat('PC / Notebook', $catHardware);
    $catMonitor = $tcat('Monitor', $catHardware);
    $catDrucker = $tcat('Drucker', $catHardware);
    $catSoftware = $tcat('Software');
    $catM365 = $tcat('Microsoft 365', $catSoftware);
    $catNetzwerk = $tcat('Netzwerk');
    $catVpn = $tcat('VPN', $catNetzwerk);
    $catBenutzer = $tcat('Benutzer');
    $catPasswort = $tcat('Passwort', $catBenutzer);

    $ticket = static function (
        string $number,
        string $subject,
        string $description,
        int $typeId,
        ?int $catId,
        ?int $subcatId,
        int $statusId,
        int $priorityId,
        ?int $requesterEmp,
        ?int $assigneeUser,
        ?int $group,
        string $source = 'web',
        ?int $locationId = null,
        ?int $ccId = null,
        ?string $resolution = null
    ) use ($pdo, $adminId): int {
        return ins(
            $pdo,
            'INSERT INTO tickets (number, subject, description, ticket_type_id, category_id, subcategory_id, status_id, priority_id, requester_employee_id, assignee_user_id, group_id, source, location_id, cost_center_id, created_by, created_by_name, resolution)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$number, $subject, $description, $typeId, $catId, $subcatId, $statusId, $priorityId, $requesterEmp, $assigneeUser, $group, $source, $locationId, $ccId, $adminId, 'Max Mustermann', $resolution]
        );
    };

    $tk1 = $ticket('INC-2024-0001', 'Notebook startet nicht mehr', "Guten Tag,\n\nmein Notebook (Latitude 5440) fährt seit heute Morgen nicht mehr hoch. Beim Einschalten bleibt der Bildschirm schwarz.\n\nViele Grüße\nAnna Schmidt", $tInc, $catHardware, $catPcNb, $tsOpen, $pHigh, $empAnna, $uAgent, $gClient, 'web', $raumA015, $ccVt);
    $tk2 = $ticket('INC-2024-0002', 'VPN-Verbindung nicht möglich', "Seit dem Update kann ich mich nicht mehr per VPN verbinden. Fehlermeldung: „Das Zertifikat ist abgelaufen“.\n\nJonas Becker", $tInc, $catNetzwerk, $catVpn, $tsProgress, $pNormal, $empJonas, $uAgent, $gNetwork, 'web', $raumB005, $ccFin);
    $tk3 = $ticket('SRQ-2024-0003', 'Neuer Mitarbeiter – Onboarding', "Bitte für den neuen Mitarbeiter im Vertrieb Konto, Notebook und Berechtigungen einrichten.\n\nEintrittsdatum: 01.10.2024\nName: Lena Krüger\nAbteilung: Vertrieb", $tSr, $catBenutzer, null, $tsOpen, $pNormal, $empAnna, $uLead, $gService, 'web', $raumA015, $ccVt);
    $tk4 = $ticket('INC-2024-0004', 'Drucker im EG druckt nicht', "Der Etagen-Drucker im Raum A-012 druckt seit heute Mittag nicht mehr. Es kommt die Meldung „Toner leer“.\n\nDavid Wagner", $tInc, $catHardware, $catDrucker, $tsWaitUser, $pLow, $empDavid, $uAgent, $gClient, 'web', $raumA012, $ccIT);
    $tk5 = $ticket('INC-2024-0005', 'Server ausgefallen – kritisch', "Der zentrale Fileserver ist nicht erreichbar. Alle Abteilungen betroffen. Höchste Priorität.\n\nMax Mustermann", $tInc, $catNetzwerk, null, $tsNew, $pCritical, $empMax, null, $gLeitung, 'web', $lager, $ccIT);
    $tk6 = $ticket('SRQ-2024-0006', 'Zugriff auf Teams-Kanal', "Bitte Zugriff auf den Projektkanal „Vertriebssteuerung“ für Sarah Braun einrichten.\n\nAnna Schmidt", $tSr, $catSoftware, $catM365, $tsResolved, $pNormal, $empAnna, $uAgent, $gApp, 'web', $raumA015, $ccVt, 'Zugriff eingerichtet und getestet.');
    $tk7 = $ticket('SRQ-2024-0007', 'Passwort zurücksetzen', "Passwort für Laura Hoffmann zurücksetzen. Konto ist gesperrt.\n\nLaura Hoffmann", $tSr, $catBenutzer, $catPasswort, $tsClosed, $pLow, $empLaura, $uAgent, $gService, 'web', $raumA115, $ccPers, 'Passwort zurückgesetzt, Anmeldung verifiziert.');
    $tk8 = $ticket('PRB-2024-0008', 'Wiederkehrende WLAN-Abbrüche', "Analyse der wiederkehrenden WLAN-Abbrüche im Gebäude A, 1. OG. Betrifft mehrere Benutzer seit zwei Wochen.", $tProblem, $catNetzwerk, null, $tsOpen, $pNormal, $empThomas, $uLead, $gNetwork, 'web', $raumA110, $ccIT);

    // Kommentare
    $comment = static function (int $ticketId, string $type, string $body, int $authorId, string $authorName, bool $isRequester) use ($pdo): int {
        return ins(
            $pdo,
            'INSERT INTO ticket_comments (ticket_id, type, body, author_user_id, author_name, is_requester, created_at) VALUES (?, ?, ?, ?, ?, ?, NOW())',
            [$ticketId, $type, $body, $authorId, $authorName, $isRequester ? 1 : 0]
        );
    };
    $comment($tk1, 'internal', 'Notebook abholen lassen und Akku/Netzteil prüfen. Ggf. Ersatzgerät ausgeben.', $uAgent, 'Melanie Fischer', false);
    $comment($tk2, 'public', 'Das Zertifikat wurde erneuert. Bitte VPN-Client neu starten und erneut versuchen.', $uAgent, 'Melanie Fischer', false);
    $comment($tk2, 'public', 'Hat funktioniert, vielen Dank!', $uAgent, 'Melanie Fischer', true);
    $comment($tk6, 'public', 'Zugriff wurde eingerichtet.', $uAgent, 'Melanie Fischer', false);

    // Worklogs
    $pdo->prepare('INSERT INTO ticket_worklogs (ticket_id, user_id, user_name, minutes, activity, note, started_at, ended_at, created_at) VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW(), NOW())')
        ->execute([$tk1, $uAgent, 'Melanie Fischer', 30, 'Diagnose', 'Erstdiagnose per Telefon']);
    $pdo->prepare('INSERT INTO ticket_worklogs (ticket_id, user_id, user_name, minutes, activity, note, started_at, ended_at, created_at) VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW(), NOW())')
        ->execute([$tk2, $uAgent, 'Melanie Fischer', 45, 'Support', 'Zertifikat erneuert und getestet']);
    $pdo->prepare('INSERT INTO ticket_worklogs (ticket_id, user_id, user_name, minutes, activity, note, started_at, ended_at, created_at) VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW(), NOW())')
        ->execute([$tk6, $uAgent, 'Melanie Fischer', 15, 'Bereitstellung', 'Zugriff eingerichtet']);

    // Ticket ↔ Asset
    $pdo->prepare('INSERT INTO ticket_assets (ticket_id, asset_id, note, added_by, created_at) VALUES (?, ?, ?, ?, NOW())')
        ->execute([$tk1, $a1, 'Betroffenes Notebook', $uAgent]);

    // -------------------------------------------------------------------------
    // 14. Wissensdatenbank
    // -------------------------------------------------------------------------
    $ka = static function (string $title, string $slug, string $summary, string $body, ?int $catId, string $status, string $visibility, int $views, int $createdBy) use ($pdo): int {
        return ins(
            $pdo,
            'INSERT INTO knowledge_articles (title, slug, summary, body, category_id, status, visibility, view_count, published_at, created_by, created_by_name, updated_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?, ?, ?)',
            [$title, $slug, $summary, $body, $catId, $status, $visibility, $views, $createdBy, 'Max Mustermann', $createdBy]
        );
    };
    $ka1 = $ka('VPN einrichten und verbinden', 'vpn-einrichten', 'Schritt-für-Schritt-Anleitung zur VPN-Einrichtung auf Notebooks.', "## Voraussetzungen\n- Dienst-Notebook mit Windows 11\n- Gültiges Benutzerkonto\n\n## Einrichtung\n1. VPN-Client starten\n2. Server „vpn.musterfirma.de“ eingeben\n3. Mit Benutzerkonto anmelden\n\nBei Problemen ein Ticket in der Kategorie **Netzwerk → VPN** erstellen.", $catNetzwerk, 'published', 'internal', 142, $uLead);
    $ka2 = $ka('Passwort zurücksetzen (Self-Service)', 'passwort-self-service', 'Anleitung zum Zurücksetzen des eigenen Passworts über das Self-Service-Portal.', "## Self-Service\n1. Portal öffnen\n2. „Passwort vergessen“ wählen\n3. Anweisungen folgen\n\nFunktioniert der Self-Service nicht, wenden Sie sich an den Service Desk.", $catBenutzer, 'published', 'public', 328, $uLead);
    $ka3 = $ka('Neuer Mitarbeiter – Onboarding-Checkliste', 'onboarding-checkliste', 'Interne Checkliste für das Onboarding neuer Mitarbeiter.', "- Konto anlegen\n- Notebook bereitstellen\n- Lizenzen zuweisen\n- Zugriffe beantragen", $catBenutzer, 'published', 'internal', 87, $uLead);
    $ka4 = $ka('Drucker einrichten (Entwurf)', 'drucker-einrichten-entwurf', 'Entwurf: Anleitung zur Druckereinrichtung.', 'In Arbeit…', $catHardware, 'draft', 'internal', 0, $uLead);

    // -------------------------------------------------------------------------
    // 15. Übergabeprotokoll
    // -------------------------------------------------------------------------
    $tmplId = (int) val($pdo, 'SELECT id FROM handover_templates WHERE is_default = 1 LIMIT 1');
    $tmplSnapshot = (string) val($pdo, 'SELECT blocks FROM handover_templates WHERE id = ?', [$tmplId]);

    $items = static function (array $rows): string {
        return json_encode(array_map(static fn ($r) => [
            'asset_id' => $r[0],
            'inventory_number' => $r[1],
            'article' => $r[2],
            'serial_number' => $r[3],
            'asset_type' => $r[4],
            'assigned_at' => $r[5],
        ], $rows), JSON_UNESCAPED_UNICODE);
    };

    $employeeSnapshot = static function (array $e): string {
        return json_encode([
            'display_name' => $e['display_name'],
            'personnel_number' => $e['personnel_number'],
            'department' => $e['department'],
            'position' => $e['position'],
            'email' => $e['email'],
            'phone' => $e['phone'],
            'location' => null,
            'cost_center' => null,
        ], JSON_UNESCAPED_UNICODE);
    };

    $hp = static function (string $number, int $employeeId, string $status, string $itemsJson, string $empSnap, int $tmplId, string $tmplSnap, ?string $signedAt, int $issuerId, string $issuerName, ?string $note = null) use ($pdo, $adminId): int {
        return ins(
            $pdo,
            'INSERT INTO handover_protocols (protocol_number, employee_id, version, status, template_id, template_snapshot, employee_snapshot, items, item_count, asset_fingerprint, note, issuer_user_id, issuer_name, signed_at, created_by, created_by_name, created_at, updated_at)
             VALUES (?, ?, 1, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())',
            [$number, $employeeId, $status, $tmplId, $tmplSnap, $empSnap, $itemsJson, substr_count($itemsJson, 'inventory_number'), '', $note, $issuerId, $issuerName, $signedAt, $adminId, 'Max Mustermann']
        );
    };

    $eAnna = $pdo->query("SELECT * FROM employees WHERE id = $empAnna")->fetch();
    $eSarah = $pdo->query("SELECT * FROM employees WHERE id = $empSarah")->fetch();

    $itemsAnna = $items([
        [$a1, 'PC24001', 'Dell Latitude 5440', 'DL-5440-001', 'PC / Endgerät', '2024-01-22'],
        [$a15, 'ZUB24001', 'Dell UltraSharp U2723QE', 'U2723QE-001', 'Zubehör / Peripherie', '2024-01-22'],
        [$a16, 'ZUB24002', 'WD22TB4 Dockingstation', 'WD22TB4-001', 'Zubehör / Peripherie', '2024-01-22'],
        [$a10, 'MD24002', 'Samsung Galaxy S24', 'S24-0001', 'Mobilgerät', '2024-02-16'],
    ]);
    $itemsSarah = $items([
        [$a2, 'PC24002', 'Dell Latitude 5440', 'DL-5440-002', 'PC / Endgerät', '2024-01-22'],
    ]);

    $hp1 = $hp('ÜG-2024-0001', $empAnna, 'signed', $itemsAnna, $employeeSnapshot($eAnna), $tmplId, $tmplSnapshot, '2024-02-16 10:30:00', $adminId, 'Max Mustermann', 'Übergabe der Arbeitsmittel an Anna Schmidt');
    $hp2 = $hp('ÜG-2024-0002', $empSarah, 'draft', $itemsSarah, $employeeSnapshot($eSarah), $tmplId, $tmplSnapshot, null, $adminId, 'Max Mustermann', 'Entwurf – wartet auf Unterschrift');

    $pdo->commit();
    fwrite(STDOUT, "Demo-Daten erfolgreich angelegt.\n");
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, 'Fehler: ' . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n");
    exit(1);
}
