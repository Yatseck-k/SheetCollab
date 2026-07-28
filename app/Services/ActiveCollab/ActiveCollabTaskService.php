<?php

declare(strict_types=1);

namespace App\Services\ActiveCollab;

use App\Data\ActiveCollab\ActiveCollabTaskData;
use App\Exceptions\IntegrationNotFoundException;
use App\Exceptions\TaskAlreadyExistsException;
use App\Models\ActiveCollabIntegration;
use App\Models\ActiveCollabTaskExport;
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
    ) {}

    /** @return array<string, mixed> */
    public function create(ActiveCollabTaskData $data): array
    {
        $integration = $this->resolveIntegration($data->spreadsheetId);
        $instanceUrl = $integration->resolvedInstanceUrl();

        $existing = $this->exportRepository->findByExternalId($data->externalId);

        if ($existing && in_array($existing->status, ['created', 'updated'], true)) {
            throw new TaskAlreadyExistsException($existing->task_id, $existing->task_url);
        }

        $export = $existing ?? $this->exportRepository->createPending($data, $integration);

        try {
            $payload = $this->buildPayload($data, $integration);
        } catch (\RuntimeException $e) {
            $this->exportRepository->markFailed($export, $e->getMessage());
            Log::warning('ActiveCollab mapping error', [
                'external_id' => $data->externalId,
                'message' => $e->getMessage(),
            ]);
            throw $e;
        }

        Log::info('Creating ActiveCollab task', [
            'external_id' => $data->externalId,
            'integration_id' => $integration->id,
            'task_name' => $payload['name'],
        ]);

        try {
            $response = $this->acRepository->createTask($integration, $payload);
        } catch (\Throwable $e) {
            $this->exportRepository->markFailed($export, $e->getMessage());
            Log::error('ActiveCollab task creation failed', [
                'external_id' => $data->externalId,
                'integration_id' => $integration->id,
                'message' => $e->getMessage(),
            ]);
            throw $e;
        }

        $taskId = $response['single']['id'];
        $taskUrl = $instanceUrl.$response['single']['url_path'];
        $syncHash = $this->calcSyncHash($data);

        $this->exportRepository->markCreated($export, $taskId, $taskUrl, $syncHash, $payload, $response);

        return [
            'task_id' => $taskId,
            'task_url' => $taskUrl,
            'synced_at' => now()->toIso8601String(),
            'sync_hash' => $syncHash,
        ];
    }

    /** @return array<string, mixed> */
    public function update(int $taskId, ActiveCollabTaskData $data): array
    {
        $integration = $this->resolveIntegration($data->spreadsheetId);
        $instanceUrl = $integration->resolvedInstanceUrl();

        $export = $this->exportRepository->findByTaskId($taskId)
            ?? $this->exportRepository->findByExternalId($data->externalId);

        try {
            $payload = $this->buildPayload($data, $integration);
        } catch (\RuntimeException $e) {
            if ($export instanceof ActiveCollabTaskExport) {
                $this->exportRepository->markFailed($export, $e->getMessage());
            }
            Log::warning('ActiveCollab mapping error on update', [
                'task_id' => $taskId,
                'external_id' => $data->externalId,
                'message' => $e->getMessage(),
            ]);
            throw $e;
        }

        Log::info('Updating ActiveCollab task', [
            'task_id' => $taskId,
            'external_id' => $data->externalId,
            'integration_id' => $integration->id,
        ]);

        try {
            $response = $this->acRepository->updateTask($integration, $taskId, $payload);
        } catch (\Throwable $e) {
            if ($export instanceof ActiveCollabTaskExport) {
                $this->exportRepository->markFailed($export, $e->getMessage());
            }
            Log::error('ActiveCollab task update failed', [
                'task_id' => $taskId,
                'integration_id' => $integration->id,
                'message' => $e->getMessage(),
            ]);
            throw $e;
        }

        $taskUrl = $instanceUrl.$response['single']['url_path'];
        $syncHash = $this->calcSyncHash($data);

        if ($export instanceof ActiveCollabTaskExport) {
            $this->exportRepository->markUpdated($export, $syncHash, $data->toArray(), $payload, $response);
        }

        return [
            'task_id' => $taskId,
            'task_url' => $taskUrl,
            'synced_at' => now()->toIso8601String(),
            'sync_hash' => $syncHash,
        ];
    }

    private function resolveIntegration(string $spreadsheetId): ActiveCollabIntegration
    {
        $integration = $this->integrationRepository->findBySpreadsheetId($spreadsheetId);

        if (! $integration instanceof ActiveCollabIntegration) {
            Log::warning('ActiveCollab integration not found', ['spreadsheet_id' => $spreadsheetId]);
            throw new IntegrationNotFoundException($spreadsheetId);
        }

        return $integration;
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
