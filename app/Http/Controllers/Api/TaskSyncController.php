<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Data\ActiveCollab\ActiveCollabTaskData;
use App\Http\Controllers\Controller;
use App\Http\Requests\SyncTaskRequest;
use App\Services\ActiveCollab\ActiveCollabTaskService;
use Illuminate\Http\JsonResponse;

class TaskSyncController extends Controller
{
    public function __invoke(SyncTaskRequest $request, ActiveCollabTaskService $service): JsonResponse
    {
        $result = $service->sync(ActiveCollabTaskData::fromRequest($request->validated()), $request->attributes->get('activecollab.access'));

        return response()->json(['data' => $result], $result['action'] === 'created' ? 201 : 200);
    }
}
