<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ActiveCollabIntegration;
use App\Models\ActiveCollabIntegrationToken;
use App\Models\ActiveCollabTaskExport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Support\ActiveCollabIntegrationFixture;
use Tests\TestCase;

class TaskSyncApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('app.key', 'base64:J63P4m4KjJ8l0RkGxJZ4Kf9P9+K6f2R4R6V4a2M1LxQ=');
        config()->set('services.google_sheets.integration_token', 'legacy-token');
        Http::preventStrayRequests();
    }

    public function test_global_token_cannot_authorize_the_new_sync_api(): void
    {
        Http::fake();
        $this->withToken('legacy-token')->postJson('/api/tasks/sync', $this->payload())->assertUnauthorized()
            ->assertJsonPath('error.code', 'unauthorized');
        $this->assertDatabaseCount('active_collab_sync_attempts', 0);
        Http::assertNothingSent();
    }

    public function test_sync_rejects_foreign_context_unknown_fields_and_oversized_json_before_claim(): void
    {
        [, $token] = $this->access();
        $this->withToken($token)->postJson('/api/tasks/sync', array_replace($this->payload(), ['spreadsheet_id' => 'another-sheet']))
            ->assertForbidden()->assertJsonPath('error.code', 'forbidden');
        $this->withToken($token)->postJson('/api/tasks/sync', $this->payload() + ['task_id' => 123])
            ->assertUnprocessable()->assertJsonValidationErrors('request');
        $large = $this->payload() + ['unexpected' => str_repeat('x', 65_537)];
        $this->withToken($token)->postJson('/api/tasks/sync', $large)
            ->assertStatus(413)->assertJsonPath('error.code', 'request_too_large');
        $this->assertDatabaseCount('active_collab_task_exports', 0);
        $this->assertDatabaseCount('active_collab_sync_attempts', 0);
    }

    public function test_create_then_skip_preserves_confirmed_timestamp_and_changed_content_updates(): void
    {
        [$integration, $token] = $this->access();
        $writes = 0;
        Http::fake(function ($request) use (&$writes) {
            $this->assertSame(0, DB::transactionLevel() - 1); // RefreshDatabase owns the outer test transaction.
            if (str_contains($request->url(), '/labels/task-labels')) {
                return Http::response([]);
            }
            $writes++;

            return Http::response(['single' => ['id' => 123, 'url_path' => '/projects/10/tasks/123',
                'project_id' => 10, 'is_trashed' => false, 'assignee_id' => 0, 'labels' => []]]);
        });
        $payload = $this->payload();
        $first = $this->withToken($token)->postJson('/api/tasks/sync', $payload)->assertCreated()->assertJsonPath('data.action', 'created');
        $date = $first->json('data.synced_at');
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $first->json('data.sync_hash'));
        $snapshot = ActiveCollabTaskExport::firstOrFail()->source_payload;
        $this->travel(60)->seconds();
        $skip = $this->withToken($token)->postJson('/api/tasks/sync', $payload)->assertOk()->assertJsonPath('data.action', 'skipped')
            ->assertJsonPath('data.synced_at', $date);
        $this->assertNotEmpty($skip->json('data.attempted_at'));
        $this->assertSame($date, $skip->json('data.synced_at'));
        $this->assertNotSame($first->json('data.operation_id'), $skip->json('data.operation_id'));
        $this->assertSame($snapshot, ActiveCollabTaskExport::firstOrFail()->source_payload);
        $this->assertSame(1, $writes);
        $this->withToken($token)->postJson('/api/tasks/sync', array_replace($payload, ['description' => 'Changed']))
            ->assertOk()->assertJsonPath('data.action', 'updated');
        $this->assertSame(2, $writes);
        $this->assertDatabaseHas('active_collab_sync_attempts', ['action' => 'skip', 'status' => 'succeeded']);
        $this->assertDatabaseHas('active_collab_task_exports', ['integration_id' => $integration->id, 'hash_version' => 'payload-sha256-v2']);
    }

    /** @return array{ActiveCollabIntegration, string} */
    private function access(): array
    {
        $integration = ActiveCollabIntegrationFixture::create(['name' => 'Sync', 'spreadsheet_id' => 'sync-sheet',
            'active_collab_base_url' => 'https://ac.example.test/api/v1', 'active_collab_project_id' => 10,
            'active_collab_task_list_id' => 31, 'active_collab_api_token' => 'ac-test-secret', 'active' => true]);
        $integration->forceFill(['uuid_only' => true])->save();
        $token = 'sc_'.str_repeat('a', 64);
        ActiveCollabIntegrationToken::create(['integration_id' => $integration->id, 'name' => 'Test', 'token_hash' => hash('sha256', $token), 'created_at' => now()]);

        return [$integration, $token];
    }

    /** @return array<string, mixed> */
    private function payload(): array
    {
        return ['record_uuid' => 'ae091ce2-282a-43ad-a258-85a2b8eecfc4', 'spreadsheet_id' => 'sync-sheet',
            'sheet_id' => '0', 'row_number' => 8, 'environment' => 'stage', 'section' => 'Catalog', 'description' => 'Bug'];
    }
}
