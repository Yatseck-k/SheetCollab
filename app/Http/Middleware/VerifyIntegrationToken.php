<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class VerifyIntegrationToken
{
    public function __construct(private readonly AuthenticateActiveCollabIntegration $authentication) {}

    public function handle(Request $request, \Closure $next): Response
    {
        return $this->authentication->handle($request, $next, 'legacy');
    }
}
