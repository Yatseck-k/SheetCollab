<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Data\ActiveCollab\ActiveCollabTaskData;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreActiveCollabTaskRequest;
use App\Http\Requests\UpdateActiveCollabTaskRequest;
use App\Repositories\ActiveCollab\ActiveCollabRepository;
use App\Services\ActiveCollab\ActiveCollabTaskService;
use Illuminate\Http\JsonResponse;

class ActiveCollabTaskController extends Controller
{
    public function __construct(
        private readonly ActiveCollabTaskService $taskService,
        private readonly ActiveCollabRepository $acRepository,
    ) {}

    public function store(StoreActiveCollabTaskRequest $request): JsonResponse
    {
        $data = ActiveCollabTaskData::fromRequest($request->validated());
        $result = $this->taskService->create($data);

        return response()->json(['data' => $result], 201);
    }

    public function update(UpdateActiveCollabTaskRequest $request, int $taskId): JsonResponse
    {
        $data = ActiveCollabTaskData::fromRequest($request->validated());
        $result = $this->taskService->update($taskId, $data);

        return response()->json(['data' => $result]);
    }

    public function taskLists(): JsonResponse
    {
        return response()->json($this->acRepository->getTaskLists());
    }

    public function users(): JsonResponse
    {
        return response()->json($this->acRepository->getUsers());
    }

    public function labels(): JsonResponse
    {
        return response()->json($this->acRepository->getLabels());
    }
}
