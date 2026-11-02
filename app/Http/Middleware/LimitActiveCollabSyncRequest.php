<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Exceptions\TaskSyncConflictException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class LimitActiveCollabSyncRequest
{
    public function handle(Request $request, \Closure $next): Response
    {
        if (strlen($request->getContent()) > 65536) {
            throw new TaskSyncConflictException('request_too_large', null, 413);
        }
        try {
            $decoded = json_decode($request->getContent(), false, 32, JSON_THROW_ON_ERROR);
            if (! $request->isJson() || ! is_object($decoded)) {
                throw new \JsonException();
            }
        } catch (\JsonException) {
            throw new TaskSyncConflictException('invalid_request', null, 422);
        }

        return $next($request);
    }
}
