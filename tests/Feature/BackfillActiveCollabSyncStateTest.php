<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ActiveCollabTaskExport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BackfillActiveCollabSyncStateTest extends TestCase
{
    use RefreshDatabase;

    public function test_dry_run_then_repeated_backfill_preserve_history_and_orphans(): void
    {
        $rows = [];
        foreach (['created', 'updated', 'pending', 'failed'] as $index => $status) {
            $rows[] = ActiveCollabTaskExport::create([
                'external_id' => 'sheet:0:'.($index + 1), 'spreadsheet_id' => 'sheet',
                'sheet_id' => '0', 'row_number' => $index + 1, 'project_id' => 10,
                'source_payload' => [], 'status' => $status,
                'task_id' => $status === 'pending' ? null : 123,
                'task_url' => $status === 'pending' ? null : 'https://ac.example.test/tasks/123',
            ]);
        }
        $before = ActiveCollabTaskExport::all()->toArray();
        $this->artisan('integrations:backfill-sync-state', ['--dry-run' => true])->assertExitCode(0);
        $this->assertSame($before, ActiveCollabTaskExport::all()->toArray());
        $this->artisan('integrations:backfill-sync-state')->assertExitCode(0);
        $this->assertSame('succeeded', $rows[0]->fresh()->last_attempt_status);
        $this->assertSame('succeeded', $rows[1]->fresh()->last_attempt_status);
        $this->assertSame('unknown', $rows[2]->fresh()->last_attempt_status);
        $this->assertSame('unknown', $rows[3]->fresh()->last_attempt_status);
        $after = ActiveCollabTaskExport::all()->toArray();
        $this->artisan('integrations:backfill-sync-state')->expectsOutput('{"classified":0,"unknown":0,"review":[]}')->assertExitCode(0);
        $this->assertSame($after, ActiveCollabTaskExport::all()->toArray());
        foreach ($rows as $row) {
            $this->assertNull($row->fresh()->synced_at);
            $this->assertNull($row->fresh()->integration_id);
        }
        $this->assertDatabaseCount('active_collab_sync_attempts', 0);
    }

    public function test_backfill_does_not_rewrite_a_live_operation_or_invent_a_missing_binding(): void
    {
        $row = ActiveCollabTaskExport::create([
            'external_id' => 'sheet:0:8', 'spreadsheet_id' => 'sheet', 'sheet_id' => '0',
            'row_number' => 8, 'project_id' => 10, 'source_payload' => [], 'status' => 'created',
        ]);
        $this->artisan('integrations:backfill-sync-state')->assertExitCode(0);
        $this->assertSame('unknown', $row->fresh()->last_attempt_status);
        $this->assertSame('unbound', $row->fresh()->binding_status);
        $row->update(['last_attempt_status' => 'running', 'binding_status' => null, 'operation_id' => '00000000-0000-4000-8000-000000000001']);
        $before = $row->fresh()->getAttributes();
        $this->artisan('integrations:backfill-sync-state')->assertExitCode(0);
        $this->assertSame($before, $row->fresh()->getAttributes());
    }

    public function test_multiple_dry_run_chunks_terminate_and_leave_every_record_unchanged(): void
    {
        for ($row = 1; $row <= 105; $row++) {
            ActiveCollabTaskExport::create([
                'external_id' => 'chunk:0:'.$row, 'spreadsheet_id' => 'chunk', 'sheet_id' => '0',
                'row_number' => $row, 'project_id' => 10, 'source_payload' => [], 'status' => 'pending',
            ]);
        }
        $this->artisan('integrations:backfill-sync-state', ['--dry-run' => true])->assertExitCode(0);
        $this->assertSame(105, ActiveCollabTaskExport::whereNull('last_attempt_status')->count());
        $this->artisan('integrations:backfill-sync-state')->assertExitCode(0);
        $this->assertSame(105, ActiveCollabTaskExport::where('last_attempt_status', 'unknown')->count());
    }

    public function test_additive_migrations_upgrade_a_legacy_snapshot_and_allow_empty_history_rollback(): void
    {
        $stateMigration = require database_path('migrations/2026_10_29_000001_add_operation_state_to_active_collab_task_exports_table.php');
        $attemptMigration = require database_path('migrations/2026_10_29_000002_create_active_collab_sync_attempts_table.php');
        $attemptMigration->down();
        $stateMigration->down();
        $legacy = ActiveCollabTaskExport::create([
            'external_id' => 'legacy:0:8', 'spreadsheet_id' => 'legacy', 'sheet_id' => '0',
            'row_number' => 8, 'project_id' => 10, 'source_payload' => [], 'status' => 'created',
            'task_id' => 123, 'task_url' => 'https://ac.example.test/tasks/123',
        ]);
        $stateMigration->up();
        $attemptMigration->up();
        $this->assertNull($legacy->fresh()->last_attempt_status);
        $this->artisan('integrations:backfill-sync-state')->assertExitCode(0);
        $this->assertSame('succeeded', $legacy->fresh()->last_attempt_status);
        $this->assertNull($legacy->fresh()->synced_at);
        $this->assertDatabaseCount('active_collab_sync_attempts', 0);
    }
}
