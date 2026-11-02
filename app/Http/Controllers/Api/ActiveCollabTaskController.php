<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Data\ActiveCollab\ActiveCollabTaskData;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreActiveCollabTaskRequest;
use App\Http\Requests\UpdateActiveCollabTaskRequest;
use App\Services\ActiveCollab\ActiveCollabIntegrationTokenService;
use App\Services\ActiveCollab\ActiveCollabTaskService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ActiveCollabTaskController extends Controller
{
    public function __construct(
        private readonly ActiveCollabTaskService $taskService,
    ) {}

    public function store(StoreActiveCollabTaskRequest $request): JsonResponse
    {
        $data = ActiveCollabTaskData::fromRequest($request->validated());
        $result = $this->taskService->create($data, $request->attributes->get('activecollab.access'));

        return response()->json(['data' => $result], 201);
    }

    public function update(UpdateActiveCollabTaskRequest $request, int $taskId): JsonResponse
    {
        $data = ActiveCollabTaskData::fromRequest($request->validated());
        $result = $this->taskService->update($taskId, $data, $request->attributes->get('activecollab.access'));

        return response()->json(['data' => $result]);
    }

    public function taskLists(Request $request, ActiveCollabIntegrationTokenService $service): JsonResponse
    {
        return response()->json($service->catalog($request->attributes->get('activecollab.access'), 'task-lists'));
    }

    public function users(Request $request, ActiveCollabIntegrationTokenService $service): JsonResponse
    {
        return response()->json($service->catalog($request->attributes->get('activecollab.access'), 'users'));
    }

    public function labels(Request $request, ActiveCollabIntegrationTokenService $service): JsonResponse
    {
        return response()->json($service->catalog($request->attributes->get('activecollab.access'), 'labels'));
    }
}
