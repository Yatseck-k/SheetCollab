<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Data\ActiveCollab\ActiveCollabSyncReservation;
use App\Data\ActiveCollab\ActiveCollabTaskData;
use App\Exceptions\TaskBindingConflictException;
use App\Models\ActiveCollabIntegration;
use App\Models\ActiveCollabSyncAttempt;
use App\Models\ActiveCollabTaskExport;
use App\Repositories\ActiveCollab\ActiveCollabExportRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\ActiveCollabIntegrationFixture;
use Tests\TestCase;

class ActiveCollabExportRepositoryTest extends TestCase
{
    use RefreshDatabase;

    private ActiveCollabExportRepository $repository;

    private ActiveCollabIntegration $integration;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('app.key', 'base64:J63P4m4KjJ8l0RkGxJZ4Kf9P9+K6f2R4R6V4a2M1LxQ=');
        $this->repository = new ActiveCollabExportRepository();
        $this->integration = ActiveCollabIntegrationFixture::create([
            'name' => 'Test', 'spreadsheet_id' => 'sheet-abc', 'active_collab_project_id' => 10,
            'active_collab_base_url' => 'https://ac.example.test/api/v1', 'active_collab_task_list_id' => 31, 'active' => true,
        ]);
    }

    public function test_reservation_blocks_competitor_and_records_success_atomically(): void
    {
        $reservation = $this->reserve();
        $competing = $this->reserve();
        $this->assertSame('sync_in_progress', $competing->conflictCode);
        $this->assertSame($reservation->operationId, $competing->operationId);
        $this->assertDatabaseCount('active_collab_sync_attempts', 1);
        $this->assertTrue($this->repository->prepareDispatch($reservation, ['name' => 'Task'], 'hash'));
        $this->assertTrue($this->repository->complete($reservation, 'succeeded', $this->confirmed()));
        $this->assertDatabaseHas('active_collab_task_exports', [
            'id' => $reservation->exportId, 'last_attempt_status' => 'succeeded',
            'binding_status' => 'bound', 'task_id' => 123, 'lease_expires_at' => null,
        ]);
        $this->assertDatabaseHas('active_collab_sync_attempts', ['operation_id' => $reservation->operationId, 'status' => 'succeeded']);
    }

    public function test_expired_lease_commits_unknown_without_allowing_takeover(): void
    {
        $reservation = $this->reserve();
        $this->travel(121)->seconds();
        $refused = $this->reserve();
        $this->assertSame('sync_outcome_unknown', $refused->conflictCode);
        $this->assertDatabaseHas('active_collab_sync_attempts', ['operation_id' => $reservation->operationId, 'status' => 'unknown']);
        $this->assertFalse($this->repository->complete($reservation, 'succeeded', $this->confirmed()));
        $this->assertDatabaseHas('active_collab_task_exports', ['id' => $reservation->exportId, 'task_id' => null, 'last_attempt_status' => 'unknown']);
        $this->assertDatabaseCount('active_collab_sync_attempts', 1);
    }

    public function test_late_completion_and_pre_dispatch_expiry_do_not_confirm_success(): void
    {
        $reservation = $this->reserve();
        $this->travel(121)->seconds();
        $this->assertFalse($this->repository->prepareDispatch($reservation, ['name' => 'Task'], 'hash'));
        $this->assertFalse($this->repository->complete($reservation, 'succeeded', $this->confirmed()));
        $this->assertDatabaseHas('active_collab_task_exports', ['id' => $reservation->exportId, 'last_attempt_status' => 'unknown']);
    }

    public function test_expired_completion_becomes_unknown_even_without_another_request(): void
    {
        $reservation = $this->reserve();
        $this->travel(121)->seconds();
        $this->assertFalse($this->repository->complete($reservation, 'succeeded', $this->confirmed()));
        $this->assertDatabaseHas('active_collab_sync_attempts', ['operation_id' => $reservation->operationId, 'status' => 'unknown']);
    }

    public function test_old_owner_cannot_complete_or_dispatch_a_new_operation(): void
    {
        $first = $this->reserve();
        $this->repository->complete($first, 'failed', errorCode: 'mapping_invalid');
        $second = $this->reserve();
        $this->assertNotSame($first->operationId, $second->operationId);
        $this->assertFalse($this->repository->complete($first, 'succeeded', $this->confirmed()));
        $this->assertFalse($this->repository->prepareDispatch($first, [], 'hash'));
        $this->assertDatabaseHas('active_collab_task_exports', ['id' => $second->exportId, 'operation_id' => $second->operationId, 'last_attempt_status' => 'running']);
    }

    public function test_completion_rollback_preserves_running_export_and_attempt(): void
    {
        $reservation = $this->reserve();
        ActiveCollabSyncAttempt::updating(function (): void {
            throw new \RuntimeException('Simulated attempt save failure.');
        });
        try {
            $this->repository->complete($reservation, 'succeeded', $this->confirmed());
            $this->fail('Completion must fail.');
        } catch (\RuntimeException $e) {
            $this->assertSame('Simulated attempt save failure.', $e->getMessage());
        } finally {
            ActiveCollabSyncAttempt::flushEventListeners();
        }
        $this->assertDatabaseHas('active_collab_task_exports', ['id' => $reservation->exportId, 'last_attempt_status' => 'running', 'task_id' => null]);
        $this->assertDatabaseHas('active_collab_sync_attempts', ['operation_id' => $reservation->operationId, 'status' => 'running']);
    }

    public function test_lookup_requires_integration_project_source_and_task(): void
    {
        $reservation = $this->reserve();
        $this->repository->complete($reservation, 'succeeded', $this->confirmed());
        $this->assertNotNull($this->repository->findByTaskId(123, $this->integration, 'sheet-abc:0:8'));
        $this->assertNull($this->repository->findByTaskId(999, $this->integration, 'sheet-abc:0:8'));
        $this->assertNull($this->repository->findByTaskId(123, $this->integration, 'sheet-abc:0:9'));
        $this->integration->active_collab_project_id = 20;
        $this->assertNull($this->repository->findByTaskId(123, $this->integration, 'sheet-abc:0:8'));
        $this->integration->active_collab_project_id = 10;
        $this->integration->id = (string) Str::uuid();
        $this->assertNull($this->repository->findByTaskId(123, $this->integration, 'sheet-abc:0:8'));
    }

    public function test_external_locator_cannot_be_reused_by_another_integration(): void
    {
        $this->reserve();
        $this->integration->id = (string) Str::uuid();
        $this->expectException(TaskBindingConflictException::class);
        $this->repository->findByExternalId('sheet-abc:0:8', $this->integration);
    }

    public function test_legacy_pending_is_classified_unknown_without_fabricating_an_attempt(): void
    {
        ActiveCollabTaskExport::create([
            'integration_id' => $this->integration->id, 'external_id' => 'sheet-abc:0:8',
            'spreadsheet_id' => 'sheet-abc', 'sheet_id' => '0', 'row_number' => 8,
            'project_id' => 10, 'source_payload' => [], 'status' => 'pending',
        ]);
        $this->assertSame('sync_outcome_unknown', $this->reserve()->conflictCode);
        $this->assertDatabaseCount('active_collab_sync_attempts', 0);
    }

    public function test_partial_operation_state_is_never_reset_by_lazy_legacy_classification(): void
    {
        $reservation = $this->reserve();
        ActiveCollabTaskExport::whereKey($reservation->exportId)->update(['binding_status' => null]);
        $this->assertSame('sync_outcome_unknown', $this->reserve()->conflictCode);
        $this->assertDatabaseHas('active_collab_task_exports', [
            'id' => $reservation->exportId, 'operation_id' => $reservation->operationId, 'last_attempt_status' => 'running',
        ]);
    }

    public function test_legacy_task_lookup_rejects_ambiguous_locator_even_for_one_matching_task(): void
    {
        $reservation = $this->reserve();
        $this->repository->complete($reservation, 'succeeded', $this->confirmed());
        $other = ActiveCollabTaskExport::findOrFail($reservation->exportId)->replicate();
        $other->task_id = 456;
        $other->save();
        $this->expectException(TaskBindingConflictException::class);
        $this->repository->findByTaskId(123, $this->integration, 'sheet-abc:0:8');
    }

    private function reserve(): ActiveCollabSyncReservation
    {
        $data = ActiveCollabTaskData::fromRequest([
            'spreadsheet_id' => 'sheet-abc', 'sheet_id' => '0', 'row_number' => 8,
            'environment' => 'test', 'section' => 'Test', 'description' => 'Task',
        ]);

        return $this->repository->reserve($data, $this->integration, $data->toArray());
    }

    /** @return array<string, mixed> */
    private function confirmed(): array
    {
        return ['task_id' => 123, 'task_url' => 'https://ac.example.test/projects/10/tasks/123',
            'sync_hash' => 'hash', 'source_payload' => [], 'active_collab_payload' => ['name' => 'Task'],
            'active_collab_response' => ['single' => ['id' => 123, 'url_path' => '/projects/10/tasks/123']]];
    }
}
