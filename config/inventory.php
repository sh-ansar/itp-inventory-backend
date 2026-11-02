<?php
return [
    'browser_login_enabled' => (bool) env('INVENTORY_BROWSER_LOGIN_ENABLED', false),
    // Integrations stay OFF until explicitly enabled after external API acceptance.
    'integrations_enabled' => (bool) env('INVENTORY_INTEGRATIONS_ENABLED', false),
    'xsrf_cookie_name' => env('INVENTORY_XSRF_COOKIE_NAME', 'INVENTORY-XSRF-TOKEN'),
];