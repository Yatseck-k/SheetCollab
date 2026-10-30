<?php

declare(strict_types=1);

namespace App\Repositories\ActiveCollab;

use App\Data\ActiveCollab\ActiveCollabRecordUuidManifest;
use App\Models\ActiveCollabIntegration;
use App\Models\ActiveCollabTaskExport;
use App\Models\User;
use App\Repositories\AdminAuditLogRepository;
use App\Services\ActiveCollab\ActiveCollabDataSanitizer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

class ActiveCollabRecordUuidMigrationRepository
{
    public function __construct(private readonly AdminAuditLogRepository $audit, private readonly ActiveCollabDataSanitizer $sanitizer) {}

    /** @return array<string, mixed> */
    public function migrate(string $integrationId, ActiveCollabRecordUuidManifest $manifest, int $actorId, string $reason, bool $dryRun, bool $enable): array
    {
        return DB::transaction(function () use ($integrationId, $manifest, $actorId, $reason, $dryRun, $enable): array {
            $integration = ActiveCollabIntegration::whereKey($integrationId)->lockForUpdate()->firstOrFail();
            Gate::forUser(User::findOrFail($actorId))->authorize('recover', ActiveCollabTaskExport::class);
            $exports = ActiveCollabTaskExport::where('integration_id', $integrationId)->orderBy('id')->lockForUpdate()->get();
            $orphans = ActiveCollabTaskExport::whereNull('integration_id')->orderBy('id')->get(['id', 'spreadsheet_id']);
            $report = ['planned' => [], 'already_bound' => [], 'unresolved' => [], 'orphaned' => $orphans->modelKeys(),
                'conflicts' => [], 'uuid_only' => (bool) $integration->uuid_only, 'applied' => false];
            if ($integration->active) {
                $report['conflicts'][] = 'integration_must_be_inactive';
            }
            if ($integration->spreadsheet_id !== $manifest->spreadsheetId) {
                $report['conflicts'][] = 'spreadsheet_mismatch';
            }
            if ($orphans->contains('spreadsheet_id', $integration->spreadsheet_id)) {
                $report['conflicts'][] = 'orphaned_history_requires_review';
            }
            foreach (ActiveCollabTaskExport::where('spreadsheet_id', $integration->spreadsheet_id)
                ->whereNotNull('integration_id')->where('integration_id', '!=', $integrationId)->pluck('id') as $id) {
                $report['conflicts'][] = 'foreign_history:'.$id;
            }
            $byTask = $exports->groupBy('task_id');
            $byUuid = $exports->whereNotNull('record_uuid')->keyBy('record_uuid');
            $seenExports = [];
            $seenPositions = [];
            $changes = [];
            foreach ($manifest->records as $record) {
                $position = $record['sheet_id'].':'.$record['row_number'];
                if (isset($seenPositions[$position])) {
                    $report['conflicts'][] = 'duplicate_position:'.$record['record_uuid'];
                }
                $seenPositions[$position] = true;
                if ($record['task_id'] === null && $record['task_url'] === null) {
                    continue; // New unsent row: no export and no external side effect.
                }
                $candidates = $byTask->get($record['task_id'], collect());
                $export = $candidates->count() === 1 ? $candidates->first() : null;
                if ($export === null || ! $this->confirmedLink($integration, $export, $record['task_id'], $record['task_url'])
                    || isset($seenExports[$export->id])
                    || ($export->record_uuid !== null && $export->record_uuid !== $record['record_uuid'])
                    || (isset($byUuid[$record['record_uuid']]) && $byUuid[$record['record_uuid']]->id !== $export->id)) {
                    $report['conflicts'][] = 'task_binding_conflict:'.$record['record_uuid'];

                    continue;
                }
                $seenExports[$export->id] = true;
                $after = ['record_uuid' => $record['record_uuid'], 'sheet_id' => $record['sheet_id'], 'row_number' => $record['row_number'],
                    'external_id' => $manifest->spreadsheetId.':'.$position];
                if ($export->only(array_keys($after)) === $after) {
                    $report['already_bound'][] = $export->id;
                } else {
                    $report['planned'][] = $export->id;
                    $changes[$export->id] = $after;
                }
            }
            foreach ($exports as $export) {
                if (! isset($seenExports[$export->id])) {
                    $report['unresolved'][] = $export->id;
                }
                if ($export->last_attempt_status === 'running') {
                    $report['conflicts'][] = 'running_operation:'.$export->id;
                }
            }
            if ($dryRun || $report['conflicts'] !== [] || $report['unresolved'] !== []) {
                return $report;
            }
            $reason = $this->sanitizer->clean(['reason' => $reason], $integration)['reason'];
            $batchId = (string) Str::uuid();
            foreach ($exports as $export) {
                if (! isset($changes[$export->id])) {
                    continue;
                }
                $after = $changes[$export->id];
                $before = $export->only(array_keys($after));
                $export->update($after);
                $this->audit->identityMutation($actorId, 'active_collab_task_export', $export->id, $integration,
                    'record_uuid_bound', $batchId, $before, $after, $reason);
            }
            if ($enable && ! $integration->uuid_only) {
                $integration->forceFill(['uuid_only' => true])->save();
                $this->audit->identityMutation($actorId, 'active_collab_integration', $integration->id, $integration,
                    'record_uuid_enabled', $batchId, ['uuid_only' => false], ['uuid_only' => true], $reason);
            }
            $report['uuid_only'] = (bool) $integration->uuid_only;
            $report['applied'] = true;

            return $report;
        }, 3);
    }

    private function confirmedLink(ActiveCollabIntegration $integration, ActiveCollabTaskExport $export, ?int $taskId, ?string $url): bool
    {
        if ($taskId === null || $url === null || $export->task_url === null || $export->task_id !== $taskId
            || $export->project_id !== $integration->active_collab_project_id || $export->spreadsheet_id !== $integration->spreadsheet_id) {
            return false;
        }
        // Match the service's exact task URL; queries, redirects and partial links require manual review.
        $expected = $integration->resolvedInstanceUrl().'/projects/'.$export->project_id.'/tasks/'.$taskId;

        return rtrim($url, '/') === $expected && rtrim($export->task_url, '/') === $expected;
    }
}
