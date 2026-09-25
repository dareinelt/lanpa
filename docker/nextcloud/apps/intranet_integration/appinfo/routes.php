<?php

declare(strict_types=1);

return [
    'routes' => [
        ['name' => 'diagnostics#show', 'url' => '/api/diagnostics', 'verb' => 'GET'],
        ['name' => 'sso#login', 'url' => '/sso', 'verb' => 'GET'],
    ],
];
