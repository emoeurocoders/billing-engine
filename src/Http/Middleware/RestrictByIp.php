<?php

namespace Omni\BillingEngine\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/**
 * Gate the billing dashboard: an ?key= access key OR an allowed-IP list.
 * Mirrors the mid-balancer package's dashboard guard.
 */
class RestrictByIp
{
    public function handle(Request $request, Closure $next)
    {
        $accessKey = config('billing-engine.dashboard.access_key');
        if ($accessKey && $request->query('key') === $accessKey) {
            return $next($request);
        }

        $raw = config('billing-engine.dashboard.allowed_ips', '127.0.0.1,::1');
        $allowed = is_array($raw) ? $raw : array_map('trim', explode(',', $raw));

        if (!in_array($request->ip(), $allowed, true)) {
            abort(403);
        }

        return $next($request);
    }
}
