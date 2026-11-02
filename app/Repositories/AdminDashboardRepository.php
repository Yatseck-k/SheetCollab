<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Models\ActiveCollabIntegration;
use App\Models\ActiveCollabSyncAttempt;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class AdminDashboardRepository
{
    /**
     * @return array{
     *     summary: array<string, int>,
     *     integrations: Collection<int, ActiveCollabIntegration>,
     *     recent_attempts: Collection<int, ActiveCollabSyncAttempt>
     * }
     */
    public function forUser(int|string $userId, bool $canManageConnections): array
    {
        $integrations = ActiveCollabIntegration::query()
            ->when(! $canManageConnections, fn ($query) => $query->whereHas(
                'users', fn ($users) => $users->whereKey($userId),
            ))
            ->orderBy('name')
            ->get(['id', 'name', 'active', 'active_collab_project_id']);
        $integrationIds = $integrations->modelKeys();

        $summary = [
            'integrations' => $integrations->count(),
            'active_integrations' => $integrations->where('active', true)->count(),
            'bound' => 0,
            'created' => 0,
            'updated' => 0,
            'running' => 0,
            'unknown' => 0,
            'failed' => 0,
            'remote_missing' => 0,
        ];

        if ($integrationIds !== []) {
            $exportCounts = DB::table('active_collab_task_exports')
                ->whereIn('integration_id', $integrationIds)
                ->selectRaw("COALESCE(SUM(CASE WHEN binding_status = 'bound' THEN 1 ELSE 0 END), 0) AS bound,
                    COALESCE(SUM(CASE WHEN last_attempt_status = 'running' THEN 1 ELSE 0 END), 0) AS running,
                    COALESCE(SUM(CASE WHEN last_attempt_status = 'unknown' THEN 1 ELSE 0 END), 0) AS unknown,
                    COALESCE(SUM(CASE WHEN last_attempt_status = 'failed' THEN 1 ELSE 0 END), 0) AS failed,
                    COALESCE(SUM(CASE WHEN binding_status = 'remote_missing' THEN 1 ELSE 0 END), 0) AS remote_missing")
                ->first();

            foreach (['bound', 'running', 'unknown', 'failed', 'remote_missing'] as $key) {
                $summary[$key] = (int) $exportCounts->{$key};
            }

            $attemptCounts = DB::table('active_collab_sync_attempts')
                ->whereIn('integration_id', $integrationIds)
                ->selectRaw('action, status, COUNT(*) AS aggregate')
                ->groupBy('action', 'status')
                ->get();

            foreach ($attemptCounts as $count) {
                if ($count->status === 'succeeded' && $count->action === 'create') {
                    $summary['created'] = (int) $count->aggregate;
                } elseif ($count->status === 'succeeded' && $count->action === 'update') {
                    $summary['updated'] = (int) $count->aggregate;
                }
            }
        }

        $recentAttempts = ActiveCollabSyncAttempt::query()
            ->with(['export:id,external_id,integration_id', 'export.integration:id,name'])
            ->whereIn('integration_id', $integrationIds)
            ->orderByDesc('started_at')
            ->limit(10)
            ->get(['id', 'export_id', 'integration_id', 'action', 'status', 'started_at']);

        return ['summary' => $summary, 'integrations' => $integrations, 'recent_attempts' => $recentAttempts];
    }
}
