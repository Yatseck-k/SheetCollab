<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Models\ActiveCollabConnection;
use App\Models\ActiveCollabIntegration;
use App\Models\ActiveCollabTaskExport;
use App\Models\AdminAuditLog;
use App\Services\ActiveCollab\ActiveCollabDataSanitizer;
use Illuminate\Support\Str;

class AdminAuditLogRepository
{
    /**
     * Persist an allowlisted administrative change. Call inside the same transaction as the mutation.
     *
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     * @param  list<string>  $extraSecrets
     */
    public function recordMutation(
        int $actorId,
        string $subjectType,
        string $subjectId,
        string $action,
        array $before,
        array $after,
        ?string $integrationId = null,
        ?string $connectionId = null,
        array $extraSecrets = [],
    ): void {
        $allowed = match ($subjectType) {
            'active_collab_integration' => ['name', 'spreadsheet_id', 'connection_id', 'active_collab_project_id',
                'active_collab_task_list_id', 'task_list_mapping', 'assignee_mapping', 'label_mapping', 'active'],
            'active_collab_connection' => ['name', 'base_url', 'active', 'credential_changed'],
            'user' => ['user_id', 'role_ids', 'permission_slugs', 'integration_ids'],
            'role' => ['role_id', 'name', 'slug', 'permission_slugs'],
            default => [],
        };
        $before = array_intersect_key($before, array_flip($allowed));
        $after = array_intersect_key($after, array_flip($allowed));
        $secrets = array_values(array_filter([
            ...$extraSecrets,
            (string) config('services.active_collab.api_token'),
            (string) config('services.google_sheets.integration_token'),
        ], static fn (string $secret): bool => $secret !== ''));
        if ($connectionId !== null) {
            $connection = ActiveCollabConnection::query()->find($connectionId);
            if ($connection !== null) {
                try {
                    $secrets[] = (string) $connection->api_token;
                } catch (\Throwable) { // @phpstan-ignore catch.neverThrown (Encrypted Eloquent cast may fail at runtime.)
                    // A broken encrypted value cannot be safely used for replacement.
                }
            }
        }
        if ($integrationId !== null) {
            $integration = ActiveCollabIntegration::query()->find($integrationId);
            if ($integration !== null) {
                try {
                    $secrets[] = $integration->resolvedApiToken();
                } catch (\LogicException) {
                    // The audit snapshot remains allowlisted and never contains credential columns.
                }
                try {
                    $secrets[] = (string) $integration->active_collab_api_token;
                } catch (\Throwable) { // @phpstan-ignore catch.neverThrown (Encrypted legacy cast may fail at runtime.)
                    // Legacy encrypted token could not be read.
                }
            }
        }
        if (in_array($subjectType, ['user', 'role'], true)) {
            foreach (ActiveCollabConnection::query()->get(['id', 'api_token']) as $knownConnection) {
                try {
                    $secrets[] = (string) $knownConnection->api_token;
                } catch (\Throwable) { // @phpstan-ignore catch.neverThrown (Encrypted Eloquent cast may fail at runtime.)
                    // Ignore unreadable credentials; never serialize them.
                }
            }
            foreach (ActiveCollabIntegration::query()->whereNotNull('active_collab_api_token')->get() as $legacyIntegration) {
                try {
                    $secrets[] = (string) $legacyIntegration->active_collab_api_token;
                } catch (\Throwable) { // @phpstan-ignore catch.neverThrown (Encrypted legacy cast may fail at runtime.)
                    // Ignore unreadable credentials; never serialize them.
                }
            }
        }
        $before = $this->redact($before, $secrets);
        $after = $this->redact($after, $secrets);

        AdminAuditLog::create([
            'actor_id' => $actorId,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'integration_id' => $integrationId,
            'connection_id' => $connectionId,
            'action' => $action,
            'request_id' => (string) Str::uuid(),
            'operation_id' => (string) Str::uuid(),
            'phase' => 'result',
            'before' => $before,
            'after' => $after,
            'context' => [],
            'created_at' => now(),
        ]);
    }

    public function requested(string $requestId): ?AdminAuditLog
    {
        return AdminAuditLog::where('request_id', $requestId)->where('phase', 'requested')->first();
    }

    public function forOperation(string $operationId): ?AdminAuditLog
    {
        return AdminAuditLog::where('phase', 'requested')->where('operation_id', $operationId)->first();
    }

    public function result(string $requestId): ?AdminAuditLog
    {
        return AdminAuditLog::where('request_id', $requestId)->where('phase', 'result')->first();
    }

    /** @param array<string, mixed> $context */
    public function request(string $requestId, int $actorId, string $action, ActiveCollabTaskExport $before, ActiveCollabTaskExport $after, array $context): void
    {
        AdminAuditLog::create([
            'actor_id' => $actorId, 'subject_type' => 'active_collab_task_export', 'subject_id' => $after->id,
            'integration_id' => $after->integration_id, 'action' => $action,
            'request_id' => $requestId, 'operation_id' => $context['operation_id'], 'phase' => 'requested',
            'before' => $this->snapshot($before), 'after' => $this->snapshot($after),
            'context' => array_intersect_key($context, array_flip([
                'operation_id', 'reason', 'project_id', 'candidate_task_id', 'source_attempt_id', 'prior_action',
            ])), 'created_at' => now(),
        ]);
    }

    public function finish(AdminAuditLog $request, ActiveCollabTaskExport $after, string $outcome): void
    {
        AdminAuditLog::create([
            'actor_id' => $request->actor_id, 'subject_type' => $request->subject_type,
            'subject_id' => $request->subject_id, 'integration_id' => $request->integration_id,
            'action' => $request->action, 'request_id' => $request->request_id, 'operation_id' => $request->operation_id, 'phase' => 'result',
            'before' => $request->before, 'after' => $this->snapshot($after),
            'context' => ['operation_id' => $request->context['operation_id'], 'outcome' => $outcome],
            'created_at' => now(),
        ]);
    }

    /** @param array<string, mixed> $before
     * @param  array<string, mixed>  $after
     */
    public function identityMutation(int $actorId, string $subjectType, string $subjectId, ActiveCollabIntegration $integration,
        string $action, string $batchId, array $before, array $after, string $reason): void
    {
        AdminAuditLog::create([
            'actor_id' => $actorId, 'subject_type' => $subjectType, 'subject_id' => $subjectId,
            'integration_id' => $integration->id, 'action' => $action, 'request_id' => (string) Str::uuid(),
            'operation_id' => $batchId, 'phase' => 'result',
            'before' => (new ActiveCollabDataSanitizer())->clean($before, $integration),
            'after' => (new ActiveCollabDataSanitizer())->clean($after, $integration),
            'context' => ['reason' => $reason, 'project_id' => $integration->active_collab_project_id], 'created_at' => now(),
        ]);
    }

    /** @param array<string, mixed> $before
     * @param  array<string, mixed>  $after
     */
    public function accessMutation(int $actorId, ActiveCollabIntegration $integration, string $action, array $before, array $after, string $reason): void
    {
        $clean = new ActiveCollabDataSanitizer();
        $values = $clean->clean(['before' => $before, 'after' => $after, 'reason' => $reason], $integration);
        AdminAuditLog::create([
            'actor_id' => $actorId, 'subject_type' => 'active_collab_integration', 'subject_id' => $integration->id,
            'integration_id' => $integration->id, 'action' => 'integration_access_'.$action,
            'request_id' => (string) Str::uuid(), 'operation_id' => (string) Str::uuid(), 'phase' => 'result',
            'before' => $values['before'], 'after' => $values['after'],
            'context' => ['reason' => $values['reason']], 'created_at' => now(),
        ]);
    }

    /** @return array<string, mixed> */
    private function snapshot(ActiveCollabTaskExport $export): array
    {
        $values = $export->only(['task_id', 'task_url', 'last_attempt_status', 'binding_status', 'operation_id']);
        $integration = $export->integration;

        return $integration === null ? array_diff_key($values, ['task_url' => true])
            : (new ActiveCollabDataSanitizer())->clean($values, $integration);
    }

    /** @param array<string, mixed> $values
     * @param  list<string>  $secrets
     * @return array<string, mixed>
     */
    private function redact(array $values, array $secrets): array
    {
        $secrets = array_values(array_unique(array_filter($secrets, static fn (string $secret): bool => $secret !== '')));
        array_walk_recursive($values, static function (&$value) use ($secrets): void {
            if (is_string($value)) {
                $value = str_replace($secrets, '[REDACTED]', $value);
            }
        });

        return $values;
    }
}
