<?php

declare(strict_types=1);

namespace App\Services\ActiveCollab;

use App\Data\ActiveCollab\ActiveCollabAccessContext;
use App\Data\ActiveCollab\ActiveCollabSyncReservation;
use App\Data\ActiveCollab\ActiveCollabTaskData;
use App\Exceptions\ActiveCollabRequestException;
use App\Exceptions\IntegrationNotFoundException;
use App\Exceptions\TaskSyncConflictException;
use App\Models\ActiveCollabIntegration;
use App\Repositories\ActiveCollab\ActiveCollabExportRepository;
use App\Repositories\ActiveCollab\ActiveCollabIntegrationRepository;
use App\Repositories\ActiveCollab\ActiveCollabIntegrationTokenRepository;
use App\Repositories\ActiveCollab\ActiveCollabRepository;
use Illuminate\Support\Facades\Log;

class ActiveCollabTaskService
{
    public function __construct(
        private readonly ActiveCollabRepository $acRepository,
        private readonly ActiveCollabExportRepository $exportRepository,
        private readonly ActiveCollabIntegrationRepository $integrationRepository,
        private readonly ActiveCollabTaskPayloadBuilder $payloadBuilder,
        private readonly ActiveCollabDataSanitizer $sanitizer,
        private readonly ActiveCollabIntegrationTokenRepository $tokens,
    ) {}

    /** @return array<string, mixed> */
    public function create(ActiveCollabTaskData $data, ?ActiveCollabAccessContext $access = null): array
    {
        return $this->execute($data, null, $access);
    }

    /** @return array<string, mixed> */
    public function update(int $taskId, ActiveCollabTaskData $data, ?ActiveCollabAccessContext $access = null): array
    {
        return $this->execute($data, $taskId, $access);
    }

    /** @return array<string, mixed> */
    public function sync(ActiveCollabTaskData $data, ActiveCollabAccessContext $access): array
    {
        return $this->execute($data, null, $access, true);
    }

    /** @return array<string, mixed> */
    private function execute(ActiveCollabTaskData $data, ?int $taskId, ?ActiveCollabAccessContext $access, bool $auto = false): array
    {
        $integration = $access !== null ? $this->tokens->integration($access) : $this->integrationRepository->findBySpreadsheetId($data->spreadsheetId);
        if ($integration === null) {
            throw new IntegrationNotFoundException($data->spreadsheetId);
        }
        if ($integration->spreadsheet_id !== $data->spreadsheetId) {
            throw new TaskSyncConflictException('forbidden', null, 403);
        }
        $raw = $data->toArray();
        $source = $this->sanitizer->clean($raw, $integration, $access?->secrets() ?? []);
        $reservation = $this->exportRepository->reserve($data, $integration, $source, $taskId, $access, $auto);
        if ($reservation->conflictCode !== null) {
            throw new TaskSyncConflictException($reservation->conflictCode, $reservation->operationId);
        }
        $guard = $access !== null ? fn () => $this->tokens->integration($access) : null;

        return $this->executeReserved($data, $integration, $reservation, $auto ? $reservation->taskId : $taskId, $guard, $auto, $access);
    }

    /** A recovery replacement supplies its own authorized reservation/guard, never a public force flag.
     * @return array<string, mixed>
     */
    public function executeReserved(ActiveCollabTaskData $data, ActiveCollabIntegration $integration,
        ActiveCollabSyncReservation $reservation, ?int $taskId = null, ?\Closure $guard = null,
        bool $sync = false, ?ActiveCollabAccessContext $access = null): array
    {
        $secrets = $access?->secrets() ?? [];
        $source = $this->sanitizer->clean($data->toArray(), $integration, $secrets);
        $version = $sync ? ActiveCollabTaskPayloadBuilder::HASH_VERSION : ActiveCollabTaskPayloadBuilder::LEGACY_VERSION;
        try {
            $mapped = $this->payloadBuilder->prepare($data, $integration);
            $labels = $mapped['label_id'] !== null ? $this->acRepository->labelsForIntegration($integration) : [];
            $payload = $this->payloadBuilder->finish($mapped, $labels);
            $hash = $sync ? $this->payloadBuilder->hash($payload, $data, $integration, $mapped['label_id']) : $this->calcSyncHash($data);
        } catch (\Throwable $e) {
            $upstream = $e instanceof ActiveCollabRequestException;
            $code = $upstream ? 'upstream_error' : 'mapping_invalid';
            $saved = $this->recordFailure($reservation, 'failed', $code, 'Request preparation failed before dispatch.');
            throw new TaskSyncConflictException($saved ? $code : 'sync_outcome_unknown', $reservation->operationId,
                $upstream ? ($e->timeout ? 504 : 502) : ($sync ? 422 : 500), $sync && $saved);
        }
        try {
            $prepared = $this->exportRepository->prepareDispatch($reservation,
                $this->sanitizer->clean($payload, $integration, $secrets), $hash, $version, $guard, $sync);
        } catch (\Throwable $e) {
            $saved = $this->recordFailure($reservation, 'failed', 'pre_dispatch_failed', 'Dispatch authorization or preparation failed.');
            if ($saved && $e instanceof TaskSyncConflictException) {
                throw new TaskSyncConflictException($e->errorCode, $reservation->operationId, $e->httpStatus);
            }
            throw new TaskSyncConflictException($saved ? 'upstream_error' : 'sync_outcome_unknown', $reservation->operationId, 502, $sync && $saved);
        }
        if (is_array($prepared)) {
            return $prepared;
        }
        if (! $prepared) {
            throw new TaskSyncConflictException('sync_outcome_unknown', $reservation->operationId);
        }
        $response = [];
        try {
            $response = $sync
                ? $this->acRepository->syncTask($integration, $taskId, $payload,
                    $mapped['label_id'] !== null ? [$mapped['label_id']] : [])
                : ($taskId === null ? $this->acRepository->createTask($integration, $payload) : $this->acRepository->updateTask($integration, $taskId, $payload));
            $response = $this->sanitizer->clean($response, $integration, $secrets);
            $resultTaskId = $response['single']['id'];
            $taskUrl = $this->sanitizer->clean(['url' => $integration->resolvedInstanceUrl().$response['single']['url_path']], $integration, $secrets)['url'];
            $confirmedAt = now()->startOfSecond();
            $completed = $this->exportRepository->complete($reservation, 'succeeded', [
                'task_id' => $resultTaskId, 'task_url' => $taskUrl, 'sync_hash' => $hash, 'hash_version' => $version,
                'source_payload' => $source, 'active_collab_payload' => $this->sanitizer->clean($payload, $integration, $secrets),
                'active_collab_response' => $response, 'synced_at' => $confirmedAt,
            ], guard: $guard);
        } catch (\Throwable $e) {
            $upstream = $e instanceof ActiveCollabRequestException;
            $httpStatus = $upstream ? $e->httpStatus : null;
            $missing = $taskId !== null && $httpStatus === 404;
            $code = $missing ? 'remote_task_missing' : ($upstream ? $e->category : 'sync_outcome_unknown');
            $safe = $this->sanitizer->clean($upstream ? $e->safeResponse : $response, $integration, $secrets);
            $saved = $this->recordFailure($reservation, $missing ? 'failed' : 'unknown', $code,
                'External result could not be confirmed.', $httpStatus, $safe);
            Log::warning('ActiveCollab synchronization failed', ['operation_id' => $reservation->operationId, 'http_status' => $httpStatus, 'category' => $code]);
            throw new TaskSyncConflictException($missing && $saved ? 'remote_task_missing' : 'sync_outcome_unknown',
                $reservation->operationId, $missing && $saved ? 409 : ($upstream && $e->timeout ? 504 : 502));
        }
        if (! $completed) {
            throw new TaskSyncConflictException('sync_outcome_unknown', $reservation->operationId);
        }
        $result = ['task_id' => $resultTaskId, 'task_url' => $taskUrl, 'synced_at' => $confirmedAt->toIso8601String(), 'sync_hash' => $hash];

        return $sync ? $result + ['record_uuid' => $data->recordUuid, 'action' => $taskId === null ? 'created' : 'updated', 'operation_id' => $reservation->operationId] : $result;
    }

    /** @param array<string, mixed> $response */
    private function recordFailure(ActiveCollabSyncReservation $reservation, string $status, string $code, string $message,
        ?int $httpStatus = null, array $response = []): bool
    {
        try {
            return $this->exportRepository->complete($reservation, $status, errorCode: $code,
                errorMessage: $message, httpStatus: $httpStatus, attemptResponse: $response);
        } catch (\Throwable) {
            return false; // Durable running remains; expiry never grants another external create.
        }
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
