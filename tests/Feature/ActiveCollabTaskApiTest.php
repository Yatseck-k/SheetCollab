<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ActiveCollabIntegration;
use App\Models\ActiveCollabTaskExport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ActiveCollabTaskApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('app.key', 'base64:J63P4m4KjJ8l0RkGxJZ4Kf9P9+K6f2R4R6V4a2M1LxQ=');
        config()->set('services.google_sheets.integration_token', 'sheet-token');
        config()->set('services.active_collab.api_token', 'global-ac-token');
        config()->set('services.active_collab.project_id', 10);
    }

    public function test_it_rejects_requests_without_valid_bearer_token(): void
    {
        $response = $this->postJson('/api/integrations/activecollab/tasks', $this->payload());

        $response->assertStatus(401)
            ->assertJson([
                'message' => 'Unauthorized.',
            ]);
    }

    public function test_it_returns_not_found_when_integration_does_not_exist(): void
    {
        $response = $this->withToken('sheet-token')
            ->postJson('/api/integrations/activecollab/tasks', $this->payload());

        $response->assertStatus(404)
            ->assertJson([
                'message' => 'Для таблицы "sheet-123" не настроена интеграция с ActiveCollab.',
            ]);
    }

    public function test_it_creates_task_and_persists_export_history(): void
    {
        $integration = $this->createIntegration();

        Http::fake([
            'https://ac.example.com/api/v1/projects/10/tasks' => Http::response([
                'single' => [
                    'id' => 123,
                    'url_path' => '/projects/10/tasks/123',
                ],
            ], 200),
        ]);

        $response = $this->withToken('sheet-token')
            ->postJson('/api/integrations/activecollab/tasks', $this->payload([
                'spreadsheet_id' => 'https://docs.google.com/spreadsheets/d/sheet-123/edit#gid=0',
            ]));

        $response->assertStatus(201)
            ->assertJsonPath('data.task_id', 123)
            ->assertJsonPath('data.task_url', 'https://ac.example.com/projects/10/tasks/123');

        $this->assertDatabaseHas('active_collab_task_exports', [
            'integration_id' => $integration->id,
            'external_id' => 'sheet-123:sheet-tab-1:8',
            'task_id' => 123,
            'task_url' => 'https://ac.example.com/projects/10/tasks/123',
            'status' => 'created',
        ]);

        $export = ActiveCollabTaskExport::query()->firstOrFail();
        $this->assertSame(10, $export->project_id);
        $this->assertSame('backend', $export->source_payload['task_type']);
        $this->assertSame(101, $export->active_collab_payload['task_list_id']);
        $this->assertSame(3, $export->active_collab_payload['assignee_id']);
        $this->assertSame(7, $export->active_collab_payload['label_id']);
        $this->assertNotEmpty($export->sync_hash);

        Http::assertSentCount(1);
    }

    public function test_it_returns_conflict_for_already_created_row(): void
    {
        $integration = $this->createIntegration();

        ActiveCollabTaskExport::create([
            'integration_id' => $integration->id,
            'external_id' => 'sheet-123:sheet-tab-1:8',
            'spreadsheet_id' => 'sheet-123',
            'sheet_id' => 'sheet-tab-1',
            'row_number' => 8,
            'project_id' => 10,
            'task_id' => 123,
            'task_url' => 'https://ac.example.com/projects/10/tasks/123',
            'source_payload' => ['status' => 'open'],
            'status' => 'created',
        ]);

        Http::fake();

        $response = $this->withToken('sheet-token')
            ->postJson('/api/integrations/activecollab/tasks', $this->payload());

        $response->assertStatus(409)
            ->assertJson([
                'message' => 'Задача для этой строки уже создана.',
                'data' => [
                    'task_id' => 123,
                    'task_url' => 'https://ac.example.com/projects/10/tasks/123',
                ],
            ]);

        Http::assertNothingSent();
    }

    public function test_it_updates_existing_task_and_marks_export_as_updated(): void
    {
        $integration = $this->createIntegration();

        ActiveCollabTaskExport::create([
            'integration_id' => $integration->id,
            'external_id' => 'sheet-123:sheet-tab-1:8',
            'spreadsheet_id' => 'sheet-123',
            'sheet_id' => 'sheet-tab-1',
            'row_number' => 8,
            'project_id' => 10,
            'task_id' => 123,
            'task_url' => 'https://ac.example.com/projects/10/tasks/123',
            'source_payload' => ['status' => 'open'],
            'status' => 'created',
        ]);

        Http::fake([
            'https://ac.example.com/api/v1/projects/10/tasks/123' => Http::response([
                'single' => [
                    'id' => 123,
                    'url_path' => '/projects/10/tasks/123',
                ],
            ], 200),
        ]);

        $response = $this->withToken('sheet-token')
            ->putJson('/api/integrations/activecollab/tasks/123', $this->payload([
                'comment' => 'Updated comment',
            ]));

        $response->assertOk()
            ->assertJsonPath('data.task_id', 123)
            ->assertJsonPath('data.task_url', 'https://ac.example.com/projects/10/tasks/123');

        $this->assertDatabaseHas('active_collab_task_exports', [
            'integration_id' => $integration->id,
            'task_id' => 123,
            'status' => 'updated',
        ]);

        $export = ActiveCollabTaskExport::where('task_id', 123)->firstOrFail();
        $this->assertSame('Updated comment', $export->source_payload['comment'] ?? null);
        $this->assertSame('Updated comment', $this->payload(['comment' => 'Updated comment'])['comment']);

        Http::assertSentCount(1);
    }

    public function test_it_returns_error_when_integration_token_is_not_configured(): void
    {
        config()->set('services.google_sheets.integration_token');

        $response = $this->postJson('/api/integrations/activecollab/tasks', $this->payload());

        $response->assertStatus(500)
            ->assertJson([
                'message' => 'Integration token not configured.',
            ]);
    }

    public function test_it_marks_export_as_failed_when_task_type_mapping_is_missing(): void
    {
        $this->createIntegration();
        config()->set('app.debug', false);

        Http::fake();

        $response = $this->withToken('sheet-token')
            ->postJson('/api/integrations/activecollab/tasks', $this->payload([
                'task_type' => 'frontend',
            ]));

        $response->assertStatus(500);

        $this->assertDatabaseHas('active_collab_task_exports', [
            'external_id' => 'sheet-123:sheet-tab-1:8',
            'status' => 'failed',
            'error_message' => 'Для типа задачи "frontend" не настроен ActiveCollab Task List.',
        ]);

        Http::assertNothingSent();
    }

    public function test_it_marks_existing_export_as_failed_when_update_mapping_breaks(): void
    {
        $integration = $this->createIntegration();
        config()->set('app.debug', false);

        ActiveCollabTaskExport::create([
            'integration_id' => $integration->id,
            'external_id' => 'sheet-123:sheet-tab-1:8',
            'spreadsheet_id' => 'sheet-123',
            'sheet_id' => 'sheet-tab-1',
            'row_number' => 8,
            'project_id' => 10,
            'task_id' => 123,
            'task_url' => 'https://ac.example.com/projects/10/tasks/123',
            'source_payload' => ['status' => 'open'],
            'status' => 'created',
        ]);

        Http::fake();

        $response = $this->withToken('sheet-token')
            ->putJson('/api/integrations/activecollab/tasks/123', $this->payload([
                'task_type' => 'frontend',
            ]));

        $response->assertStatus(500);

        $this->assertDatabaseHas('active_collab_task_exports', [
            'task_id' => 123,
            'status' => 'failed',
            'error_message' => 'Для типа задачи "frontend" не настроен ActiveCollab Task List.',
        ]);

        Http::assertNothingSent();
    }

    public function test_it_returns_task_lists_from_activecollab(): void
    {
        Http::fake([
            'https://app.activecollab.com/460565/api/v1/projects/10/task-lists' => Http::response([
                'data' => [
                    ['id' => 31, 'name' => 'Default'],
                ],
            ], 200),
        ]);

        $response = $this->withToken('sheet-token')
            ->getJson('/api/integrations/activecollab/task-lists');

        $response->assertOk()
            ->assertJsonPath('data.0.id', 31)
            ->assertJsonPath('data.0.name', 'Default');

        Http::assertSent(fn ($request): bool => $request->hasHeader('X-Angie-AuthApiToken', ['global-ac-token'])
            && $request->url() === 'https://app.activecollab.com/460565/api/v1/projects/10/task-lists');
    }

    // ── validation ────────────────────────────────────────────────────────────────

    public function test_it_returns_422_when_required_fields_are_missing(): void
    {
        $response = $this->withToken('sheet-token')
            ->postJson('/api/integrations/activecollab/tasks', []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors([
                'spreadsheet_id', 'sheet_id', 'row_number', 'environment', 'section', 'description',
            ]);
    }

    public function test_it_returns_422_when_screenshot_is_not_a_valid_url(): void
    {
        $response = $this->withToken('sheet-token')
            ->postJson('/api/integrations/activecollab/tasks', $this->payload([
                'screenshot' => 'not-a-valid-url',
            ]));

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['screenshot']);
    }

    public function test_it_returns_422_when_row_number_is_zero(): void
    {
        $response = $this->withToken('sheet-token')
            ->postJson('/api/integrations/activecollab/tasks', $this->payload([
                'row_number' => 0,
            ]));

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['row_number']);
    }

    // ── integration lookup ────────────────────────────────────────────────────────

    public function test_it_returns_404_when_integration_is_inactive(): void
    {
        ActiveCollabIntegration::create([
            'name' => 'Inactive Integration',
            'spreadsheet_id' => 'sheet-123',
            'active_collab_base_url' => 'https://ac.example.com/api/v1',
            'active_collab_project_id' => 10,
            'active_collab_task_list_id' => 31,
            'active' => false,
        ]);

        Http::fake();

        $response = $this->withToken('sheet-token')
            ->postJson('/api/integrations/activecollab/tasks', $this->payload());

        $response->assertStatus(404);
        Http::assertNothingSent();
    }

    // ── task creation – happy path variants ──────────────────────────────────────

    public function test_it_creates_task_with_minimal_payload_and_no_optional_mappings(): void
    {
        $this->createIntegration();

        Http::fake([
            'https://ac.example.com/api/v1/projects/10/tasks' => Http::response([
                'single' => ['id' => 77, 'url_path' => '/projects/10/tasks/77'],
            ], 200),
        ]);

        $response = $this->withToken('sheet-token')
            ->postJson('/api/integrations/activecollab/tasks', [
                'spreadsheet_id' => 'sheet-123',
                'sheet_id' => 'sheet-tab-1',
                'row_number' => 9,
                'environment' => 'prod',
                'section' => 'Cart',
                'description' => 'Minimal bug report',
            ]);

        $response->assertStatus(201);

        $export = ActiveCollabTaskExport::firstOrFail();
        $this->assertSame(31, $export->active_collab_payload['task_list_id']);
        $this->assertArrayNotHasKey('assignee_id', $export->active_collab_payload);
        $this->assertArrayNotHasKey('label_id', $export->active_collab_payload);
    }

    public function test_it_reuses_pending_export_when_retrying_after_partial_failure(): void
    {
        $integration = $this->createIntegration();

        ActiveCollabTaskExport::create([
            'integration_id' => $integration->id,
            'external_id' => 'sheet-123:sheet-tab-1:8',
            'spreadsheet_id' => 'sheet-123',
            'sheet_id' => 'sheet-tab-1',
            'row_number' => 8,
            'project_id' => 10,
            'source_payload' => [],
            'status' => 'pending',
        ]);

        Http::fake([
            'https://ac.example.com/api/v1/projects/10/tasks' => Http::response([
                'single' => ['id' => 50, 'url_path' => '/projects/10/tasks/50'],
            ], 200),
        ]);

        $this->withToken('sheet-token')
            ->postJson('/api/integrations/activecollab/tasks', $this->payload())
            ->assertStatus(201);

        $this->assertCount(1, ActiveCollabTaskExport::all());
        $this->assertDatabaseHas('active_collab_task_exports', [
            'external_id' => 'sheet-123:sheet-tab-1:8',
            'status' => 'created',
            'task_id' => 50,
        ]);
    }

    // ── error handling ────────────────────────────────────────────────────────────

    public function test_it_marks_export_failed_when_activecollab_api_returns_server_error(): void
    {
        $this->createIntegration();
        config()->set('app.debug', false);

        Http::fake(['*' => Http::response(['message' => 'Internal Server Error'], 500)]);

        $response = $this->withToken('sheet-token')
            ->postJson('/api/integrations/activecollab/tasks', $this->payload());

        $response->assertStatus(500);

        $this->assertDatabaseHas('active_collab_task_exports', [
            'external_id' => 'sheet-123:sheet-tab-1:8',
            'status' => 'failed',
        ]);
    }

    public function test_it_marks_update_export_failed_when_activecollab_api_returns_server_error(): void
    {
        $integration = $this->createIntegration();
        config()->set('app.debug', false);

        ActiveCollabTaskExport::create([
            'integration_id' => $integration->id,
            'external_id' => 'sheet-123:sheet-tab-1:8',
            'spreadsheet_id' => 'sheet-123',
            'sheet_id' => 'sheet-tab-1',
            'row_number' => 8,
            'project_id' => 10,
            'task_id' => 123,
            'task_url' => 'https://ac.example.com/projects/10/tasks/123',
            'source_payload' => [],
            'status' => 'created',
        ]);

        Http::fake(['*' => Http::response(['message' => 'Internal Server Error'], 500)]);

        $response = $this->withToken('sheet-token')
            ->putJson('/api/integrations/activecollab/tasks/123', $this->payload());

        $response->assertStatus(500);

        $this->assertDatabaseHas('active_collab_task_exports', [
            'task_id' => 123,
            'status' => 'failed',
        ]);
    }

    // ── reference endpoints ───────────────────────────────────────────────────────

    public function test_get_users_returns_activecollab_users(): void
    {
        Http::fake([
            'https://app.activecollab.com/460565/api/v1/users' => Http::response([
                'data' => [
                    ['id' => 3, 'display_name' => 'Tony Yats'],
                ],
            ], 200),
        ]);

        config()->set('services.active_collab.base_url', 'https://app.activecollab.com/460565/api/v1');

        $response = $this->withToken('sheet-token')
            ->getJson('/api/integrations/activecollab/users');

        $response->assertOk()
            ->assertJsonPath('data.0.id', 3)
            ->assertJsonPath('data.0.display_name', 'Tony Yats');

        Http::assertSent(fn ($request): bool => str_ends_with((string) $request->url(), '/users')
            && $request->hasHeader('X-Angie-AuthApiToken', ['global-ac-token']));
    }

    public function test_get_labels_returns_activecollab_labels(): void
    {
        Http::fake([
            'https://app.activecollab.com/460565/api/v1/labels' => Http::response([
                'data' => [
                    ['id' => 7, 'name' => 'Critical'],
                ],
            ], 200),
        ]);

        config()->set('services.active_collab.base_url', 'https://app.activecollab.com/460565/api/v1');

        $response = $this->withToken('sheet-token')
            ->getJson('/api/integrations/activecollab/labels');

        $response->assertOk()
            ->assertJsonPath('data.0.id', 7)
            ->assertJsonPath('data.0.name', 'Critical');
    }

    private function createIntegration(): ActiveCollabIntegration
    {
        return ActiveCollabIntegration::create([
            'name' => 'Test Integration',
            'spreadsheet_id' => 'sheet-123',
            'active_collab_base_url' => 'https://ac.example.com/api/v1',
            'active_collab_project_id' => 10,
            'active_collab_task_list_id' => 31,
            'active_collab_api_token' => 'integration-ac-token',
            'task_list_mapping' => [
                'backend' => 101,
            ],
            'assignee_mapping' => [
                'Tony Yats' => 3,
            ],
            'label_mapping' => [
                'critical' => 7,
            ],
            'active' => true,
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'spreadsheet_id' => 'sheet-123',
            'sheet_id' => 'sheet-tab-1',
            'row_number' => 8,
            'environment' => 'prod',
            'section' => 'Checkout',
            'description' => 'Button is not clickable',
            'severity' => 'critical',
            'priority' => 'high',
            'device' => 'desktop',
            'resolution' => '1920x1080',
            'screenshot' => 'https://example.com/screenshot.png',
            'comment' => 'Initial comment',
            'task_type' => 'backend',
            'responsible' => 'Tony Yats',
            'reporter' => 'QA Team',
            'status' => 'open',
        ], $overrides);
    }
}
