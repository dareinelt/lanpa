<?php

declare(strict_types=1);

return [
    'routes' => [
        ['name' => 'diagnostics#show', 'url' => '/api/diagnostics', 'verb' => 'GET'],
        ['name' => 'ai#update', 'url' => '/api/ai', 'verb' => 'POST'],
        ['name' => 'hosts#update', 'url' => '/api/hosts', 'verb' => 'POST'],
        ['name' => 'quota#update', 'url' => '/api/quota', 'verb' => 'POST'],
        ['name' => 'admins#update', 'url' => '/api/admins', 'verb' => 'POST'],
        ['name' => 'sso#login', 'url' => '/sso', 'verb' => 'GET'],
    ],
];
