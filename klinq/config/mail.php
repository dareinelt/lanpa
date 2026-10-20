<?php

declare(strict_types=1);

use App\Core\Env;

return [
    // E-Mail-Versanddienst (Container "mail"); leer = kein Versand möglich
    'service_url' => rtrim((string) Env::get('MAIL_SERVICE_URL', 'http://mail:8025'), '/'),
    'service_timeout' => Env::int('MAIL_SERVICE_TIMEOUT', 15),
    'from_address' => Env::get('MAIL_FROM_ADDRESS', ''),
    'from_name' => Env::get('MAIL_FROM_NAME', ''),
];
