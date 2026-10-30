<?php

declare(strict_types=1);

namespace App\Services\ActiveCollab;

use App\Data\ActiveCollab\ActiveCollabSyncReservation;
use App\Data\ActiveCollab\ActiveCollabTaskData;
use App\Exceptions\ActiveCollabRequestException;
use App\Exceptions\IntegrationNotFoundException;
use App\Exceptions\TaskSyncConflictException;
use App\Models\ActiveCollabIntegration;
use App\Repositories\ActiveCollab\ActiveCollabExportRepository;
use App\Repositories\ActiveCollab\ActiveCollabIntegrationRepository;
use App\Repositories\ActiveCollab\ActiveCollabRepository;
use Illuminate\Support\Facades\Log;

class ActiveCollabTaskService
{
    public function __construct(
        private readonly ActiveCollabRepository $acRepository,
        private readonly ActiveCollabExportRepository $exportRepository,
        private readonly ActiveCollabIntegrationRepository $integrationRepository,
        private readonly ActiveCollabTaskBodyBuilder $bodyBuilder,
        private readonly ActiveCollabMappingService $mappingService,
        private readonly ActiveCollabDataSanitizer $sanitizer,
    ) {}

    /** @return array<string, mixed> */
    public function create(ActiveCollabTaskData $data): array
    {
        return $this->execute($data);
    }

    /** @return array<string, mixed> */
    public function update(int $taskId, ActiveCollabTaskData $data): array
    {
        return $this->execute($data, $taskId);
    }

    /** @return array<string, mixed> */
    private function execute(ActiveCollabTaskData $data, ?int $taskId = null): array
    {
        $integration = $this->integrationRepository->findBySpreadsheetId($data->spreadsheetId);
        if ($integration === null) {
            throw new IntegrationNotFoundException($data->spreadsheetId);
        }
        $source = $this->sanitizer->clean($data->toArray(), $integration);
        $reservation = $this->exportRepository->reserve($data, $integration, $source, $taskId);
        if ($reservation->conflictCode !== null) {
            throw new TaskSyncConflictException($reservation->conflictCode, $reservation->operationId);
        }

        return $this->executeReserved($data, $integration, $reservation, $taskId);
    }

    /**
     * A replacement uses a server-authorized reservation; public API callers cannot bypass reserve().
     *
     * @return array<string, mixed>
     */
    public function executeReserved(ActiveCollabTaskData $data, ActiveCollabIntegration $integration,
        ActiveCollabSyncReservation $reservation, ?int $taskId = null, ?\Closure $guard = null): array
    {
        $source = $this->sanitizer->clean($data->toArray(), $integration);
        try {
            $payload = $this->buildPayload($data, $integration);
            $hash = $this->calcSyncHash($data);
        } catch (\Throwable $e) {
            $message = $this->sanitizer->clean(['message' => $e->getMessage()], $integration)['message'];
            $saved = $this->recordFailure($reservation, 'failed', 'mapping_invalid', $message, guard: $guard);
            throw new TaskSyncConflictException($saved ? 'mapping_invalid' : 'sync_outcome_unknown', $reservation->operationId, 500);
        }
        try {
            if ($guard !== null) {
                $guard();
            }
            $prepared = $this->exportRepository->prepareDispatch($reservation, $this->sanitizer->clean($payload, $integration), $hash);
        } catch (\Throwable) {
            $saved = $this->recordFailure($reservation, 'failed', 'pre_dispatch_failed', 'Local preparation failed before dispatch.', guard: $guard);
            throw new TaskSyncConflictException($saved ? 'pre_dispatch_failed' : 'sync_outcome_unknown', $reservation->operationId, 502);
        }
        if (! $prepared) {
            throw new TaskSyncConflictException('sync_outcome_unknown', $reservation->operationId);
        }
        try {
            $response = $taskId === null
                ? $this->acRepository->createTask($integration, $payload)
                : $this->acRepository->updateTask($integration, $taskId, $payload);
            $response = $this->sanitizer->clean($response, $integration);
            $resultTaskId = $response['single']['id'];
            $taskUrl = $this->sanitizer->clean(['url' => $integration->resolvedInstanceUrl().$response['single']['url_path']], $integration)['url'];
            $completed = $this->exportRepository->complete($reservation, 'succeeded', [
                'task_id' => $resultTaskId, 'task_url' => $taskUrl, 'sync_hash' => $hash,
                'source_payload' => $source, 'active_collab_payload' => $this->sanitizer->clean($payload, $integration),
                'active_collab_response' => $response,
            ], guard: $guard);
        } catch (\Throwable $e) {
            $httpStatus = $e instanceof ActiveCollabRequestException ? $e->httpStatus : null;
            $timeout = $e instanceof ActiveCollabRequestException && $e->timeout;
            $missing = $taskId !== null && $httpStatus === 404;
            $code = $missing ? 'remote_task_missing' : ($e instanceof ActiveCollabRequestException ? $e->category : 'sync_outcome_unknown');
            $saved = $this->recordFailure($reservation, $missing ? 'failed' : 'unknown', $code, 'External result could not be confirmed.', $httpStatus);
            Log::warning('ActiveCollab synchronization failed', ['operation_id' => $reservation->operationId, 'http_status' => $httpStatus, 'category' => $code]);
            throw new TaskSyncConflictException($missing && $saved ? 'remote_task_missing' : 'sync_outcome_unknown',
                $reservation->operationId, $missing && $saved ? 409 : ($timeout ? 504 : 502));
        }
        if (! $completed) {
            throw new TaskSyncConflictException('sync_outcome_unknown', $reservation->operationId);
        }

        return ['task_id' => $resultTaskId, 'task_url' => $taskUrl, 'synced_at' => now()->toIso8601String(), 'sync_hash' => $hash];
    }

    private function recordFailure(ActiveCollabSyncReservation $reservation, string $status, string $code, string $message,
        ?int $httpStatus = null, ?\Closure $guard = null): bool
    {
        try {
            return $this->exportRepository->complete($reservation, $status, errorCode: $code,
                errorMessage: $message, httpStatus: $httpStatus, guard: $guard);
        } catch (\Throwable) {
            // Durable running remains; expiry cannot grant another external create.
            return false;
        }
    }

    /** @return array<string, mixed> */
    private function buildPayload(ActiveCollabTaskData $data, ActiveCollabIntegration $integration): array
    {
        $payload = [
            'name' => $this->bodyBuilder->buildName($data),
            'body' => $this->bodyBuilder->buildBody($data),
            'task_list_id' => $this->mappingService->resolveTaskListId($data->taskType, $integration),
        ];

        $assigneeId = $this->mappingService->resolveAssigneeId($data->responsible, $integration);
        if ($assigneeId !== null) {
            $payload['assignee_id'] = $assigneeId;
        }

        $labelId = $this->mappingService->resolveLabelId($data->severity, $integration);
        if ($labelId !== null) {
            $payload['label_id'] = $labelId;
        }

        return $payload;
    }

    private function calcSyncHash(ActiveCollabTaskData $data): string
    {
        return md5(implode('|', [
            $data->severity, $data->priority, $data->device, $data->resolution,
            $data->environment, $data->section, $data->problemDescription,
            $data->screenshotUrl, $data->comment, $data->taskType,
            $data->responsible, $data->reporter, $data->sheetStatus,
        ]));
    }
}
