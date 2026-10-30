<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Models\ActiveCollabIntegration;
use App\Models\ActiveCollabTaskExport;
use App\Models\AdminAuditLog;
use App\Services\ActiveCollab\ActiveCollabDataSanitizer;
use Illuminate\Support\Str;

class AdminAuditLogRepository
{
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

    /** @return array<string, mixed> */
    private function snapshot(ActiveCollabTaskExport $export): array
    {
        $values = $export->only(['task_id', 'task_url', 'last_attempt_status', 'binding_status', 'operation_id']);
        $integration = $export->integration;

        return $integration === null ? array_diff_key($values, ['task_url' => true])
            : (new ActiveCollabDataSanitizer())->clean($values, $integration);
    }
}
