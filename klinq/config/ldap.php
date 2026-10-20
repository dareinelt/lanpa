<?php

declare(strict_types=1);

use App\Core\Env;

return [
    'enabled' => Env::bool('AD_ENABLED', false),
    'driver' => Env::get('AD_DRIVER', 'ldap'),
    'fake_file' => Env::get('AD_FAKE_FILE', 'database/fixtures/fake-ad-users.json'),
    'sync_interval_minutes' => Env::int('AD_SYNC_INTERVAL_MINUTES', 0),
    'host' => Env::get('AD_HOST', ''),
    'port' => Env::int('AD_PORT', 636),
    'base_dn' => Env::get('AD_BASE_DN', ''),
    'bind_dn' => Env::get('AD_BIND_DN', ''),
    'bind_password' => Env::get('AD_BIND_PASSWORD', ''),
    'user_filter' => Env::get('AD_USER_FILTER', '(&(objectClass=user)(objectCategory=person))'),
    'page_size' => 500,
    'attributes' => [
        'guid' => Env::get('AD_ATTR_GUID', 'objectGUID'),
        'username' => Env::get('AD_ATTR_USERNAME', 'sAMAccountName'),
        'first_name' => Env::get('AD_ATTR_FIRST_NAME', 'givenName'),
        'last_name' => Env::get('AD_ATTR_LAST_NAME', 'sn'),
        'display_name' => Env::get('AD_ATTR_DISPLAY_NAME', 'displayName'),
        'email' => Env::get('AD_ATTR_EMAIL', 'mail'),
        'personnel_number' => Env::get('AD_ATTR_PERSONNEL_NUMBER', 'employeeID'),
        'department' => Env::get('AD_ATTR_DEPARTMENT', 'department'),
        'position' => Env::get('AD_ATTR_POSITION', 'title'),
        'phone' => Env::get('AD_ATTR_PHONE', 'telephoneNumber'),
        'location' => Env::get('AD_ATTR_LOCATION', 'physicalDeliveryOfficeName'),
        'cost_center' => Env::get('AD_ATTR_COST_CENTER', 'extensionAttribute1'),
        'account_control' => Env::get('AD_ATTR_ACCOUNT_CONTROL', 'userAccountControl'),
    ],
    'auth' => [
        'enabled' => Env::bool('AD_AUTH_ENABLED', false),
        'default_role' => Env::get('AD_AUTH_DEFAULT_ROLE', 'readonly'),
        // Login-Format: "{username}@domain" oder DN-Vorlage mit {username}
        'bind_template' => Env::get('AD_AUTH_BIND_TEMPLATE', '{username}@example.local'),
    ],
];
