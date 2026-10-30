<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

final class RequestCorrelationId
{
    public function handle(Request $request, Closure $next): Response
    {
        // Do not trust client-provided IDs; issue one server-side for each request.
        $id = (string) Str::uuid();
        $request->attributes->set('inventory.request_id', $id);
        $response = $next($request);
        $response->headers->set('X-Request-ID', $id);
        return $response;
    }
}