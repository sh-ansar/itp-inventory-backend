<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

final class EnsureActiveAccount
{
    public function handle(Request $request, Closure $next): Response
    {
        // Recheck persisted state for every request, even if a session has a stale User model.
        $authenticated = $request->user();
        // Fresh Eloquent casts normalize SQLite integers and PostgreSQL booleans.
        if ($authenticated !== null) {
            $fresh = User::query()->whereKey($authenticated->getAuthIdentifier())->first(['is_active']);
            if ($fresh?->is_active === true) return $next($request);
        }

        if (Auth::guard('web')->check()) Auth::guard('web')->logout();
        if ($request->hasSession()) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }
        return new JsonResponse(['message'=>'Unauthenticated.'], 401);
    }
}