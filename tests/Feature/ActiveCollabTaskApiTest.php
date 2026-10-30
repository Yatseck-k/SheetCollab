<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ActiveCollabIntegration;
use App\Models\ActiveCollabSyncAttempt;
use App\Models\ActiveCollabTaskExport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
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
        config()->set('services.active_collab.base_url', 'https://app.activecollab.com/460565/api/v1');
        Http::preventStrayRequests();
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

    public function test_it_blocks_legacy_pending_without_repeating_create(): void
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
            ->assertStatus(409)->assertJsonPath('error.code', 'sync_outcome_unknown');

        Http::assertNothingSent();
        $this->assertDatabaseCount('active_collab_sync_attempts', 0);
        $this->assertCount(1, ActiveCollabTaskExport::all());
        $this->assertDatabaseHas('active_collab_task_exports', [
            'external_id' => 'sheet-123:sheet-tab-1:8',
            'status' => 'pending',
            'task_id' => null,
            'last_attempt_status' => 'unknown',
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

        $response->assertStatus(502)->assertJsonPath('error.code', 'sync_outcome_unknown');

        $this->assertDatabaseHas('active_collab_task_exports', [
            'external_id' => 'sheet-123:sheet-tab-1:8',
            'status' => 'failed',
            'last_attempt_status' => 'unknown',
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

        $response->assertStatus(502)->assertJsonPath('error.code', 'sync_outcome_unknown');

        $this->assertDatabaseHas('active_collab_task_exports', [
            'task_id' => 123,
            'status' => 'failed',
            'last_attempt_status' => 'unknown',
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

    /** @param array<string, mixed>|null $overrides */
    #[DataProvider('invalidBindingProvider')]
    public function test_it_rejects_update_for_invalid_binding(?array $overrides): void
    {
        $integration = $this->createIntegration();
        $export = $overrides === null ? null : $this->createBoundExport($integration, $overrides);
        $before = $export?->fresh()->getAttributes();
        Http::fake();

        $this->withToken('sheet-token')
            ->putJson('/api/integrations/activecollab/tasks/123', $this->payload())
            ->assertStatus(409)
            ->assertExactJson($this->bindingConflict());

        Http::assertNothingSent();
        if ($export !== null) {
            $this->assertSame($before, $export->fresh()->getAttributes());
        }
    }

    /** @param array<string, mixed> $overrides */
    #[DataProvider('conflictingCreateBindingProvider')]
    public function test_it_rejects_create_for_conflicting_existing_binding(array $overrides): void
    {
        $integration = $this->createIntegration();
        $export = $this->createBoundExport($integration, $overrides);
        $before = $export->fresh()->getAttributes();
        Http::fake();

        $response = $this->withToken('sheet-token')
            ->postJson('/api/integrations/activecollab/tasks', $this->payload());

        $response->assertStatus(409);
        $response->assertExactJson($this->bindingConflict());
        Http::assertNothingSent();
        $this->assertSame($before, $export->fresh()->getAttributes());
    }

    /** @return array<string, array{0: array<string, mixed>|null}> */
    public static function invalidBindingProvider(): array
    {
        return [
            'missing export' => [null],
            'different source row' => [['external_id' => 'sheet-123:sheet-tab-1:9', 'row_number' => 9]],
            'orphan integration' => [['integration_id' => null]],
            'different integration' => [['integration_id' => '00000000-0000-4000-8000-000000000001']],
            'different project' => [['project_id' => 20]],
            'different task' => [['task_id' => 999]],
            'missing task ID' => [['task_id' => null]],
            'invalid task ID' => [['task_id' => 0]],
            'missing URL' => [['task_url' => null]],
            'empty URL' => [['task_url' => '   ']],
            'created without binding' => [['task_id' => null, 'task_url' => null]],
            'updated without binding' => [['status' => 'updated', 'task_id' => null, 'task_url' => null]],
            'failed with URL only' => [['status' => 'failed', 'task_id' => null]],
        ];
    }

    /** @return array<string, array{0: array<string, mixed>}> */
    public static function conflictingCreateBindingProvider(): array
    {
        $cases = self::invalidBindingProvider();
        unset($cases['missing export'], $cases['different source row'], $cases['different task']);

        return $cases;
    }

    public function test_create_rejects_complete_bindings_regardless_of_status(): void
    {
        $integration = $this->createIntegration();
        $export = $this->createBoundExport($integration);
        Http::fake();

        foreach (['created', 'updated', 'pending', 'failed'] as $status) {
            $export->update(['status' => $status]);
            $this->withToken('sheet-token')
                ->postJson('/api/integrations/activecollab/tasks', $this->payload())
                ->assertStatus(409)
                ->assertJsonPath('data.task_id', 123)
                ->assertJsonPath('data.task_url', $export->task_url);
        }

        Http::assertNothingSent();
    }

    #[DataProvider('updateFailureProvider')]
    public function test_failed_update_preserves_binding_and_blocks_another_create(bool $mappingFailure): void
    {
        $integration = $this->createIntegration();
        $export = $this->createBoundExport($integration);
        $confirmed = $export->only([
            'task_id', 'task_url', 'sync_hash', 'source_payload',
            'active_collab_payload', 'active_collab_response',
        ]);
        config()->set('app.debug', false);
        Http::fake(['*' => Http::response(['message' => 'Upstream failure'], 500)]);

        $this->withToken('sheet-token')
            ->putJson('/api/integrations/activecollab/tasks/123', $this->payload([
                'task_type' => $mappingFailure ? 'unmapped' : 'backend',
                'comment' => 'Unconfirmed change',
            ]))->assertStatus($mappingFailure ? 500 : 502);

        $this->assertSame('failed', $export->fresh()->status);
        $this->assertSame($confirmed, $export->fresh()->only(array_keys($confirmed)));

        $this->withToken('sheet-token')
            ->postJson('/api/integrations/activecollab/tasks', $this->payload())
            ->assertStatus(409)
            ->assertExactJson([
                'message' => 'Задача для этой строки уже создана.',
                'data' => ['task_id' => 123, 'task_url' => $confirmed['task_url']],
            ]);

        Http::assertSentCount($mappingFailure ? 0 : 1);
        Http::assertNotSent(fn ($request): bool => $request->method() === 'POST');
    }

    /** @return array<string, array{bool}> */
    public static function updateFailureProvider(): array
    {
        return ['mapping failure' => [true], 'HTTP failure' => [false]];
    }

    public function test_update_uses_exact_binding_when_task_ids_coincide(): void
    {
        $integration = $this->createIntegration();
        $other = $this->createBoundExport($integration, [
            'integration_id' => '00000000-0000-4000-8000-000000000001',
            'external_id' => 'other-sheet:tab:8',
            'spreadsheet_id' => 'other-sheet',
            'project_id' => 20,
        ]);
        $before = $other->fresh()->getAttributes();
        $target = $this->createBoundExport($integration);
        Http::fake(['*' => Http::response([
            'single' => ['id' => 123, 'url_path' => '/projects/10/tasks/123'],
        ])]);

        $this->withToken('sheet-token')
            ->putJson('/api/integrations/activecollab/tasks/123', $this->payload())
            ->assertOk()->assertJsonPath('data.task_id', 123);

        $this->assertSame('updated', $target->fresh()->status);
        $this->assertSame($before, $other->fresh()->getAttributes());
        Http::assertSentCount(1);
        Http::assertSent(fn ($request): bool => $request->method() === 'PUT'
            && $request->url() === 'https://ac.example.com/api/v1/projects/10/tasks/123');
    }

    /** @param array<string, mixed> $overrides */
    private function createBoundExport(ActiveCollabIntegration $integration, array $overrides = []): ActiveCollabTaskExport
    {
        return ActiveCollabTaskExport::create(array_merge([
            'integration_id' => $integration->id,
            'external_id' => 'sheet-123:sheet-tab-1:8',
            'spreadsheet_id' => 'sheet-123',
            'sheet_id' => 'sheet-tab-1',
            'row_number' => 8,
            'project_id' => 10,
            'task_id' => 123,
            'task_url' => 'https://ac.example.com/projects/10/tasks/123',
            'source_payload' => ['comment' => 'Confirmed source'],
            'sync_hash' => 'confirmed-hash',
            'active_collab_payload' => ['name' => 'Confirmed task'],
            'active_collab_response' => ['single' => ['id' => 123]],
            'status' => 'created',
        ], $overrides));
    }

    /** @return array<string, mixed> */
    private function bindingConflict(): array
    {
        return [
            'message' => 'Привязка задачи не найдена или не соответствует исходной записи.',
            'error' => [
                'code' => 'legacy_binding_conflict',
                'message' => 'Привязка задачи не найдена или не соответствует исходной записи.',
                'operation_id' => null,
                'retryable' => false,
            ],
        ];
    }

    public function test_mapping_failure_before_dispatch_can_be_retried_safely(): void
    {
        $integration = $this->createIntegration();
        Http::fake(['*' => Http::response(['single' => ['id' => 123, 'url_path' => '/projects/10/tasks/123']])]);
        $this->withToken('sheet-token')->postJson('/api/integrations/activecollab/tasks', $this->payload(['task_type' => 'unmapped']))
            ->assertStatus(500)->assertJsonPath('error.code', 'mapping_invalid');
        Http::assertNothingSent();
        $integration->update(['task_list_mapping' => ['backend' => 101, 'unmapped' => 102]]);
        $this->withToken('sheet-token')->postJson('/api/integrations/activecollab/tasks', $this->payload(['task_type' => 'unmapped']))->assertCreated();
        Http::assertSentCount(1);
        $this->assertDatabaseCount('active_collab_sync_attempts', 2);
        $this->assertDatabaseHas('active_collab_sync_attempts', ['status' => 'failed', 'error_code' => 'mapping_invalid']);
        $this->assertDatabaseHas('active_collab_sync_attempts', ['status' => 'succeeded']);
    }

    public function test_timeout_is_unknown_and_never_repeats_create(): void
    {
        $this->createIntegration();
        Http::fake(fn () => throw new ConnectionException('cURL error 28: integration-ac-token'));
        $first = $this->withToken('sheet-token')->postJson('/api/integrations/activecollab/tasks', $this->payload());
        $first->assertStatus(504)->assertJsonPath('error.retryable', false);
        $this->assertStringNotContainsString('integration-ac-token', $first->getContent());
        $this->withToken('sheet-token')->postJson('/api/integrations/activecollab/tasks', $this->payload())
            ->assertStatus(409)->assertJsonPath('error.operation_id', $first->json('error.operation_id'));
        $this->assertDatabaseCount('active_collab_sync_attempts', 1);
        $this->assertDatabaseHas('active_collab_sync_attempts', ['status' => 'unknown']);
    }

    public function test_malformed_success_does_not_permit_a_second_create(): void
    {
        $this->createIntegration();
        Http::fake(['*' => Http::response(['single' => ['id' => 123]])]);
        $this->withToken('sheet-token')->postJson('/api/integrations/activecollab/tasks', $this->payload())
            ->assertStatus(502)->assertJsonPath('error.code', 'sync_outcome_unknown');
        $this->withToken('sheet-token')->postJson('/api/integrations/activecollab/tasks', $this->payload())->assertStatus(409);
        Http::assertSentCount(1);
        $this->assertDatabaseHas('active_collab_task_exports', ['last_attempt_status' => 'unknown', 'task_id' => null]);
    }

    public function test_commit_failure_after_external_create_blocks_a_second_call(): void
    {
        $this->createIntegration();
        Http::fake(['*' => Http::response(['single' => ['id' => 123, 'url_path' => '/projects/10/tasks/123']])]);
        ActiveCollabTaskExport::updating(function (ActiveCollabTaskExport $export): void {
            if ($export->last_attempt_status === 'succeeded') {
                throw new \RuntimeException('Simulated failed commit.');
            }
        });
        try {
            $this->withToken('sheet-token')->postJson('/api/integrations/activecollab/tasks', $this->payload())->assertStatus(502);
        } finally {
            ActiveCollabTaskExport::flushEventListeners();
        }
        $this->withToken('sheet-token')->postJson('/api/integrations/activecollab/tasks', $this->payload())
            ->assertStatus(409)->assertJsonPath('error.code', 'sync_outcome_unknown');
        Http::assertSentCount(1);
        $this->assertDatabaseHas('active_collab_task_exports', ['last_attempt_status' => 'unknown', 'task_id' => null]);
    }

    public function test_database_outage_leaves_durable_running_then_expiry_blocks_create(): void
    {
        $this->createIntegration();
        Http::fake(['*' => Http::response(['single' => ['id' => 123, 'url_path' => '/projects/10/tasks/123']])]);
        ActiveCollabSyncAttempt::updating(fn () => throw new \RuntimeException('Simulated database outage.'));
        try {
            $this->withToken('sheet-token')->postJson('/api/integrations/activecollab/tasks', $this->payload())->assertStatus(502);
        } finally {
            ActiveCollabSyncAttempt::flushEventListeners();
        }
        $this->assertDatabaseHas('active_collab_task_exports', ['last_attempt_status' => 'running', 'task_id' => null]);
        $this->travel(121)->seconds();
        $this->withToken('sheet-token')->postJson('/api/integrations/activecollab/tasks', $this->payload())
            ->assertStatus(409)->assertJsonPath('error.code', 'sync_outcome_unknown');
        Http::assertSentCount(1);
        $this->assertDatabaseHas('active_collab_sync_attempts', ['status' => 'unknown']);
    }

    public function test_known_secrets_are_absent_from_new_history_and_error_logs(): void
    {
        $this->createIntegration();
        $logs = [];
        Log::listen(function ($event) use (&$logs): void {
            $logs[] = [$event->message, $event->context];
        });
        Http::fake(['*' => Http::response(['message' => 'integration-ac-token global-ac-token sheet-token'], 500)]);
        $response = $this->withToken('sheet-token')->postJson('/api/integrations/activecollab/tasks', $this->payload([
            'comment' => 'integration-ac-token global-ac-token sheet-token',
        ]))->assertStatus(502);
        $serialized = json_encode([
            $response->json(), ActiveCollabSyncAttempt::all()->toArray(),
            ActiveCollabTaskExport::all()->toArray(), $logs,
        ], JSON_THROW_ON_ERROR);
        foreach (['integration-ac-token', 'global-ac-token', 'sheet-token'] as $token) {
            $this->assertStringNotContainsString($token, $serialized);
        }
    }

    public function test_missing_remote_task_preserves_binding_and_blocks_following_update(): void
    {
        $integration = $this->createIntegration();
        $export = $this->createBoundExport($integration);
        $confirmed = $export->only(['task_id', 'task_url', 'sync_hash', 'source_payload', 'active_collab_payload', 'active_collab_response']);
        Http::fake(['*' => Http::response(['message' => 'Not found'], 404)]);
        $this->withToken('sheet-token')->putJson('/api/integrations/activecollab/tasks/123', $this->payload())
            ->assertStatus(409)->assertJsonPath('error.code', 'remote_task_missing');
        $this->assertSame($confirmed, $export->fresh()->only(array_keys($confirmed)));
        $this->assertSame('remote_missing', $export->fresh()->binding_status);
        $this->withToken('sheet-token')->putJson('/api/integrations/activecollab/tasks/123', $this->payload())
            ->assertStatus(409)->assertJsonPath('error.code', 'remote_task_missing');
        $this->withToken('sheet-token')->postJson('/api/integrations/activecollab/tasks', $this->payload())
            ->assertStatus(409)->assertJsonPath('data.task_id', 123);
        Http::assertSentCount(1);
    }

    public function test_missing_task_can_receive_a_fresh_validated_source_without_external_write(): void
    {
        $integration = $this->createIntegration();
        $export = $this->createBoundExport($integration, ['binding_status' => 'remote_missing', 'last_attempt_status' => 'failed']);
        Http::fake();
        $this->withToken('sheet-token')->putJson('/api/integrations/activecollab/tasks/123', $this->payload(['description' => 'Fresh replacement source']))
            ->assertStatus(409)->assertJsonPath('error.code', 'remote_task_missing');
        $attempt = ActiveCollabSyncAttempt::firstOrFail();
        $this->assertSame('Fresh replacement source', $attempt->source_payload['problem_description']);
        $this->assertSame('failed', $attempt->status);
        $this->assertSame('remote_task_missing', $attempt->error_code);
        $this->assertSame(123, $export->fresh()->task_id);
        $this->assertSame(['comment' => 'Confirmed source'], $export->fresh()->source_payload);
        Http::assertNothingSent();
    }

    public function test_missing_task_update_cannot_overwrite_an_unresolved_replacement(): void
    {
        $integration = $this->createIntegration();
        $oldOperation = (string) Str::uuid();
        $export = $this->createBoundExport($integration, ['binding_status' => 'remote_missing', 'last_attempt_status' => 'unknown', 'operation_id' => $oldOperation]);
        Http::fake();
        $this->withToken('sheet-token')->putJson('/api/integrations/activecollab/tasks/123', $this->payload())
            ->assertStatus(409)->assertJsonPath('error.code', 'remote_task_missing');
        $this->assertSame($oldOperation, $export->fresh()->operation_id);
        $this->assertSame('unknown', $export->fresh()->last_attempt_status);
        $this->assertDatabaseCount('active_collab_sync_attempts', 0);
        Http::assertNothingSent();
    }

    public function test_uuid_binding_survives_move_and_another_record_can_reuse_its_position(): void
    {
        $integration = $this->createIntegration();
        $integration->forceFill(['uuid_only' => true])->save();
        $uuid = (string) Str::uuid();
        Http::fakeSequence()
            ->push(['single' => ['id' => 123, 'url_path' => '/projects/10/tasks/123']])
            ->push(['single' => ['id' => 123, 'url_path' => '/projects/10/tasks/123']])
            ->push(['single' => ['id' => 456, 'url_path' => '/projects/10/tasks/456']]);
        $this->withToken('sheet-token')->postJson('/api/integrations/activecollab/tasks', $this->payload(['record_uuid' => strtoupper($uuid)]))->assertCreated();
        $export = ActiveCollabTaskExport::firstOrFail();
        $this->withToken('sheet-token')->putJson('/api/integrations/activecollab/tasks/123', $this->payload([
            'record_uuid' => $uuid, 'row_number' => 19, 'sheet_id' => 'moved-tab',
        ]))->assertOk();
        $this->assertSame($uuid, $export->fresh()->record_uuid);
        $this->assertSame('sheet-123:moved-tab:19', $export->fresh()->external_id);
        $this->withToken('sheet-token')->postJson('/api/integrations/activecollab/tasks', $this->payload([
            'record_uuid' => (string) Str::uuid(), 'row_number' => 19, 'sheet_id' => 'moved-tab',
        ]))->assertCreated()->assertJsonPath('data.task_id', 456);
        $this->assertDatabaseCount('active_collab_task_exports', 2);
        $this->assertSame(2, ActiveCollabTaskExport::where('external_id', 'sheet-123:moved-tab:19')->count());
        $this->assertSame(8, $export->attempts()->oldest('started_at')->firstOrFail()->source_payload['row_number']);
        Http::assertSentCount(3);
    }

    public function test_uuid_is_required_only_after_explicit_integration_transition(): void
    {
        $integration = $this->createIntegration();
        Http::fake();
        $this->withToken('sheet-token')->postJson('/api/integrations/activecollab/tasks', $this->payload(['record_uuid' => (string) Str::uuid()]))
            ->assertConflict()->assertJsonPath('error.code', 'legacy_binding_conflict');
        $integration->forceFill(['uuid_only' => true])->save();
        $this->withToken('sheet-token')->postJson('/api/integrations/activecollab/tasks', $this->payload())
            ->assertConflict()->assertJsonPath('error.code', 'legacy_binding_conflict');
        $this->assertDatabaseCount('active_collab_task_exports', 0);
        Http::assertNothingSent();
    }

    public function test_unknown_uuid_cannot_adopt_task_and_uuid_conflicts_do_not_change_locator(): void
    {
        $integration = $this->createIntegration();
        $integration->forceFill(['uuid_only' => true])->save();
        $uuid = (string) Str::uuid();
        $export = $this->createBoundExport($integration, ['record_uuid' => $uuid, 'last_attempt_status' => 'unknown', 'binding_status' => 'bound']);
        Http::fake();
        $this->withToken('sheet-token')->putJson('/api/integrations/activecollab/tasks/123', $this->payload(['record_uuid' => (string) Str::uuid()]))->assertConflict();
        $this->withToken('sheet-token')->putJson('/api/integrations/activecollab/tasks/999', $this->payload(['record_uuid' => $uuid]))->assertConflict();
        $this->withToken('sheet-token')->putJson('/api/integrations/activecollab/tasks/123', $this->payload(['record_uuid' => $uuid, 'row_number' => 45]))
            ->assertConflict()->assertJsonPath('error.code', 'sync_outcome_unknown');
        $this->withToken('sheet-token')->postJson('/api/integrations/activecollab/tasks', $this->payload(['record_uuid' => $uuid, 'row_number' => 45]))
            ->assertConflict()->assertJsonPath('data.task_id', 123);
        $this->assertSame(8, $export->fresh()->row_number);
        $this->assertDatabaseCount('active_collab_task_exports', 1);
        Http::assertNothingSent();
    }

    public function test_partially_migrated_integration_blocks_legacy_even_on_empty_position(): void
    {
        $integration = $this->createIntegration();
        $this->createBoundExport($integration, ['record_uuid' => (string) Str::uuid()]);
        Http::fake();
        $this->withToken('sheet-token')->postJson('/api/integrations/activecollab/tasks', $this->payload(['row_number' => 999]))
            ->assertConflict()->assertJsonPath('error.code', 'legacy_binding_conflict');
        Http::assertNothingSent();
    }

    public function test_invalid_record_uuid_is_rejected_at_request_boundary(): void
    {
        $this->createIntegration();
        Http::fake();
        $this->withToken('sheet-token')->postJson('/api/integrations/activecollab/tasks', $this->payload(['record_uuid' => 'not-a-uuid']))
            ->assertUnprocessable()->assertJsonValidationErrors('record_uuid');
        Http::assertNothingSent();
    }

    #[DataProvider('uuidStates')]
    public function test_uuid_move_preserves_operation_guards(string $status, string $binding, bool $expired, string $code, int $expectedRow): void
    {
        $integration = $this->createIntegration();
        $integration->forceFill(['uuid_only' => true])->save();
        $uuid = (string) Str::uuid();
        $export = $this->createBoundExport($integration, ['record_uuid' => $uuid, 'binding_status' => $binding,
            'last_attempt_status' => $status, 'operation_id' => (string) Str::uuid(),
            'lease_expires_at' => $expired ? now()->subMinute() : now()->addMinute()]);
        Http::fake();
        $this->withToken('sheet-token')->putJson('/api/integrations/activecollab/tasks/123', $this->payload(['record_uuid' => $uuid, 'row_number' => 19]))
            ->assertConflict()->assertJsonPath('error.code', $code);
        $this->assertSame($expectedRow, $export->fresh()->row_number);
        $this->assertSame('confirmed-hash', $export->fresh()->sync_hash);
        $this->assertSame(123, $export->fresh()->task_id);
        Http::assertNothingSent();
    }

    /** @return list<array{string, string, bool, string, int}> */
    public static function uuidStates(): array
    {
        return [
            ['running', 'bound', false, 'sync_in_progress', 8],
            ['running', 'bound', true, 'sync_outcome_unknown', 8],
            ['failed', 'remote_missing', false, 'remote_task_missing', 19],
        ];
    }

    public function test_same_uuid_is_scoped_to_integration_and_never_authorizes_another_task(): void
    {
        $first = $this->createIntegration();
        $first->forceFill(['uuid_only' => true])->save();
        $second = $first->replicate();
        $second->spreadsheet_id = 'other-sheet';
        $second->save();
        $uuid = (string) Str::uuid();
        Http::fakeSequence()->push(['single' => ['id' => 123, 'url_path' => '/projects/10/tasks/123']])
            ->push(['single' => ['id' => 456, 'url_path' => '/projects/10/tasks/456']]);
        foreach (['sheet-123', 'other-sheet'] as $sheet) {
            $this->withToken('sheet-token')->postJson('/api/integrations/activecollab/tasks', $this->payload(['spreadsheet_id' => $sheet, 'record_uuid' => $uuid]))->assertCreated();
        }
        $this->withToken('sheet-token')->putJson('/api/integrations/activecollab/tasks/123', $this->payload(['spreadsheet_id' => 'other-sheet', 'record_uuid' => $uuid]))->assertConflict();
        $this->assertSame(2, ActiveCollabTaskExport::where('record_uuid', $uuid)->count());
        Http::assertSentCount(2);
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
