<?php

declare(strict_types=1);

use App\Http\Controllers\Api\ActiveCollabTaskController;
use Illuminate\Support\Facades\Route;

Route::prefix('integrations/activecollab')
    ->middleware('integration.token')
    ->group(function () {
        Route::post('tasks', [ActiveCollabTaskController::class, 'store']);
        Route::put('tasks/{taskId}', [ActiveCollabTaskController::class, 'update']);
        Route::get('task-lists', [ActiveCollabTaskController::class, 'taskLists']);
        Route::get('users', [ActiveCollabTaskController::class, 'users']);
        Route::get('labels', [ActiveCollabTaskController::class, 'labels']);
    });
