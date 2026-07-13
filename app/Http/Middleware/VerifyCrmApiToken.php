<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/**
 * Static bearer-token auth for the outreach write API (/api/outreach/*).
 * Least privilege: only the two outreach endpoints use it — no session, no
 * super-admin. Token is CRM_API_TOKEN (Northflank env → .env). Rotate by changing
 * the env var and redeploying.
 */
class VerifyCrmApiToken
{
    public function handle(Request $request, Closure $next)
    {
        $expected = env('CRM_API_TOKEN');
        $provided = $request->bearerToken() ?: $request->header('X-Api-Key');

        if (empty($expected) || empty($provided) || ! hash_equals($expected, $provided)) {
            return response()->json([
                'error' => 'unauthorized',
                'message' => 'Missing or invalid API token.',
            ], 401);
        }

        return $next($request);
    }
}
