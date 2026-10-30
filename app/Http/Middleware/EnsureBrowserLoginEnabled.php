<?php
namespace App\Http\Middleware;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
class EnsureBrowserLoginEnabled {
 public function handle(Request $request, Closure $next): Response {
  abort_unless(config('inventory.browser_login_enabled') === true, 404);
  if(app()->environment('production')) abort_unless($request->isSecure() && config('session.secure') === true && config('session.http_only') === true, 503);
  return $next($request);
 }
}