<?php

declare(strict_types=1);

namespace App\Repositories\ActiveCollab;

use App\Data\ActiveCollab\ActiveCollabRecoveryData;
use App\Data\ActiveCollab\ActiveCollabSyncReservation;
use App\Data\ActiveCollab\ActiveCollabTaskData;
use App\Exceptions\TaskAlreadyExistsException;
use App\Exceptions\TaskBindingConflictException;
use App\Exceptions\TaskSyncConflictException;
use App\Models\ActiveCollabIntegration;
use App\Models\ActiveCollabSyncAttempt;
use App\Models\ActiveCollabTaskExport;
use App\Models\AdminAuditLog;
use App\Models\User;
use App\Repositories\AdminAuditLogRepository;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

class ActiveCollabExportRepository
{
    public function __construct(private readonly AdminAuditLogRepository $audit = new AdminAuditLogRepository()) {}

    /** Legacy-only lookup: an ambiguous locator must never choose an arbitrary export. */
    public function findByExternalId(string $externalId, ActiveCollabIntegration $integration): ?ActiveCollabTaskExport
    {
        $this->assertIdentityMode($integration, null);
        $matches = ActiveCollabTaskExport::where('external_id', $externalId)->limit(2)->get();
        if ($matches->count() > 1) {
            throw new TaskBindingConflictException();
        }
        $export = $matches->first();
        if ($export !== null) {
            $this->assertContext($export, $integration);
        }

        return $export;
    }

    public function findByTaskId(int $taskId, ActiveCollabIntegration $integration, string $externalId): ?ActiveCollabTaskExport
    {
        $this->assertIdentityMode($integration, null);
        $matches = ActiveCollabTaskExport::where('integration_id', $integration->id)
            ->where('project_id', $integration->active_collab_project_id)->whereNull('record_uuid')
            ->where('external_id', $externalId)->limit(2)->get();
        if ($matches->count() > 1) {
            throw new TaskBindingConflictException();
        }

        $export = $matches->first();

        return $export?->task_id === $taskId ? $export : null;
    }

    private function assertIdentityMode(ActiveCollabIntegration $integration, ?string $recordUuid): void
    {
        if ((bool) $integration->uuid_only !== ($recordUuid !== null)
            || (! $integration->uuid_only && ActiveCollabTaskExport::where('integration_id', $integration->id)->whereNotNull('record_uuid')->exists())) {
            throw new TaskSyncConflictException('legacy_binding_conflict', null);
        }
    }

    /** @param array<string, mixed> $sourcePayload */
    public function reserve(ActiveCollabTaskData $data, ActiveCollabIntegration $integration, array $sourcePayload, ?int $taskId = null): ActiveCollabSyncReservation
    {
        // A first-insert collision rolls back before re-reading the winner in a new transaction.
        $retried = false;
        while (true) {
            try {
                return DB::transaction(function () use ($data, $integration, $sourcePayload, $taskId): ActiveCollabSyncReservation {
                    $fresh = ActiveCollabIntegration::whereKey($integration->id)->lockForUpdate()->first();
                    if ($fresh === null || ! $fresh->active || $fresh->spreadsheet_id !== $data->spreadsheetId
                        || $fresh->active_collab_project_id !== $integration->active_collab_project_id
                        || $fresh->resolvedBaseUrl() !== $integration->resolvedBaseUrl()
                        || ! hash_equals($fresh->resolvedApiToken(), $integration->resolvedApiToken())) {
                        throw new TaskBindingConflictException();
                    }
                    $this->assertIdentityMode($fresh, $data->recordUuid);
                    $export = $data->recordUuid !== null
                        ? ActiveCollabTaskExport::where('integration_id', $fresh->id)->where('record_uuid', $data->recordUuid)->lockForUpdate()->first()
                        : $this->findByExternalId($data->externalId, $fresh);
                    if ($export === null) {
                        if ($taskId !== null) {
                            throw new TaskBindingConflictException();
                        }
                        $export = ActiveCollabTaskExport::create([
                            'integration_id' => $integration->id, 'external_id' => $data->externalId, 'record_uuid' => $data->recordUuid,
                            'spreadsheet_id' => $data->spreadsheetId, 'sheet_id' => $data->sheetId,
                            'row_number' => $data->rowNumber, 'project_id' => $integration->active_collab_project_id,
                            'source_payload' => $sourcePayload, 'status' => 'pending',
                            'last_attempt_status' => 'idle', 'binding_status' => 'unbound',
                        ]);
                    }
                    $export = ActiveCollabTaskExport::whereKey($export->id)->lockForUpdate()->firstOrFail();
                    $this->assertContext($export, $fresh);
                    if ($export->spreadsheet_id !== $data->spreadsheetId) {
                        throw new TaskBindingConflictException();
                    }
                    $bound = $this->hasCompleteBinding($export);
                    if ($taskId === null && $bound) {
                        throw new TaskAlreadyExistsException($export->task_id, $export->task_url);
                    }
                    if ($taskId !== null) {
                        if (! $bound || $export->task_id !== $taskId) {
                            throw new TaskBindingConflictException();
                        }
                    } elseif ($export->task_id !== null || $export->task_url !== null
                        || in_array($export->status, ['created', 'updated'], true)) {
                        throw new TaskBindingConflictException();
                    }
                    if ($export->last_attempt_status === null || $export->binding_status === null) {
                        if ($export->operation_id !== null) {
                            return new ActiveCollabSyncReservation($export->id, $export->operation_id, 'sync_outcome_unknown');
                        }
                        $export->update($this->legacyState($export));
                    }
                    if ($export->last_attempt_status === 'running') {
                        if ($export->lease_expires_at === null || $export->lease_expires_at->lte(now())) {
                            $this->expireLocked($export);
                        } else {
                            return new ActiveCollabSyncReservation($export->id, $export->operation_id, 'sync_in_progress');
                        }
                    }
                    if ($export->binding_status === 'remote_missing') {
                        // A validated Sheets update can supply a fresh source for an explicit admin replacement.
                        // Never overwrite an unresolved create's operation/root cause.
                        if (in_array($export->last_attempt_status, ['idle', 'failed', 'succeeded'], true)) {
                            $reservation = $this->claim($export, $integration, $sourcePayload, 'update');
                            if ($reservation->conflictCode !== null) {
                                return $reservation;
                            }
                            $export->refresh();
                            $this->finishLocked($export, 'failed', [], 'remote_task_missing', 'Remote task requires recovery.');
                        }

                        return new ActiveCollabSyncReservation($export->id, $export->operation_id, 'remote_task_missing');
                    }
                    if (! in_array($export->last_attempt_status, ['idle', 'failed', 'succeeded'], true)) {
                        return new ActiveCollabSyncReservation($export->id, $export->operation_id, 'sync_outcome_unknown');
                    }

                    return $this->claim($export, $integration, $sourcePayload, $taskId === null ? 'create' : 'update');
                }, 3);
            } catch (UniqueConstraintViolationException $e) {
                if ($retried) {
                    throw $e;
                }
                $retried = true;
            }
        }
    }

    /** @param array<string, mixed> $payload */
    public function prepareDispatch(ActiveCollabSyncReservation $reservation, array $payload, string $hash): bool
    {
        return DB::transaction(function () use ($reservation, $payload, $hash): bool {
            $export = $this->ownedRunning($reservation);
            if ($export === null) {
                return false;
            }
            if ($export->lease_expires_at === null || $export->lease_expires_at->lte(now())) {
                $this->expireLocked($export);

                return false;
            }
            $changed = ActiveCollabSyncAttempt::where('operation_id', $reservation->operationId)
                ->where('export_id', $reservation->exportId)->where('status', 'running')
                ->update(['active_collab_payload' => $payload, 'sync_hash' => $hash]);
            if ($changed !== 1) {
                throw new \LogicException('Running attempt is missing.');
            }

            return true;
        });
    }

    /** @param array<string, mixed> $confirmed */
    public function complete(
        ActiveCollabSyncReservation $reservation,
        string $status,
        array $confirmed = [],
        ?string $errorCode = null,
        ?string $errorMessage = null,
        ?int $httpStatus = null,
        ?\Closure $guard = null,
    ): bool {
        if (! in_array($status, ['succeeded', 'failed', 'unknown'], true)) {
            throw new \InvalidArgumentException('Invalid completion status.');
        }

        return DB::transaction(function () use ($reservation, $status, $confirmed, $errorCode, $errorMessage, $httpStatus, $guard): bool {
            if ($guard !== null) {
                $this->lockIntegrationForExport($reservation->exportId);
            }
            $export = $this->ownedRunning($reservation);
            if ($export === null) {
                return false;
            }
            if ($status !== 'unknown' && ($export->lease_expires_at === null || $export->lease_expires_at->lte(now()))) {
                $this->expireLocked($export);

                return false;
            }
            if ($guard !== null) {
                $guard();
            }
            $this->finishLocked($export, $status, $confirmed, $errorCode, $errorMessage, $httpStatus);

            return true;
        });
    }

    /** @return array{classified: int, unknown: int, review: list<string>} */
    public function backfill(bool $dryRun): array
    {
        $report = ['classified' => 0, 'unknown' => 0, 'review' => []];
        ActiveCollabTaskExport::where(fn ($query) => $query->whereNull('last_attempt_status')->orWhereNull('binding_status'))
            ->chunkById(100, function ($exports) use ($dryRun, &$report): void {
                foreach ($exports as $candidate) {
                    DB::transaction(function () use ($candidate, $dryRun, &$report): void {
                        $export = ActiveCollabTaskExport::whereKey($candidate->id)->lockForUpdate()->firstOrFail();
                        if ($export->last_attempt_status !== null && $export->binding_status !== null) {
                            return;
                        }
                        // Never rewrite an operation created by a live writer, including malformed partial state.
                        if ($export->operation_id !== null) {
                            $report['review'][] = $export->id;

                            return;
                        }
                        $state = $this->legacyState($export);
                        $report['classified']++;
                        $report['unknown'] += $state['last_attempt_status'] === 'unknown' ? 1 : 0;
                        $integration = $export->integration;
                        if ($integration === null || $integration->active_collab_project_id !== $export->project_id
                            || ! $this->hasCompleteBinding($export)) {
                            $report['review'][] = $export->id;
                        }
                        if (! $dryRun) {
                            $export->update($state);
                        }
                    });
                }
            });

        return $report;
    }

    public function actor(int $actorId): User
    {
        return User::findOrFail($actorId);
    }

    public function authorizeRecovery(int $actorId): void
    {
        Gate::forUser($this->actor($actorId))->authorize('recover', ActiveCollabTaskExport::class);
    }

    /** @return array{export: ActiveCollabTaskExport, integration: ActiveCollabIntegration, source: array<string, mixed>, attempts: Collection<int, ActiveCollabSyncAttempt>, allowed: array<string, bool>} */
    public function recoverySnapshot(int $actorId, string $exportId, ?string $sourceAttemptId = null): array
    {
        $this->authorizeRecovery($actorId);
        $export = ActiveCollabTaskExport::findOrFail($exportId);
        $integration = $export->integration;
        if ($integration === null) {
            throw new TaskBindingConflictException();
        }

        return ['export' => $export, 'integration' => $integration, 'source' => $this->source($export, $sourceAttemptId),
            'attempts' => $export->attempts()->orderByDesc('started_at')->orderByDesc('id')->limit(50)->get(),
            'allowed' => $this->recoveryActions($export, $this->priorAction($export))];
    }

    /** @param array<string, mixed> $source
     * @return array{reservation: ActiveCollabSyncReservation, replay: bool}
     */
    public function beginRecovery(int $actorId, string $exportId, ActiveCollabRecoveryData $data, array $source, string $reason): array
    {
        try {
            return $this->reserveRecovery($actorId, $exportId, $data, $source, $reason);
        } catch (UniqueConstraintViolationException) {
            throw new TaskSyncConflictException('recovery_conflict', $data->expectedOperationId);
        }
    }

    /** @param array<string, mixed> $source
     * @return array{reservation: ActiveCollabSyncReservation, replay: bool}
     */
    private function reserveRecovery(int $actorId, string $exportId, ActiveCollabRecoveryData $data, array $source, string $reason): array
    {
        return DB::transaction(function () use ($actorId, $exportId, $data, $source, $reason): array {
            $this->authorizeRecovery($actorId);
            $this->lockIntegrationForExport($exportId);
            $export = ActiveCollabTaskExport::whereKey($exportId)->lockForUpdate()->firstOrFail();
            $prior = $this->audit->requested($data->requestId);
            if ($prior !== null) {
                $this->assertReplay($prior, $actorId, $exportId, $data, $reason);

                return ['reservation' => new ActiveCollabSyncReservation($exportId, $prior->operation_id), 'replay' => true];
            }
            $integration = ActiveCollabIntegration::whereKey($export->integration_id)->lockForUpdate()->first();
            if ($integration === null || ! $integration->active) {
                throw new TaskBindingConflictException();
            }
            $this->assertContext($export, $integration);
            $this->source($export, $data->sourceAttemptId);
            if ($export->operation_id !== $data->expectedOperationId || $export->last_attempt_status !== $data->expectedStatus
                || $export->binding_status !== $data->expectedBinding) {
                throw new TaskSyncConflictException('recovery_conflict', $export->operation_id);
            }
            if ($export->last_attempt_status === 'running') {
                if ($export->lease_expires_at !== null && $export->lease_expires_at->gt(now())) {
                    throw new TaskSyncConflictException('sync_in_progress', $export->operation_id);
                }
                $this->expireLocked($export);
            }
            $priorAction = $this->priorAction($export);
            $allowed = $this->recoveryActions($export, $priorAction)[$data->action];
            if (! $allowed) {
                throw new TaskSyncConflictException('recovery_conflict', $export->operation_id);
            }
            $before = clone $export;
            // Preserve the submitted pre-expiry state for replay comparisons.
            $before->operation_id = $data->expectedOperationId;
            $before->last_attempt_status = $data->expectedStatus;
            $before->binding_status = $data->expectedBinding;
            $operationId = (string) Str::uuid();
            $export->update(['operation_id' => $operationId, 'last_attempt_status' => 'running',
                'lease_expires_at' => now()->addSeconds($this->leaseSeconds())]);
            ActiveCollabSyncAttempt::create([
                'export_id' => $exportId, 'integration_id' => $integration->id, 'operation_id' => $operationId,
                'action' => $data->action === 'replace' ? 'create' : 'reconcile', 'status' => 'running',
                'started_at' => now(), 'source_payload' => $source,
            ]);
            $this->audit->request($data->requestId, $actorId, $data->action, $before, $export, [
                'operation_id' => $operationId, 'reason' => $reason, 'project_id' => $export->project_id,
                'candidate_task_id' => $data->candidateTaskId, 'source_attempt_id' => $data->sourceAttemptId,
                'prior_action' => $priorAction,
            ]);

            return ['reservation' => new ActiveCollabSyncReservation($exportId, $operationId), 'replay' => false];
        }, 3);
    }

    public function replayRecovery(int $actorId, string $exportId, ActiveCollabRecoveryData $data, string $reason): bool
    {
        $this->authorizeRecovery($actorId);
        $prior = $this->audit->requested($data->requestId);
        if ($prior === null) {
            return false;
        }
        $this->assertReplay($prior, $actorId, $exportId, $data, $reason);

        return true;
    }

    private function assertReplay(AdminAuditLog $prior, int $actorId, string $exportId, ActiveCollabRecoveryData $data, string $reason): void
    {
        if ($prior->actor_id !== $actorId || $prior->subject_id !== $exportId || $prior->action !== $data->action
                            || ($prior->context['candidate_task_id'] ?? null) !== $data->candidateTaskId
                            || ($prior->context['source_attempt_id'] ?? null) !== $data->sourceAttemptId
                            || ($prior->context['reason'] ?? null) !== $reason
                            || ($prior->before['operation_id'] ?? null) !== $data->expectedOperationId
                            || ($prior->before['last_attempt_status'] ?? null) !== $data->expectedStatus
                            || ($prior->before['binding_status'] ?? null) !== $data->expectedBinding) {
            throw new TaskSyncConflictException('recovery_conflict', $data->expectedOperationId);
        }
    }

    /** @return array<string, mixed> */
    public function recoveryResult(string $requestId): array
    {
        $result = $this->audit->result($requestId);
        if ($result === null) {
            $request = $this->audit->requested($requestId);
            throw new TaskSyncConflictException('sync_in_progress', $request?->operation_id);
        }

        return ['operation_id' => $result->operation_id, 'status' => $result->context['outcome']];
    }

    /** @param array{integration_id: string, project_id: int, base_url: string, api_token: string} $destination */
    public function assertRecoveryContext(int $actorId, string $exportId, array $destination): void
    {
        $this->authorizeRecovery($actorId);
        $export = ActiveCollabTaskExport::findOrFail($exportId);
        $integration = ActiveCollabIntegration::whereKey($export->integration_id)->lockForUpdate()->first();
        if ($integration === null || ! $integration->active || $integration->id !== $destination['integration_id']
            || $integration->active_collab_project_id !== $destination['project_id']
            || $export->project_id !== $destination['project_id']
            || $integration->resolvedBaseUrl() !== $destination['base_url']
            || ! hash_equals($destination['api_token'], $integration->resolvedApiToken())) {
            throw new TaskSyncConflictException('recovery_conflict', $export->operation_id);
        }
    }

    /** @param array<string, mixed>|null $task
     * @param  array{integration_id: string, project_id: int, base_url: string, api_token: string}  $destination
     */
    public function finalizeRecovery(ActiveCollabSyncReservation $reservation, int $actorId, ActiveCollabIntegration $snapshot, ?array $task, array $destination): bool
    {
        return DB::transaction(function () use ($reservation, $actorId, $snapshot, $task, $destination): bool {
            $this->lockIntegrationForExport($reservation->exportId);
            $export = $this->ownedRunning($reservation);
            if ($export === null) {
                return false;
            }
            if ($export->lease_expires_at === null || $export->lease_expires_at->lte(now())) {
                $this->expireLocked($export);

                return false;
            }
            $this->assertRecoveryContext($actorId, $export->id, $destination);
            $changes = ['sync_hash' => null];
            if ($task === null && $export->task_id === null) {
                $changes += ['binding_status' => 'unbound', 'task_url' => null];
            }
            if ($task !== null) {
                $sameTask = $export->task_id === $task['single']['id'];
                $changes += ['binding_status' => 'bound', 'task_id' => $task['single']['id'],
                    'task_url' => $snapshot->resolvedInstanceUrl().$task['single']['url_path']];
                if (! $sameTask) {
                    $changes += ['active_collab_payload' => null, 'active_collab_response' => null, 'synced_at' => null];
                }
            }
            $export->update($changes);
            $this->finishLocked($export, 'succeeded', [], null, null);

            return true;
        });
    }

    /** @return array{expired: int, review: list<string>} */
    public function expireOperations(bool $dryRun): array
    {
        $report = ['expired' => 0, 'review' => []];
        ActiveCollabTaskExport::where('last_attempt_status', 'running')
            ->where(fn ($query) => $query->whereNull('lease_expires_at')->orWhere('lease_expires_at', '<=', now()))
            ->chunkById(100, function ($exports) use ($dryRun, &$report): void {
                foreach ($exports as $candidate) {
                    DB::transaction(function () use ($candidate, $dryRun, &$report): void {
                        $export = ActiveCollabTaskExport::whereKey($candidate->id)->lockForUpdate()->firstOrFail();
                        if ($export->last_attempt_status !== 'running' || ($export->lease_expires_at !== null && $export->lease_expires_at->gt(now()))) {
                            return;
                        }
                        $report['expired']++;
                        if (! ActiveCollabSyncAttempt::where('export_id', $export->id)->where('operation_id', $export->operation_id)->where('status', 'running')->exists()) {
                            $report['review'][] = $export->id;
                        }
                        if (! $dryRun) {
                            $this->expireLocked($export);
                        }
                    });
                }
            });

        return $report;
    }

    private function expireLocked(ActiveCollabTaskExport $export): void
    {
        $attempt = ActiveCollabSyncAttempt::where('export_id', $export->id)->where('operation_id', $export->operation_id)->where('status', 'running')->first();
        if ($attempt !== null) {
            $this->finishLocked($export, 'unknown', [], 'sync_outcome_unknown', 'Operation lease expired.');
        } else {
            $export->update(['last_attempt_status' => 'unknown', 'lease_expires_at' => null, 'status' => 'failed', 'error_message' => 'Operation history requires review.']);
        }
    }

    /** @return array<string, mixed> */
    private function source(ActiveCollabTaskExport $export, ?string $sourceAttemptId): array
    {
        if ($sourceAttemptId === null) {
            return $export->source_payload;
        }

        return ActiveCollabSyncAttempt::where('export_id', $export->id)->whereKey($sourceAttemptId)->firstOrFail()->source_payload;
    }

    private function priorAction(ActiveCollabTaskExport $export): ?string
    {
        $attempt = ActiveCollabSyncAttempt::where('export_id', $export->id)->where('operation_id', $export->operation_id)->first();
        if ($attempt?->action === 'reconcile' && $export->operation_id !== null) {
            return $this->audit->forOperation($export->operation_id)?->context['prior_action'] ?? 'reconcile';
        }

        return $attempt?->action;
    }

    /** @return array<string, bool> */
    private function recoveryActions(ActiveCollabTaskExport $export, ?string $priorAction): array
    {
        $live = $export->last_attempt_status === 'running' && $export->lease_expires_at !== null && $export->lease_expires_at->gt(now());
        $unknown = $export->last_attempt_status === 'unknown' || ($export->last_attempt_status === 'running' && ! $live);
        $missing = $export->binding_status === 'remote_missing';

        return [
            'bind' => ! $live && ($unknown || $missing),
            'verify' => ! $live && ($unknown || $missing) && $this->hasCompleteBinding($export),
            'confirm_absence' => ! $live && $unknown && ($export->task_id === null || ($missing && $priorAction === 'create')),
            'replace' => ! $live && $missing && ! $unknown && $this->hasCompleteBinding($export),
        ];
    }

    /** @param array<string, mixed> $sourcePayload */
    private function claim(ActiveCollabTaskExport $export, ActiveCollabIntegration $integration, array $sourcePayload, string $action): ActiveCollabSyncReservation
    {
        $operationId = (string) Str::uuid();
        $changed = ActiveCollabTaskExport::whereKey($export->id)
            ->where('last_attempt_status', $export->last_attempt_status)
            ->where('operation_id', $export->operation_id)
            ->update([
                'external_id' => $sourcePayload['external_id'], 'sheet_id' => $sourcePayload['sheet_id'], 'row_number' => $sourcePayload['row_number'],
                'operation_id' => $operationId, 'last_attempt_status' => 'running',
                'lease_expires_at' => now()->addSeconds($this->leaseSeconds()),
            ]);
        if ($changed !== 1) {
            return new ActiveCollabSyncReservation($export->id, $export->operation_id, 'sync_in_progress');
        }
        ActiveCollabSyncAttempt::create([
            'export_id' => $export->id, 'integration_id' => $integration->id,
            'operation_id' => $operationId, 'action' => $action,
            'status' => 'running', 'started_at' => now(), 'source_payload' => $sourcePayload,
        ]);

        return new ActiveCollabSyncReservation($export->id, $operationId);
    }

    private function lockIntegrationForExport(string $exportId): void
    {
        $export = ActiveCollabTaskExport::findOrFail($exportId);
        ActiveCollabIntegration::whereKey($export->integration_id)->lockForUpdate()->first();
    }

    private function ownedRunning(ActiveCollabSyncReservation $reservation): ?ActiveCollabTaskExport
    {
        return ActiveCollabTaskExport::whereKey($reservation->exportId)
            ->where('operation_id', $reservation->operationId)->where('last_attempt_status', 'running')
            ->lockForUpdate()->first();
    }

    /** @return array{last_attempt_status: string, binding_status: string} */
    private function legacyState(ActiveCollabTaskExport $export): array
    {
        $bound = $this->hasCompleteBinding($export);

        return [
            'last_attempt_status' => $bound && in_array($export->status, ['created', 'updated'], true) ? 'succeeded' : 'unknown',
            'binding_status' => $bound ? 'bound' : 'unbound',
        ];
    }

    private function assertContext(ActiveCollabTaskExport $export, ActiveCollabIntegration $integration): void
    {
        if ($export->integration_id !== $integration->id || $export->project_id !== $integration->active_collab_project_id) {
            throw new TaskBindingConflictException();
        }
    }

    private function hasCompleteBinding(ActiveCollabTaskExport $export): bool
    {
        return $export->task_id !== null && $export->task_id > 0
            && $export->task_url !== null && trim($export->task_url) !== '';
    }

    private function leaseSeconds(): int
    {
        $timeout = (int) config('services.active_collab.timeout', 30);
        $lease = (int) config('services.active_collab.lease_seconds', 120);
        if ($timeout < 1 || $lease <= $timeout) {
            throw new \LogicException('Lease must exceed the HTTP timeout.');
        }

        return $lease;
    }

    /** @param array<string, mixed> $confirmed */
    private function finishLocked(ActiveCollabTaskExport $export, string $status, array $confirmed, ?string $errorCode, ?string $errorMessage, ?int $httpStatus = null): void
    {
        $attempt = ActiveCollabSyncAttempt::where('export_id', $export->id)
            ->where('operation_id', $export->operation_id)->where('status', 'running')->first();
        if ($attempt === null) {
            throw new \LogicException('Running attempt is missing.');
        }
        $changes = ['last_attempt_status' => $status, 'lease_expires_at' => null, 'error_message' => $errorMessage];
        if ($status === 'succeeded' && $attempt->action !== 'reconcile') {
            $changes += $confirmed;
            $changes['status'] = $attempt->action === 'create' ? 'created' : 'updated';
            $changes['binding_status'] = 'bound';
            $changes['synced_at'] = now();
            $attempt->active_collab_response = $confirmed['active_collab_response'];
        } elseif ($status !== 'succeeded') {
            $changes['status'] = 'failed';
        }
        if ($errorCode === 'remote_task_missing') {
            $changes['binding_status'] = 'remote_missing';
        }
        $export->update($changes);
        $attempt->fill([
            'status' => $status, 'finished_at' => now(), 'http_status' => $httpStatus,
            'error_code' => $errorCode, 'error_message' => $errorMessage,
        ])->save();
        $audit = $this->audit->forOperation((string) $export->operation_id);
        if ($audit !== null && $this->audit->result($audit->request_id) === null) {
            $this->audit->finish($audit, $export, $status);
        }
    }
}
