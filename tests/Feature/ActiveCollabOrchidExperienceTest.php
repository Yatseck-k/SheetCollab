<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ActiveCollabIntegration;
use App\Models\ActiveCollabSyncAttempt;
use App\Models\ActiveCollabTaskExport;
use App\Models\User;
use App\Repositories\AdminDashboardRepository;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Support\ActiveCollabIntegrationFixture;
use Tests\TestCase;

class ActiveCollabOrchidExperienceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('app.key', 'base64:J63P4m4KjJ8l0RkGxJZ4Kf9P9+K6f2R4R6V4a2M1LxQ=');
        Http::preventStrayRequests();
    }

    public function test_dashboard_only_aggregates_and_lists_assigned_integrations(): void
    {
        [$assigned, $export] = $this->integrationWithAttempt('Assigned dashboard', 'assigned-row');
        $this->integrationWithAttempt('Private dashboard', 'private-row');
        $viewer = User::factory()->create(['permissions' => [
            'platform.index' => true, 'platform.activecollab.integration.view' => true,
        ]]);
        $assigned->users()->attach($viewer->id);

        $this->actingAs($viewer)->get(route('platform.main'))
            ->assertOk()->assertSee('Assigned dashboard')->assertDontSee('Private dashboard')
            ->assertSee('assigned-row')->assertDontSee('private-row');
        Http::assertNothingSent();
    }

    public function test_dashboard_aggregates_metrics_in_two_queries_and_keeps_existing_counts(): void
    {
        [$assigned, $boundExport] = $this->integrationWithAttempt('Dashboard metrics', 'dashboard-bound-row');
        $boundExport->update(['binding_status' => 'bound', 'last_attempt_status' => 'running']);
        $failedExport = ActiveCollabTaskExport::query()->create([
            'integration_id' => $assigned->id,
            'external_id' => 'dashboard-remote-missing-row',
            'spreadsheet_id' => $assigned->spreadsheet_id,
            'sheet_id' => '0',
            'row_number' => 2,
            'project_id' => 10,
            'status' => 'failed',
            'last_attempt_status' => 'failed',
            'binding_status' => 'remote_missing',
            'source_payload' => [],
        ]);
        $this->createAttempt($boundExport, 'create', 'succeeded');
        $this->createAttempt($failedExport, 'update', 'succeeded');

        $viewer = User::factory()->create(['permissions' => [
            'platform.index' => true, 'platform.activecollab.integration.view' => true,
        ]]);
        $assigned->users()->attach($viewer->id);
        $aggregateQueries = ['exports' => 0, 'attempts' => 0];

        DB::listen(static function (QueryExecuted $query) use (&$aggregateQueries): void {
            $sql = strtolower($query->sql);
            if (str_contains($sql, 'active_collab_task_exports') && str_contains($sql, 'sum(case when')) {
                $aggregateQueries['exports']++;
            }
            if (str_contains($sql, 'active_collab_sync_attempts') && str_contains($sql, 'group by')) {
                $aggregateQueries['attempts']++;
            }
        });

        $dashboard = app(AdminDashboardRepository::class)->forUser($viewer->id, false);

        $this->assertSame([
            'integrations' => 1,
            'active_integrations' => 1,
            'bound' => 1,
            'created' => 1,
            'updated' => 1,
            'running' => 1,
            'unknown' => 0,
            'failed' => 1,
            'remote_missing' => 1,
        ], $dashboard['summary']);
        $this->assertCount(1, $dashboard['integrations']);
        $this->assertSame(['exports' => 1, 'attempts' => 1], $aggregateQueries);
    }

    public function test_attempt_history_is_scoped_paginated_and_does_not_render_payload_or_error_text(): void
    {
        [$assigned, $export] = $this->integrationWithAttempt('History assigned', 'history-row');
        [, $privateExport] = $this->integrationWithAttempt('History private', 'private-history-row');
        $viewer = User::factory()->create(['permissions' => [
            'platform.index' => true, 'platform.activecollab.integration.view' => true,
        ]]);
        $assigned->users()->attach($viewer->id);

        $this->actingAs($viewer)->get(route('platform.exports.attempts', ['export' => $export->id]))
            ->assertOk()->assertSee('history-row')->assertSee('Требуется сверка')
            ->assertDontSee('private-history-row')->assertDontSee('payload-secret-marker')->assertDontSee('raw-error-secret-marker');
        $this->actingAs($viewer)->get(route('platform.exports.attempts', ['export' => $privateExport->id]))
            ->assertNotFound();
        Http::assertNothingSent();
    }

    public function test_opening_connection_editor_does_not_make_implicit_project_request(): void
    {
        $connection = $this->integrationWithAttempt('Connection check', 'connection-row')[0]->activeCollabConnection;
        $admin = User::factory()->create(['permissions' => ['platform.index' => true, 'platform.activecollab.admin' => true]]);

        $this->actingAs($admin)->get(route('platform.connections.edit', ['connection' => $connection->id]))->assertOk();
        Http::assertNothingSent();
    }

    /** @return array{ActiveCollabIntegration, ActiveCollabTaskExport} */
    private function integrationWithAttempt(string $name, string $externalId): array
    {
        $integration = ActiveCollabIntegrationFixture::create([
            'name' => $name, 'spreadsheet_id' => Str::slug($name), 'active_collab_project_id' => 10,
            'active_collab_task_list_id' => 31,
        ]);
        $export = ActiveCollabTaskExport::query()->create([
            'integration_id' => $integration->id, 'external_id' => $externalId, 'spreadsheet_id' => $integration->spreadsheet_id,
            'sheet_id' => '0', 'row_number' => 1, 'project_id' => 10, 'status' => 'failed', 'last_attempt_status' => 'unknown',
            'binding_status' => 'unbound', 'source_payload' => [],
        ]);
        ActiveCollabSyncAttempt::query()->create([
            'export_id' => $export->id, 'integration_id' => $integration->id, 'operation_id' => (string) Str::uuid(),
            'action' => 'create', 'status' => 'unknown', 'started_at' => now(), 'finished_at' => now(), 'error_code' => 'sync_outcome_unknown',
            'error_message' => 'raw-error-secret-marker', 'source_payload' => ['comment' => 'payload-secret-marker'],
            'active_collab_payload' => ['name' => 'payload-secret-marker'], 'active_collab_response' => ['secret' => 'payload-secret-marker'],
        ]);

        return [$integration, $export];
    }

    private function createAttempt(ActiveCollabTaskExport $export, string $action, string $status): void
    {
        ActiveCollabSyncAttempt::query()->create([
            'export_id' => $export->id,
            'integration_id' => $export->integration_id,
            'operation_id' => (string) Str::uuid(),
            'action' => $action,
            'status' => $status,
            'started_at' => now(),
            'finished_at' => now(),
            'source_payload' => [],
        ]);
    }
}
