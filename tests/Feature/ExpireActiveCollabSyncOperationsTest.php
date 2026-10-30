<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ActiveCollabSyncAttempt;
use App\Models\ActiveCollabTaskExport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class ExpireActiveCollabSyncOperationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_dry_run_chunks_expire_only_old_running_and_never_invent_attempts(): void
    {
        Http::preventStrayRequests();
        Http::fake();
        for ($row = 1; $row <= 105; $row++) {
            $export = $this->running($row);
            if ($row !== 1) {
                ActiveCollabSyncAttempt::create([
                    'export_id' => $export->id, 'integration_id' => (string) Str::uuid(),
                    'operation_id' => $export->operation_id, 'action' => 'create', 'status' => 'running',
                    'started_at' => now()->subMinutes(3), 'source_payload' => [],
                ]);
            }
        }
        $live = $this->running(200);
        $live->update(['lease_expires_at' => now()->addMinute()]);
        $finished = $this->running(201);
        $finished->update(['last_attempt_status' => 'succeeded']);
        $before = ActiveCollabTaskExport::all()->toArray();
        $this->artisan('integrations:expire-sync-operations', ['--dry-run' => true])->assertExitCode(0);
        $this->assertSame($before, ActiveCollabTaskExport::all()->toArray());
        $this->artisan('integrations:expire-sync-operations')->assertExitCode(0);
        $this->assertSame(105, ActiveCollabTaskExport::where('last_attempt_status', 'unknown')->count());
        $this->assertSame('running', $live->fresh()->last_attempt_status);
        $this->assertSame('succeeded', $finished->fresh()->last_attempt_status);
        $this->assertSame(104, ActiveCollabSyncAttempt::where('status', 'unknown')->count());
        $this->assertDatabaseCount('active_collab_sync_attempts', 104);
        $this->artisan('integrations:expire-sync-operations')->expectsOutput('{"expired":0,"review":[]}')->assertExitCode(0);
        Http::assertNothingSent();
    }

    public function test_null_lease_or_missing_attempt_is_unknown_without_losing_binding(): void
    {
        $export = $this->running(8);
        $export->update(['lease_expires_at' => null, 'task_id' => 123, 'task_url' => 'https://ac.example.test/tasks/123',
            'binding_status' => 'bound', 'sync_hash' => 'confirmed', 'active_collab_payload' => ['name' => 'Confirmed']]);
        $before = $export->fresh()->only(['task_id', 'task_url', 'sync_hash', 'active_collab_payload']);
        $this->artisan('integrations:expire-sync-operations')->assertExitCode(0);
        $this->assertSame('unknown', $export->fresh()->last_attempt_status);
        $this->assertSame($before, $export->fresh()->only(array_keys($before)));
        $this->assertDatabaseCount('active_collab_sync_attempts', 0);
    }

    private function running(int $row): ActiveCollabTaskExport
    {
        return ActiveCollabTaskExport::create([
            'external_id' => 'expiry:0:'.$row, 'spreadsheet_id' => 'expiry', 'sheet_id' => '0', 'row_number' => $row,
            'project_id' => 10, 'source_payload' => [], 'status' => 'pending', 'binding_status' => 'unbound',
            'last_attempt_status' => 'running', 'operation_id' => (string) Str::uuid(), 'lease_expires_at' => now()->subMinute(),
        ]);
    }
}
