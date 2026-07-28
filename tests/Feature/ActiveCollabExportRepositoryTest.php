<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Data\ActiveCollab\ActiveCollabTaskData;
use App\Models\ActiveCollabIntegration;
use App\Models\ActiveCollabTaskExport;
use App\Repositories\ActiveCollab\ActiveCollabExportRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

        $this->integration = ActiveCollabIntegration::create([
            'name' => 'Test Integration',
            'spreadsheet_id' => 'sheet-abc',
            'active_collab_base_url' => 'https://ac.example.com/api/v1',
            'active_collab_project_id' => 10,
            'active_collab_task_list_id' => 31,
            'active' => true,
        ]);
    }

    // ── findByExternalId ──────────────────────────────────────────────────────────

    public function test_find_by_external_id_returns_matching_export(): void
    {
        $this->createExport(['external_id' => 'sheet-abc:tab-1:5', 'row_number' => 5]);

        $result = $this->repository->findByExternalId('sheet-abc:tab-1:5');

        $this->assertNotNull($result);
        $this->assertSame('sheet-abc:tab-1:5', $result->external_id);
    }

    public function test_find_by_external_id_returns_null_when_not_found(): void
    {
        $result = $this->repository->findByExternalId('nonexistent:tab:1');

        $this->assertNull($result);
    }

    // ── findByTaskId ──────────────────────────────────────────────────────────────

    public function test_find_by_task_id_returns_matching_export(): void
    {
        $this->createExport(['task_id' => 999]);

        $result = $this->repository->findByTaskId(999);

        $this->assertNotNull($result);
        $this->assertSame(999, $result->task_id);
    }

    public function test_find_by_task_id_returns_null_when_not_found(): void
    {
        $result = $this->repository->findByTaskId(99999);

        $this->assertNull($result);
    }

    // ── createPending ─────────────────────────────────────────────────────────────

    public function test_create_pending_creates_export_with_pending_status(): void
    {
        $data = $this->makeData();

        $export = $this->repository->createPending($data, $this->integration);

        $this->assertInstanceOf(ActiveCollabTaskExport::class, $export);
        $this->assertSame('pending', $export->status);
        $this->assertDatabaseHas('active_collab_task_exports', [
            'status' => 'pending',
            'external_id' => 'sheet-abc:tab-1:5',
        ]);
    }

    public function test_create_pending_sets_correct_fields(): void
    {
        $data = $this->makeData();

        $export = $this->repository->createPending($data, $this->integration);

        $this->assertSame($this->integration->id, $export->integration_id);
        $this->assertSame('sheet-abc:tab-1:5', $export->external_id);
        $this->assertSame('sheet-abc', $export->spreadsheet_id);
        $this->assertSame('tab-1', $export->sheet_id);
        $this->assertSame(5, $export->row_number);
        $this->assertSame(10, $export->project_id);
        $this->assertNull($export->task_id);
        $this->assertIsArray($export->source_payload);
    }

    // ── markCreated ───────────────────────────────────────────────────────────────

    public function test_mark_created_updates_status_and_persists_all_ac_data(): void
    {
        $export = $this->createExport(['status' => 'pending']);
        $acPayload = ['name' => 'Task', 'task_list_id' => 31];
        $acResponse = ['single' => ['id' => 123, 'url_path' => '/projects/10/tasks/123']];

        $this->repository->markCreated($export, 123, 'https://ac.example.com/tasks/123', 'hash-abc', $acPayload, $acResponse);

        $this->assertDatabaseHas('active_collab_task_exports', [
            'id' => $export->id,
            'status' => 'created',
            'task_id' => 123,
            'task_url' => 'https://ac.example.com/tasks/123',
            'sync_hash' => 'hash-abc',
            'error_message' => null,
        ]);

        $refreshed = $export->fresh();
        $this->assertSame($acPayload, $refreshed->active_collab_payload);
        $this->assertSame($acResponse, $refreshed->active_collab_response);
    }

    // ── markUpdated ───────────────────────────────────────────────────────────────

    public function test_mark_updated_changes_status_and_updates_payloads(): void
    {
        $export = $this->createExport(['status' => 'created', 'task_id' => 77]);
        $newSource = ['description' => 'Updated description'];
        $acPayload = ['name' => 'Updated Task'];
        $acResponse = ['single' => ['id' => 77]];

        $this->repository->markUpdated($export, 'new-hash', $newSource, $acPayload, $acResponse);

        $this->assertDatabaseHas('active_collab_task_exports', [
            'id' => $export->id,
            'status' => 'updated',
            'sync_hash' => 'new-hash',
            'error_message' => null,
        ]);

        $refreshed = $export->fresh();
        $this->assertSame($newSource, $refreshed->source_payload);
        $this->assertSame($acPayload, $refreshed->active_collab_payload);
    }

    // ── markFailed ────────────────────────────────────────────────────────────────

    public function test_mark_failed_sets_failed_status_with_error_message(): void
    {
        $export = $this->createExport(['status' => 'pending']);

        $this->repository->markFailed($export, 'Connection timed out');

        $this->assertDatabaseHas('active_collab_task_exports', [
            'id' => $export->id,
            'status' => 'failed',
            'error_message' => 'Connection timed out',
        ]);
    }

    public function test_mark_failed_can_be_called_on_previously_created_export(): void
    {
        $export = $this->createExport(['status' => 'created', 'task_id' => 42]);

        $this->repository->markFailed($export, 'AC API error on update');

        $this->assertDatabaseHas('active_collab_task_exports', [
            'id' => $export->id,
            'status' => 'failed',
            'error_message' => 'AC API error on update',
        ]);
    }

    // ── helpers ───────────────────────────────────────────────────────────────────

    /** @param array<string, mixed> $overrides */
    private function makeData(array $overrides = []): ActiveCollabTaskData
    {
        $d = array_merge([
            'spreadsheetId' => 'sheet-abc',
            'sheetId' => 'tab-1',
            'rowNumber' => 5,
            'environment' => 'prod',
            'section' => 'Cart',
            'problemDescription' => 'Bug in cart',
        ], $overrides);

        return new ActiveCollabTaskData(
            externalId: "{$d['spreadsheetId']}:{$d['sheetId']}:{$d['rowNumber']}",
            spreadsheetId: $d['spreadsheetId'],
            sheetId: $d['sheetId'],
            rowNumber: $d['rowNumber'],
            severity: null,
            priority: null,
            device: null,
            resolution: null,
            environment: $d['environment'],
            section: $d['section'],
            problemDescription: $d['problemDescription'],
            screenshotUrl: null,
            comment: null,
            taskType: null,
            responsible: null,
            reporter: null,
            sheetStatus: null,
        );
    }

    /** @param array<string, mixed> $overrides */
    private function createExport(array $overrides = []): ActiveCollabTaskExport
    {
        return ActiveCollabTaskExport::create(array_merge([
            'integration_id' => $this->integration->id,
            'external_id' => 'sheet-abc:tab-1:5',
            'spreadsheet_id' => 'sheet-abc',
            'sheet_id' => 'tab-1',
            'row_number' => 5,
            'project_id' => 10,
            'source_payload' => ['environment' => 'prod'],
            'status' => 'pending',
        ], $overrides));
    }
}
