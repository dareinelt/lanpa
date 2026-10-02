<?php

declare(strict_types=1);

return [
    'routes' => [
        ['name' => 'diagnostics#show', 'url' => '/api/diagnostics', 'verb' => 'GET'],
        ['name' => 'ai#update', 'url' => '/api/ai', 'verb' => 'POST'],
        ['name' => 'hosts#update', 'url' => '/api/hosts', 'verb' => 'POST'],
        ['name' => 'quota#update', 'url' => '/api/quota', 'verb' => 'POST'],
        ['name' => 'admins#update', 'url' => '/api/admins', 'verb' => 'POST'],
        ['name' => 'drives#update', 'url' => '/api/drives', 'verb' => 'POST'],
        ['name' => 'network_drives#show', 'url' => '/api/network-drives', 'verb' => 'GET'],
        ['name' => 'network_drives#update', 'url' => '/api/network-drives', 'verb' => 'POST'],
        ['name' => 'sso#login', 'url' => '/sso', 'verb' => 'GET'],
    ],
];
