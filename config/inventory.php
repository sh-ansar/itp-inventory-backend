<?php
return [
    'browser_login_enabled' => (bool) env('INVENTORY_BROWSER_LOGIN_ENABLED', false),
    'xsrf_cookie_name' => env('INVENTORY_XSRF_COOKIE_NAME', 'INVENTORY-XSRF-TOKEN'),
];