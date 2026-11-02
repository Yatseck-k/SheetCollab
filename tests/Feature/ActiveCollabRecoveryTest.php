<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Data\ActiveCollab\ActiveCollabRecoveryData;
use App\Data\ActiveCollab\ActiveCollabSyncReservation;
use App\Data\ActiveCollab\ActiveCollabTaskData;
use App\Exceptions\TaskSyncConflictException;
use App\Models\ActiveCollabIntegration;
use App\Models\ActiveCollabSyncAttempt;
use App\Models\ActiveCollabTaskExport;
use App\Models\AdminAuditLog;
use App\Models\User;
use App\Repositories\ActiveCollab\ActiveCollabExportRepository;
use App\Services\ActiveCollab\ActiveCollabRecoveryService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\ActiveCollabIntegrationFixture;
use Tests\TestCase;

class ActiveCollabRecoveryTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private ActiveCollabIntegration $integration;

    private ActiveCollabTaskExport $export;

    private ActiveCollabRecoveryService $service;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('app.key', 'base64:J63P4m4KjJ8l0RkGxJZ4Kf9P9+K6f2R4R6V4a2M1LxQ=');
        $this->admin = User::factory()->create(['permissions' => [
            'platform.index' => true, 'platform.activecollab.recovery' => true,
            'platform.systems.users' => true, 'platform.systems.roles' => true,
        ]]);
        $this->integration = ActiveCollabIntegrationFixture::create([
            'name' => 'Recovery', 'spreadsheet_id' => 'recover-sheet', 'active_collab_project_id' => 10,
            'active_collab_base_url' => 'https://ac.example.test/api/v1', 'active_collab_api_token' => 'recovery-secret',
            'active_collab_task_list_id' => 31, 'active' => true,
        ]);
        $source = ActiveCollabTaskData::fromRequest([
            'spreadsheet_id' => 'recover-sheet', 'sheet_id' => '0', 'row_number' => 8,
            'environment' => 'test', 'section' => 'Recovery', 'description' => 'Original source',
        ])->toArray();
        $this->export = ActiveCollabTaskExport::create([
            'integration_id' => $this->integration->id, 'external_id' => 'recover-sheet:0:8',
            'spreadsheet_id' => 'recover-sheet', 'sheet_id' => '0', 'row_number' => 8, 'project_id' => 10,
            'source_payload' => $source, 'status' => 'failed', 'last_attempt_status' => 'unknown', 'binding_status' => 'unbound',
        ]);
        $this->service = app(ActiveCollabRecoveryService::class);
        Http::preventStrayRequests();
    }

    public function test_confirm_absence_releases_only_unbound_create_without_http_and_is_replayable(): void
    {
        Http::fake();
        $data = $this->data('confirm_absence');
        $result = $this->service->recover($this->admin->id, $this->export->id, $data);
        $this->assertSame('succeeded', $result['status']);
        $this->assertSame($result, $this->service->recover($this->admin->id, $this->export->id, $data));
        $this->assertSame('unbound', $this->export->fresh()->binding_status);
        $this->assertNull($this->export->fresh()->synced_at);
        $this->assertDatabaseCount('active_collab_sync_attempts', 1);
        $this->assertDatabaseCount('admin_audit_logs', 2);
        Http::assertNothingSent();
    }

    public function test_binding_existing_task_checks_destination_and_does_not_claim_content_synced(): void
    {
        Http::fake(['*' => Http::response($this->task())]);
        $this->export->update(['sync_hash' => 'old-hash', 'active_collab_payload' => ['name' => 'Old task']]);
        $this->service->recover($this->admin->id, $this->export->id, $this->data('bind'));
        $fresh = $this->export->fresh();
        $this->assertSame(123, $fresh->task_id);
        $this->assertSame('bound', $fresh->binding_status);
        $this->assertNull($fresh->sync_hash);
        $this->assertNull($fresh->synced_at);
        $this->assertNull($fresh->active_collab_payload);
        $this->assertDatabaseHas('active_collab_sync_attempts', ['action' => 'reconcile', 'status' => 'succeeded']);
        Http::assertSentCount(1);
        Http::assertSent(fn ($request): bool => $request->method() === 'GET' && $request->url() === 'https://ac.example.test/api/v1/projects/10/tasks/123');
    }

    public function test_verify_preserves_confirmed_snapshot_but_invalidates_hash(): void
    {
        $this->export->update(['binding_status' => 'bound', 'task_id' => 123, 'task_url' => 'https://ac.example.test/projects/10/tasks/123',
            'sync_hash' => 'old', 'active_collab_payload' => ['name' => 'Confirmed'], 'synced_at' => now()->subDay()]);
        $before = $this->export->fresh();
        Http::fake(['*' => Http::response($this->task())]);
        $this->service->recover($this->admin->id, $this->export->id, $this->data('verify'));
        $this->assertSame($before->active_collab_payload, $this->export->fresh()->active_collab_payload);
        $this->assertEquals($before->synced_at, $this->export->fresh()->synced_at);
        $this->assertNull($this->export->fresh()->sync_hash);
    }

    /** @return array<string, array{string}> */
    public static function badReads(): array
    {
        return ['not found' => ['404'], 'trashed' => ['trashed'], 'wrong project' => ['project'],
            'wrong ID' => ['id'], 'timeout' => ['timeout'], 'malformed' => ['malformed'], 'server error' => ['500']];
    }

    #[DataProvider('badReads')]
    public function test_failed_read_never_proves_absence_or_changes_an_unbound_target(string $kind): void
    {
        $task = $this->task();
        if ($kind === 'trashed') {
            $task['single']['is_trashed'] = true;
        }
        if ($kind === 'project') {
            $task['single']['project_id'] = 20;
        }
        if ($kind === 'id') {
            $task['single']['id'] = 999;
        }
        if ($kind === 'malformed') {
            $task = ['single' => ['id' => 123]];
        }
        Http::fake($kind === 'timeout' ? fn () => throw new ConnectionException('cURL error 28: recovery-secret')
            : fn () => Http::response($task, in_array($kind, ['404', '500'], true) ? (int) $kind : 200));
        try {
            $this->service->recover($this->admin->id, $this->export->id, $this->data('bind'));
            $this->fail('Invalid read must not bind.');
        } catch (TaskSyncConflictException $e) {
            $this->assertSame('sync_outcome_unknown', $e->errorCode);
        }
        $this->assertNull($this->export->fresh()->task_id);
        $this->assertSame('unknown', $this->export->fresh()->last_attempt_status);
        $this->assertDatabaseHas('admin_audit_logs', ['phase' => 'result']);
    }

    public function test_unknown_replacement_cannot_be_retried_by_verifying_the_old_missing_task(): void
    {
        $this->markMissing();
        Http::fakeSequence()->push([], 500)->push([], 404);
        try {
            $this->service->recover($this->admin->id, $this->export->id, $this->data('replace'));
        } catch (TaskSyncConflictException) {
        }
        $this->assertSame('unknown', $this->export->fresh()->last_attempt_status);
        try {
            $this->service->recover($this->admin->id, $this->export->id, $this->data('verify'));
        } catch (TaskSyncConflictException) {
        }
        $this->assertDatabaseHas('active_collab_task_exports', [
            'id' => $this->export->id,
            'last_attempt_status' => 'unknown',
        ]);
        $this->expectException(TaskSyncConflictException::class);
        $this->service->recover($this->admin->id, $this->export->id, $this->data('replace'));
    }

    public function test_explicit_replacement_preserves_old_binding_until_success_and_is_replayable(): void
    {
        $this->markMissing();
        $data = $this->data('replace');
        Http::fake(function () {
            $this->assertSame(90, $this->export->fresh()->task_id);
            $this->assertSame('remote_missing', $this->export->fresh()->binding_status);

            return Http::response($this->task());
        });
        $result = $this->service->recover($this->admin->id, $this->export->id, $data);
        $this->assertSame(123, $this->export->fresh()->task_id);
        $this->assertSame('bound', $this->export->fresh()->binding_status);
        $this->assertSame($result, $this->service->recover($this->admin->id, $this->export->id, $data));
        Http::assertSentCount(1);
        $this->assertSame(90, AdminAuditLog::where('phase', 'requested')->firstOrFail()->before['task_id']);
    }

    public function test_audit_failure_rolls_back_the_recovery_decision(): void
    {
        AdminAuditLog::creating(function (AdminAuditLog $log): void {
            if ($log->phase === 'result') {
                throw new \RuntimeException('Audit save failed.');
            }
        });
        try {
            $this->service->recover($this->admin->id, $this->export->id, $this->data('confirm_absence'));
            $this->fail('Recovery must not unlock without audit.');
        } catch (TaskSyncConflictException) {
            $this->assertSame('running', $this->export->fresh()->last_attempt_status);
            $this->assertNull($this->export->fresh()->task_id);
        } finally {
            AdminAuditLog::flushEventListeners();
        }
        $this->assertDatabaseCount('admin_audit_logs', 1);
        $this->assertDatabaseHas('active_collab_sync_attempts', ['status' => 'running']);
    }

    public function test_revocation_before_finalize_prevents_binding(): void
    {
        Http::fake(function () {
            $this->admin->forceFill(['permissions' => ['platform.index' => true]])->save();

            return Http::response($this->task());
        });
        $this->expectException(AuthorizationException::class);
        try {
            $this->service->recover($this->admin->id, $this->export->id, $this->data('bind'));
        } finally {
            $this->assertNull($this->export->fresh()->task_id);
            $this->assertSame('unknown', $this->export->fresh()->last_attempt_status);
        }
    }

    public function test_expired_recovery_and_changed_destination_do_not_bind(): void
    {
        $reads = 0;
        Http::fake(function () use (&$reads) {
            if ($reads++ === 0) {
                $this->travel(121)->seconds();
            } else {
                $this->integration->update(['active_collab_project_id' => 20]);
            }

            return Http::response($this->task());
        });
        try {
            $this->service->recover($this->admin->id, $this->export->id, $this->data('bind'));
        } catch (TaskSyncConflictException) {
        }
        $this->assertNull($this->export->fresh()->task_id);
        $this->expectException(TaskSyncConflictException::class);
        try {
            $this->service->recover($this->admin->id, $this->export->id, $this->data('bind'));
        } finally {
            $this->assertNull($this->export->fresh()->task_id);
        }
    }

    public function test_insufficient_permissions_do_not_create_audit_or_attempts(): void
    {
        $this->admin->forceFill(['permissions' => ['platform.activecollab.recovery' => true]])->save();
        $this->expectException(AuthorizationException::class);
        try {
            $this->service->recover($this->admin->id, $this->export->id, $this->data('confirm_absence'));
        } finally {
            $this->assertDatabaseCount('admin_audit_logs', 0);
            $this->assertDatabaseCount('active_collab_sync_attempts', 0);
        }
    }

    public function test_stale_form_and_reused_request_for_another_action_are_rejected(): void
    {
        $data = $this->data('confirm_absence');
        $stale = $this->data('bind');
        $this->service->recover($this->admin->id, $this->export->id, $data);
        $this->expectException(TaskSyncConflictException::class);
        $this->service->recover($this->admin->id, $this->export->id, $stale);
    }

    public function test_source_validation_prevents_sending_redacted_or_foreign_payload(): void
    {
        $this->markMissing();
        $source = $this->export->source_payload;
        $source['problem_description'] = '[REDACTED]';
        $this->export->update(['source_payload' => $source]);
        $this->expectException(ValidationException::class);
        try {
            $this->service->recover($this->admin->id, $this->export->id, $this->data('replace'));
        } finally {
            $this->assertDatabaseCount('admin_audit_logs', 0);
            Http::assertNothingSent();
        }
    }

    public function test_secrets_are_removed_from_reason_source_and_view(): void
    {
        $data = $this->data('confirm_absence', ['reason' => 'Verified absent recovery-secret']);
        $source = $this->export->source_payload;
        $source['comment'] = 'recovery-secret';
        $this->export->update(['source_payload' => $source]);
        $this->service->recover($this->admin->id, $this->export->id, $data);
        $serialized = json_encode([AdminAuditLog::all()->toArray(), ActiveCollabSyncAttempt::all()->toArray(),
            $this->service->view($this->admin->id, $this->export->id)], JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('recovery-secret', $serialized);
    }

    public function test_connection_credential_change_during_read_does_not_pass_context_validation(): void
    {
        $connection = $this->integration->activeCollabConnection;
        $connection->update(['api_token' => 'initial-connection-secret']);
        Http::fake(function () {
            $this->integration->activeCollabConnection->update(['api_token' => 'changed-connection-secret']);

            return Http::response($this->task());
        });
        $this->expectException(TaskSyncConflictException::class);
        try {
            $this->service->recover($this->admin->id, $this->export->id, $this->data('bind'));
        } finally {
            $this->assertNull($this->export->fresh()->task_id);
        }
    }

    public function test_replay_uses_the_saved_result_even_if_source_is_changed_later(): void
    {
        $this->markMissing();
        Http::fake(['*' => Http::response($this->task())]);
        $data = $this->data('replace');
        $result = $this->service->recover($this->admin->id, $this->export->id, $data);
        $this->export->update(['source_payload' => []]);
        $this->assertSame($result, $this->service->recover($this->admin->id, $this->export->id, $data));
        Http::assertSentCount(1);
    }

    public function test_unknown_replacement_requires_explicit_absence_before_a_new_replacement(): void
    {
        $this->markMissing();
        Http::fakeSequence()->push([], 500)->push([], 404)->push($this->task());
        try {
            $this->service->recover($this->admin->id, $this->export->id, $this->data('replace'));
        } catch (TaskSyncConflictException) {
        }
        try {
            $this->service->recover($this->admin->id, $this->export->id, $this->data('verify'));
        } catch (TaskSyncConflictException) {
        }
        $this->assertSame('unknown', $this->export->fresh()->last_attempt_status);
        $this->service->recover($this->admin->id, $this->export->id, $this->data('confirm_absence'));
        $this->assertSame(90, $this->export->fresh()->task_id);
        $this->assertSame('remote_missing', $this->export->fresh()->binding_status);
        Http::assertSentCount(2); // Initial POST and GET; absence confirmation sends nothing.
        $this->service->recover($this->admin->id, $this->export->id, $this->data('replace'));
        $this->assertSame(123, $this->export->fresh()->task_id);
        $this->assertDatabaseCount('active_collab_sync_attempts', 4);
        $this->assertDatabaseCount('admin_audit_logs', 8);
    }

    public function test_unknown_update_cannot_be_unbound_by_claiming_no_new_task(): void
    {
        $this->export->update(['task_id' => 123, 'task_url' => 'https://ac.example.test/tasks/123', 'binding_status' => 'bound']);
        $this->expectException(TaskSyncConflictException::class);
        try {
            $this->service->recover($this->admin->id, $this->export->id, $this->data('confirm_absence'));
        } finally {
            $this->assertSame(123, $this->export->fresh()->task_id);
            $this->assertDatabaseCount('admin_audit_logs', 0);
        }
    }

    public function test_same_request_id_cannot_be_reused_for_a_different_action(): void
    {
        $data = $this->data('confirm_absence');
        $other = $this->data('bind', ['request_id' => $data->requestId]);
        $this->service->recover($this->admin->id, $this->export->id, $data);
        $this->expectException(TaskSyncConflictException::class);
        $this->service->recover($this->admin->id, $this->export->id, $other);
    }

    public function test_recovery_keeps_the_original_unknown_attempt_and_rejects_its_callback(): void
    {
        $oldId = (string) Str::uuid();
        $this->export->update(['operation_id' => $oldId]);
        $old = ActiveCollabSyncAttempt::create(['export_id' => $this->export->id, 'integration_id' => $this->integration->id,
            'operation_id' => $oldId, 'action' => 'create', 'status' => 'unknown', 'started_at' => now()->subMinutes(3), 'source_payload' => []]);
        Http::fake(['*' => Http::response($this->task())]);
        $this->service->recover($this->admin->id, $this->export->id, $this->data('bind'));
        $this->assertSame('unknown', $old->fresh()->status);
        $reservation = new ActiveCollabSyncReservation($this->export->id, $oldId);
        $this->assertFalse(app(ActiveCollabExportRepository::class)->complete($reservation, 'succeeded'));
        $this->assertSame(123, $this->export->fresh()->task_id);
    }

    public function test_legacy_source_unexpected_keys_never_enter_view_or_new_journal(): void
    {
        $source = $this->export->source_payload + ['headers' => ['Authorization' => 'other-secret'], 'api_token' => 'other-secret'];
        $this->export->update(['source_payload' => $source]);
        $this->service->recover($this->admin->id, $this->export->id, $this->data('confirm_absence'));
        $this->assertStringNotContainsString('other-secret', json_encode([
            $this->service->view($this->admin->id, $this->export->id), ActiveCollabSyncAttempt::all()->toArray(), AdminAuditLog::all()->toArray(),
        ], JSON_THROW_ON_ERROR));
    }

    public function test_actor_deletion_preserves_audit_history(): void
    {
        $this->service->recover($this->admin->id, $this->export->id, $this->data('confirm_absence'));
        $this->admin->delete();
        $this->assertDatabaseCount('admin_audit_logs', 2);
        $this->assertSame(2, AdminAuditLog::whereNull('actor_id')->count());
    }

    public function test_failed_read_does_not_invent_an_unknown_mutation_for_an_already_missing_task(): void
    {
        $this->markMissing();
        Http::fake(['*' => Http::response([], 403)]);
        try {
            $this->service->recover($this->admin->id, $this->export->id, $this->data('verify'));
        } catch (TaskSyncConflictException) {
        }
        $this->assertSame('failed', $this->export->fresh()->last_attempt_status);
        $this->assertSame('remote_missing', $this->export->fresh()->binding_status);
        $this->assertTrue($this->service->view($this->admin->id, $this->export->id)['allowed']['replace']);
        Http::assertSentCount(1);
    }

    public function test_confirm_absence_normalizes_a_partial_unbound_legacy_locator(): void
    {
        $this->export->update(['binding_status' => null, 'task_url' => 'https://ac.example.test/incomplete']);
        $this->service->recover($this->admin->id, $this->export->id, $this->data('confirm_absence'));
        $this->assertSame('unbound', $this->export->fresh()->binding_status);
        $this->assertNull($this->export->fresh()->task_url);
        $source = $this->export->source_payload;
        $data = ActiveCollabTaskData::fromStoredPayload($source);
        $reservation = app(ActiveCollabExportRepository::class)->reserve($data, $this->integration, $source);
        $this->assertNull($reservation->conflictCode);
    }

    public function test_audit_failure_after_external_replacement_does_not_allow_a_second_create(): void
    {
        $this->markMissing();
        Http::fake(['*' => Http::response($this->task())]);
        AdminAuditLog::creating(function (AdminAuditLog $log): void {
            if ($log->phase === 'result') {
                throw new \RuntimeException('Simulated unavailable audit after POST.');
            }
        });
        try {
            try {
                $this->service->recover($this->admin->id, $this->export->id, $this->data('replace'));
            } catch (TaskSyncConflictException) {
            }
        } finally {
            AdminAuditLog::flushEventListeners();
        }
        $this->assertSame('running', $this->export->fresh()->last_attempt_status);
        $this->assertSame(90, $this->export->fresh()->task_id);
        $this->travel(121)->seconds();
        $this->artisan('integrations:expire-sync-operations')->assertExitCode(0);
        $this->expectException(TaskSyncConflictException::class);
        try {
            $this->service->recover($this->admin->id, $this->export->id, $this->data('replace'));
        } finally {
            Http::assertSentCount(1);
            $this->assertSame('unknown', $this->export->fresh()->last_attempt_status);
        }
    }

    public function test_uuid_replacement_accepts_its_own_historical_source_after_row_move(): void
    {
        $this->markMissing();
        $uuid = (string) Str::uuid();
        $this->integration->forceFill(['uuid_only' => true])->save();
        $this->export->update(['record_uuid' => $uuid, 'row_number' => 19, 'sheet_id' => 'moved', 'external_id' => 'recover-sheet:moved:19']);
        $oldSource = $this->export->source_payload;
        Http::fake(['*' => Http::response($this->task())]);
        $this->service->recover($this->admin->id, $this->export->id, $this->data('replace'));
        $this->assertSame(123, $this->export->fresh()->task_id);
        $this->assertSame($uuid, $this->export->fresh()->source_payload['record_uuid']);
        $this->assertSame('recover-sheet:moved:19', $this->export->fresh()->external_id);
        $this->assertNull($oldSource['record_uuid']);
        Http::assertSentCount(1);
    }

    public function test_uuid_replacement_rejects_a_source_with_another_explicit_uuid(): void
    {
        $this->markMissing();
        $source = $this->export->source_payload;
        $source['record_uuid'] = (string) Str::uuid();
        $this->export->update(['record_uuid' => (string) Str::uuid(), 'source_payload' => $source]);
        Http::fake();
        $this->expectException(ValidationException::class);
        try {
            $this->service->recover($this->admin->id, $this->export->id, $this->data('replace'));
        } finally {
            Http::assertNothingSent();
            $this->assertDatabaseCount('admin_audit_logs', 0);
            $this->assertDatabaseCount('active_collab_sync_attempts', 0);
        }
    }

    /** @param array<string, mixed> $overrides */
    private function data(string $action, array $overrides = []): ActiveCollabRecoveryData
    {
        $export = $this->export->fresh();

        return ActiveCollabRecoveryData::fromInput(array_merge([
            'request_id' => (string) Str::uuid(), 'expected_operation_id' => $export->operation_id,
            'expected_status' => $export->last_attempt_status, 'expected_binding' => $export->binding_status,
            'candidate_task_id' => 123, 'reason' => 'Verified by administrator', 'acknowledged' => true,
        ], $overrides), $action);
    }

    private function markMissing(): void
    {
        $this->export->update(['last_attempt_status' => 'failed', 'binding_status' => 'remote_missing',
            'task_id' => 90, 'task_url' => 'https://ac.example.test/projects/10/tasks/90']);
    }

    /** @return array<string, mixed> */
    private function task(): array
    {
        return ['single' => ['id' => 123, 'project_id' => 10, 'is_trashed' => false, 'url_path' => '/projects/10/tasks/123']];
    }
}
