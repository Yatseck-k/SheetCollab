<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Repositories\ActiveCollab\ActiveCollabIntegrationTokenRepository;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateActiveCollabIntegration
{
    public function __construct(private readonly ActiveCollabIntegrationTokenRepository $tokens) {}

    public function handle(Request $request, \Closure $next, string $mode = 'individual'): Response
    {
        $spreadsheet = $request->input('spreadsheet_id');
        if ($mode !== 'individual' && (! is_string($spreadsheet) || $spreadsheet === '')) {
            $expected = (string) config('services.google_sheets.integration_token');
            if ($expected !== '' && hash_equals($expected, $request->bearerToken() ?? '')) {
                $request->attributes->set('activecollab.access', null);

                return $next($request);
            }
        }
        $context = $this->tokens->authenticate($request->bearerToken() ?? '', is_string($spreadsheet) ? $spreadsheet : null, $mode === 'individual');
        $request->attributes->set('activecollab.access', $context);

        return $next($request);
    }
}
