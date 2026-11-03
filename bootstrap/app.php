<?php

declare(strict_types=1);

use App\Exceptions\ActiveCollabRequestException;
use App\Exceptions\IntegrationNotFoundException;
use App\Exceptions\TaskAlreadyExistsException;
use App\Exceptions\TaskBindingConflictException;
use App\Exceptions\TaskSyncConflictException;
use App\Http\Middleware\AuthenticateActiveCollabIntegration;
use App\Http\Middleware\LimitActiveCollabSyncRequest;
use App\Http\Middleware\VerifyIntegrationToken;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $trustedProxies = array_values(array_filter(array_map(
            trim(...),
            explode(',', (string) env('TRUSTED_PROXIES', '')),
        )));

        if ($trustedProxies !== []) {
            $middleware->trustProxies(at: $trustedProxies);
        }

        $middleware->alias([
            'integration.token' => VerifyIntegrationToken::class,
            'activecollab.token' => AuthenticateActiveCollabIntegration::class,
            'activecollab.limit' => LimitActiveCollabSyncRequest::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );

        $exceptions->render(function (ValidationException $e, Request $request) {
            if ($request->is('api/tasks/sync')) {
                return response()->json(['error' => ['code' => 'invalid_request', 'message' => 'Некорректные данные запроса.',
                    'operation_id' => null, 'retryable' => false], 'errors' => $e->errors()], 422);
            }

            return null;
        });

        $exceptions->render(function (IntegrationNotFoundException $e) {
            return response()->json(['message' => $e->getMessage()], 404);
        });

        $exceptions->render(function (TaskSyncConflictException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'error' => ['code' => $e->errorCode, 'message' => $e->getMessage(),
                    'operation_id' => $e->operationId, 'retryable' => $e->retryable],
            ], $e->httpStatus);
        });

        $exceptions->render(function (ActiveCollabRequestException $e) {
            return response()->json(['message' => $e->getMessage()], $e->timeout ? 504 : 502);
        });

        $exceptions->render(function (TaskBindingConflictException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'error' => [
                    'code' => 'legacy_binding_conflict',
                    'message' => $e->getMessage(),
                    'operation_id' => null,
                    'retryable' => false,
                ],
            ], 409);
        });

        $exceptions->render(function (TaskAlreadyExistsException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'data' => [
                    'task_id' => $e->taskId,
                    'task_url' => $e->taskUrl,
                ],
            ], 409);
        });
    })->create();
