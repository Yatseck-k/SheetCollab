<?php

declare(strict_types=1);

namespace App\Services\ActiveCollab;

use App\Data\ActiveCollab\ActiveCollabRecoveryData;
use App\Data\ActiveCollab\ActiveCollabTaskData;
use App\Exceptions\ActiveCollabRequestException;
use App\Exceptions\TaskSyncConflictException;
use App\Repositories\ActiveCollab\ActiveCollabExportRepository;
use App\Repositories\ActiveCollab\ActiveCollabRepository;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

class ActiveCollabRecoveryService
{
    public function __construct(
        private readonly ActiveCollabExportRepository $exports,
        private readonly ActiveCollabRepository $client,
        private readonly ActiveCollabTaskService $tasks,
        private readonly ActiveCollabDataSanitizer $sanitizer,
    ) {}

    /** @return array<string, mixed> */
    public function view(int $actorId, string $exportId, ?string $sourceAttemptId = null): array
    {
        $snapshot = $this->exports->recoverySnapshot($actorId, $exportId, $sourceAttemptId);
        $export = $snapshot['export'];
        $integration = $snapshot['integration'];
        $attempts = [];
        foreach ($snapshot['attempts'] as $attempt) {
            $attempts[] = $attempt->only(['id', 'operation_id', 'action', 'status', 'error_code', 'http_status', 'started_at', 'finished_at']);
        }

        return ['export' => $this->sanitizer->clean($export->only([
            'id', 'record_uuid', 'external_id', 'task_id', 'task_url', 'operation_id', 'last_attempt_status', 'binding_status', 'synced_at',
        ]), $integration), 'source' => $this->sanitizer->source($snapshot['source'], $integration),
            'attempts' => $attempts, 'integration_name' => $this->sanitizer->clean(['name' => $integration->name], $integration)['name'],
            'project_id' => $export->project_id, 'allowed' => $snapshot['allowed'],
            'attempt_label' => match ($export->last_attempt_status) {
                'idle' => 'Готово к отправке', 'running' => 'Выполняется', 'succeeded' => 'Успешно',
                'failed' => 'Ошибка', 'unknown' => 'Требуется сверка', default => 'Не классифицировано',
            },
            'binding_label' => match ($export->binding_status) {
                'bound' => 'Привязана', 'unbound' => 'Не привязана', 'remote_missing' => 'Не найдена или недоступна', default => 'Не классифицировано',
            }];
    }

    /** @return array<string, mixed> */
    public function recover(int $actorId, string $exportId, ActiveCollabRecoveryData $data): array
    {
        $snapshot = $this->exports->recoverySnapshot($actorId, $exportId, $data->sourceAttemptId);
        $export = $snapshot['export'];
        $integration = $snapshot['integration'];
        $source = $this->sanitizer->source($snapshot['source'], $integration);
        $reason = $this->sanitizer->clean(['reason' => $data->reason], $integration)['reason'];
        if ($this->exports->replayRecovery($actorId, $exportId, $data, $reason)) {
            return $this->exports->recoveryResult($data->requestId);
        }
        $destination = ['integration_id' => $integration->id, 'project_id' => $integration->active_collab_project_id,
            'base_url' => $integration->resolvedBaseUrl(), 'api_token' => $integration->resolvedApiToken()];
        $taskData = null;
        if ($data->action === 'replace') {
            if ($export->record_uuid !== null && ! isset($source['record_uuid'])) {
                // Source is loaded by this export ID, never adopted through a row locator.
                $source['record_uuid'] = $export->record_uuid;
            }
            $taskData = ActiveCollabTaskData::fromStoredPayload($source);
            if ($taskData->spreadsheetId !== $export->spreadsheet_id
                || $taskData->recordUuid !== $export->record_uuid
                || ($export->record_uuid === null && ($taskData->externalId !== $export->external_id
                    || $taskData->sheetId !== $export->sheet_id || $taskData->rowNumber !== $export->row_number))) {
                throw ValidationException::withMessages(['source' => 'Источник не соответствует записи. Отправьте корректные данные из Sheets.']);
            }
        }
        $started = $this->exports->beginRecovery($actorId, $exportId, $data, $source, $reason);
        if ($started['replay']) {
            return $this->exports->recoveryResult($data->requestId);
        }
        $reservation = $started['reservation'];
        $guard = fn () => $this->exports->assertRecoveryContext($actorId, $exportId, $destination);
        if ($taskData !== null) {
            $this->tasks->executeReserved($taskData, $integration, $reservation, guard: $guard);

            return $this->exports->recoveryResult($data->requestId);
        }
        $candidate = $data->candidateTaskId ?? $export->task_id;
        try {
            $guard();
            $task = null;
            if ($data->action !== 'confirm_absence') {
                $task = $this->sanitizer->clean($this->client->getTask($integration, (int) $candidate), $integration);
                if ($task['single']['is_trashed']) {
                    throw new ActiveCollabRequestException(404, category: 'remote_task_missing');
                }
            }
            if (! $this->exports->finalizeRecovery($reservation, $actorId, $integration, $task, $destination)) {
                throw new TaskSyncConflictException('sync_outcome_unknown', $reservation->operationId);
            }
        } catch (\Throwable $e) {
            $httpStatus = $e instanceof ActiveCollabRequestException ? $e->httpStatus : null;
            $missing = $httpStatus === 404 && $export->task_id !== null && $candidate === $export->task_id;
            // A failed GET never proves the outcome of the prior mutation. Keep unresolved creates blocked.
            try {
                $this->exports->complete($reservation, in_array($export->last_attempt_status, ['idle', 'failed', 'succeeded'], true) ? 'failed' : 'unknown',
                    errorCode: $missing ? 'remote_task_missing' : ($e instanceof ActiveCollabRequestException ? $e->category : 'sync_outcome_unknown'),
                    errorMessage: 'Recovery could not confirm the remote result.', httpStatus: $httpStatus);
            } catch (\Throwable) {
                // Preserve durable reservation; sweep will mark it unknown.
            }
            if ($e instanceof AuthorizationException) {
                throw $e;
            }
            throw new TaskSyncConflictException($missing ? 'remote_task_missing' : 'sync_outcome_unknown', $reservation->operationId);
        }

        return $this->exports->recoveryResult($data->requestId);
    }
}
