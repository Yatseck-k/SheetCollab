<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class VerifyIntegrationToken
{
    public function handle(Request $request, \Closure $next): Response
    {
        $expected = config('services.google_sheets.integration_token');

        if (! $expected) {
            return response()->json(['message' => 'Integration token not configured.'], 500);
        }

        $provided = $request->bearerToken();

        if (! $provided || ! hash_equals($expected, $provided)) {
            return response()->json(['message' => 'Unauthorized.'], 401);
        }

        return $next($request);
    }
}
