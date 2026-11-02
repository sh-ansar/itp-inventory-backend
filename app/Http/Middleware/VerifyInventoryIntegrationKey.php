<?php

namespace App\Http\Middleware;

use App\Models\Company;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

final class VerifyInventoryIntegrationKey
{
    public function handle(Request $request, Closure $next, string $ability): Response
    {
        // A deploy must explicitly opt in; the API is invisible by default.
        abort_unless((bool) config('inventory.integrations_enabled', false), 404);

        $company = $request->route('company');
        if (! $company instanceof Company) {
            abort(404);
        }
        $token = $request->bearerToken();
        if (! is_string($token) || ! preg_match('/^inv_[a-zA-Z0-9]{64}$/D', $token)) {
            abort(401, 'Integration token required');
        }
        $hash = hash('sha256', $token);
        $key = DB::table('inventory_integration_keys')
            ->where('token_hash', $hash)
            ->where('company_id', $company->id)
            ->first();
        if (! $key || ! hash_equals($key->token_hash, $hash) ||
            $key->revoked_at !== null ||
            Carbon::parse($key->expires_at)->isPast()) {
            // Wrong-company tokens are indistinguishable from invalid tokens.
            abort(401, 'Integration token invalid or expired');
        }
        $abilities = json_decode($key->abilities, true);
        if (! is_array($abilities) || ! in_array($ability, $abilities, true)) {
            abort(403, 'Integration scope denied');
        }
        $request->attributes->set('inventory.integration.id', $key->id);
        $request->attributes->set('inventory.integration.system', $key->system_code);
        // Rate-limited audit metadata; do not store plaintext tokens or request bodies.
        if ($key->last_used_at === null || Carbon::parse($key->last_used_at)->lt(now()->subMinutes(5))) {
            DB::table('inventory_integration_keys')->where('id', $key->id)
                ->update(['last_used_at' => now()]);
        }

        return $next($request);
    }
}
