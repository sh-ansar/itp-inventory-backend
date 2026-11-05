<?php

return [
    'browser_login_enabled' => (bool) env('INVENTORY_BROWSER_LOGIN_ENABLED', false),
    'integrations_enabled' => (bool) env('INVENTORY_INTEGRATIONS_ENABLED', false),
    'xsrf_cookie_name' => env('INVENTORY_XSRF_COOKIE_NAME', 'INVENTORY-XSRF-TOKEN'),
    // Independent gates: no Portal cookies, database tables, billing or SMTP.
    'public_registration_enabled' => (bool) env('INVENTORY_PUBLIC_REGISTRATION_ENABLED', false),
    'public_url' => env('INVENTORY_PUBLIC_URL', 'https://platform.itpmining.kz/inventory'),
    'invoice_issuance_enabled' => (bool) env('INVENTORY_INVOICE_ISSUANCE_ENABLED', false),
    'legal_publication_enabled' => (bool) env('INVENTORY_LEGAL_PUBLICATION_ENABLED', false),
];
