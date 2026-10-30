<?php

namespace App\Http\Middleware;

use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Symfony\Component\HttpFoundation\Cookie;

final class IsolatedXsrfCookie extends ValidateCsrfToken
{
    protected function newCookie($request, $config)
    {
        $name = config('inventory.xsrf_cookie_name', 'INVENTORY-XSRF-TOKEN');
        abort_unless(is_string($name) && preg_match('/\A[A-Za-z0-9_-]{1,64}\z/D', $name), 500);
        return new Cookie(
            $name, $request->session()->token(),
            $this->availableAt(60 * $config['lifetime']),
            $config['path'], $config['domain'], $config['secure'],
            false, false, $config['same_site'] ?? null,
            $config['partitioned'] ?? false
        );
    }
}