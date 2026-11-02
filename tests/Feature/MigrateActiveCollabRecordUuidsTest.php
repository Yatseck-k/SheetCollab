<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Data\ActiveCollab\ActiveCollabRecordUuidManifest;
use App\Models\ActiveCollabIntegration;
use App\Models\ActiveCollabTaskExport;
use App\Models\AdminAuditLog;
use App\Models\User;
use App\Services\ActiveCollab\ActiveCollabRecordUuidMigrationService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\ActiveCollabIntegrationFixture;
use Tests\TestCase;

class MigrateActiveCollabRecordUuidsTest extends TestCase
{
    use RefreshDatabase;

    private ActiveCollabIntegration $integration;

    private User $actor;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('app.key', 'base64:J63P4m4KjJ8l0RkGxJZ4Kf9P9+K6f2R4R6V4a2M1LxQ=');
        Http::preventStrayRequests();
        Http::fake();
        $this->integration = ActiveCollabIntegrationFixture::create([
            'name' => 'UUID migration', 'spreadsheet_id' => 'sheet', 'active_collab_base_url' => 'https://ac.example.test/api/v1',
            'active_collab_project_id' => 10, 'active_collab_task_list_id' => 31, 'active_collab_api_token' => 'private-ac-token', 'active' => false,
        ]);
        $this->actor = User::factory()->create(['permissions' => [
            'platform.activecollab.recovery' => true, 'platform.systems.users' => true, 'platform.systems.roles' => true,
        ]]);
    }

    public function test_dry_run_apply_and_replay_match_task_links_even_when_positions_are_swapped(): void
    {
        $first = $this->export(123, 8);
        $second = $this->export(456, 9);
        $first->update(['last_attempt_status' => 'unknown', 'operation_id' => (string) Str::uuid(), 'sync_hash' => 'old-hash', 'synced_at' => now()->subDay()]);
        $before = $first->fresh()->only(['source_payload', 'operation_id', 'last_attempt_status', 'sync_hash', 'synced_at']);
        $manifest = [$this->record(123, 9), $this->record(456, 8)];
        $dry = $this->runMigration($manifest, dryRun: true);
        $this->assertCount(2, $dry['planned']);
        $this->assertNull($first->fresh()->record_uuid);
        $this->assertDatabaseCount('admin_audit_logs', 0);
        $report = $this->runMigration($manifest);
        $this->assertTrue($report['applied']);
        $this->assertTrue($report['uuid_only']);
        $this->assertSame(9, $first->fresh()->row_number);
        $this->assertSame(8, $second->fresh()->row_number);
        $this->assertEquals($before, $first->fresh()->only(array_keys($before)));
        $this->assertDatabaseCount('active_collab_sync_attempts', 0);
        $this->assertDatabaseCount('admin_audit_logs', 3);
        $replay = $this->runMigration($manifest);
        $this->assertSame([], $replay['planned']);
        $this->assertCount(2, $replay['already_bound']);
        $this->assertDatabaseCount('admin_audit_logs', 3);
        Http::assertNothingSent();
    }

    public function test_binding_and_enabling_can_be_separate_and_empty_integration_can_be_enabled(): void
    {
        $this->export(123, 8);
        $records = [$this->record(123, 8), $this->record(null, 19)];
        $this->assertFalse($this->runMigration($records, enable: false)['uuid_only']);
        $this->assertDatabaseCount('active_collab_task_exports', 1);
        $this->assertTrue($this->runMigration($records)['uuid_only']);
        $this->assertDatabaseCount('admin_audit_logs', 2);
        $this->integration->update(['spreadsheet_id' => 'retired-sheet']);
        $this->integration = ActiveCollabIntegrationFixture::create([
            'name' => 'Empty', 'spreadsheet_id' => 'sheet', 'active_collab_base_url' => 'https://ac.example.test/api/v1',
            'active_collab_project_id' => 10, 'active_collab_task_list_id' => 31, 'active_collab_api_token' => 'private-ac-token', 'active' => false,
        ]);
        $this->assertFalse($this->runMigration([])['uuid_only']); // Foreign history for this spreadsheet must be reviewed.
    }

    public function test_empty_new_integration_is_enabled_without_creating_exports(): void
    {
        $this->assertTrue($this->runMigration([])['uuid_only']);
        $this->assertDatabaseCount('active_collab_task_exports', 0);
        $this->assertDatabaseCount('admin_audit_logs', 1);
    }

    #[DataProvider('blockers')]
    public function test_ambiguous_or_unsafe_history_prevents_all_mutation(string $case): void
    {
        $export = $this->export(123, 8);
        $records = [$this->record(123, 8)];
        switch ($case) {
            case 'active': $this->integration->update(['active' => true]);
                break;
            case 'running': $export->update(['last_attempt_status' => 'running', 'lease_expires_at' => now()->subDay()]);
                break;
            case 'unbound': $export->update(['task_id' => null, 'task_url' => null]);
                break;
            case 'missing': $records = [];
                break;
            case 'wrong project': $export->update(['project_id' => 20]);
                break;
            case 'wrong host': $records[0]['task_url'] = 'https://other.example.test/projects/10/tasks/123';
                break;
            case 'wrong task url': $records[0]['task_url'] = 'https://ac.example.test/projects/10/tasks/456';
                break;
            case 'missing url': $records[0]['task_url'] = null;
                break;
            case 'duplicate task': $copy = $this->record(123, 9);
                $copy['record_uuid'] = (string) Str::uuid();
                $records[] = $copy;
                break;
            case 'ambiguous exports': $this->export(123, 9);
                break;
            case 'orphan': $export->update(['integration_id' => null]);
                break;
            case 'uuid already bound': $export->update(['record_uuid' => (string) Str::uuid()]);
                break;
            case 'duplicate position': $records[] = $this->record(null, 8);
                break;
        }
        $before = $export->fresh()->getAttributes();
        $report = $this->runMigration($records);
        $this->assertFalse($report['applied']);
        $this->assertNotEmpty(array_merge($report['conflicts'], $report['unresolved']));
        $this->assertSame($before, $export->fresh()->getAttributes());
        $this->assertFalse($this->integration->fresh()->uuid_only);
        $this->assertDatabaseCount('admin_audit_logs', 0);
        Http::assertNothingSent();
    }

    /** @return list<array{string}> */
    public static function blockers(): array
    {
        return array_map(fn ($value) => [$value], ['active', 'running', 'unbound', 'missing', 'wrong project', 'wrong host', 'wrong task url',
            'missing url', 'duplicate task', 'ambiguous exports', 'orphan', 'uuid already bound', 'duplicate position']);
    }

    public function test_actor_requires_all_permissions_and_audit_reason_is_redacted(): void
    {
        $this->actor->forceFill(['permissions' => ['platform.activecollab.recovery' => true]])->save();
        try {
            $this->runMigration([]);
            $this->fail('Missing permissions must be rejected.');
        } catch (AuthorizationException) {
            $this->assertDatabaseCount('admin_audit_logs', 0);
        }
        $this->actor->forceFill(['permissions' => ['platform.activecollab.recovery' => true, 'platform.systems.users' => true, 'platform.systems.roles' => true]])->save();
        $this->runMigration([]);
        $this->assertStringNotContainsString('private-ac-token', AdminAuditLog::firstOrFail()->toJson());
    }

    #[DataProvider('auditFailures')]
    public function test_audit_failure_rolls_back_every_identity_and_readiness_change(string $action): void
    {
        $export = $this->export(123, 8);
        AdminAuditLog::creating(function (AdminAuditLog $log) use ($action): void {
            if ($log->action === $action) {
                throw new \RuntimeException('Simulated audit failure');
            }
        });
        try {
            $this->runMigration([$this->record(123, 8)]);
            $this->fail('Audit failure must roll back.');
        } catch (\RuntimeException $e) {
            $this->assertSame('Simulated audit failure', $e->getMessage());
        } finally {
            AdminAuditLog::flushEventListeners();
        }
        $this->assertNull($export->fresh()->record_uuid);
        $this->assertFalse($this->integration->fresh()->uuid_only);
        $this->assertDatabaseCount('admin_audit_logs', 0);
    }

    /** @return list<array{string}> */
    public static function auditFailures(): array
    {
        return [['record_uuid_bound'], ['record_uuid_enabled']];
    }

    public function test_cli_is_repeatable_and_rejects_bad_or_oversized_local_manifest(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'uuid-manifest-');
        $options = ['--integration' => (string) $this->integration->id, '--actor' => $this->actor->id,
            '--reason' => 'Confirmed identity migration', '--manifest' => $path, '--enable-uuid-only' => true];
        try {
            file_put_contents($path, json_encode(['version' => 1, 'spreadsheet_id' => 'sheet', 'records' => []], JSON_THROW_ON_ERROR));
            $this->artisan('integrations:migrate-record-uuids', $options + ['--dry-run' => true])->assertSuccessful();
            $this->assertFalse($this->integration->fresh()->uuid_only);
            $this->artisan('integrations:migrate-record-uuids', $options)->assertSuccessful();
            $this->artisan('integrations:migrate-record-uuids', $options)->assertSuccessful();
            $this->assertDatabaseCount('admin_audit_logs', 1);
            file_put_contents($path, '{bad json');
            $this->artisan('integrations:migrate-record-uuids', $options)->assertFailed();
            file_put_contents($path, str_repeat('x', ActiveCollabRecordUuidManifest::MAX_BYTES + 1));
            $this->artisan('integrations:migrate-record-uuids', $options)->assertFailed();
        } finally {
            unlink($path);
        }
        Http::assertNothingSent();
    }

    private function export(int $taskId, int $row): ActiveCollabTaskExport
    {
        return ActiveCollabTaskExport::create([
            'integration_id' => $this->integration->id, 'external_id' => 'sheet:0:'.$row, 'spreadsheet_id' => 'sheet',
            'sheet_id' => '0', 'row_number' => $row, 'project_id' => 10, 'task_id' => $taskId,
            'task_url' => 'https://ac.example.test/projects/10/tasks/'.$taskId, 'status' => 'created',
            'last_attempt_status' => 'succeeded', 'binding_status' => 'bound', 'source_payload' => ['comment' => 'Historical snapshot'],
        ]);
    }

    /** @return array<string, mixed> */
    private function record(?int $taskId, int $row): array
    {
        return ['record_uuid' => $taskId === null ? (string) Str::uuid() : sprintf('00000000-0000-4000-8000-%012d', $taskId),
            'sheet_id' => '0', 'row_number' => $row, 'task_id' => $taskId,
            'task_url' => $taskId === null ? null : 'https://ac.example.test/projects/10/tasks/'.$taskId];
    }

    /** @param list<array<string, mixed>> $records
     * @return array<string, mixed>
     */
    private function runMigration(array $records, bool $dryRun = false, bool $enable = true): array
    {
        return app(ActiveCollabRecordUuidMigrationService::class)->migrate($this->integration->id,
            ActiveCollabRecordUuidManifest::fromArray(['version' => 1, 'spreadsheet_id' => 'sheet', 'records' => $records]),
            $this->actor->id, 'Confirmed private-ac-token links during pause', $dryRun, $enable);
    }
}
