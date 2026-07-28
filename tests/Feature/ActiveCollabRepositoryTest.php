<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ActiveCollabIntegration;
use App\Repositories\ActiveCollab\ActiveCollabRepository;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ActiveCollabRepositoryTest extends TestCase
{
    private ActiveCollabRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('app.key', 'base64:J63P4m4KjJ8l0RkGxJZ4Kf9P9+K6f2R4R6V4a2M1LxQ=');
        config()->set('services.active_collab.base_url', 'https://ac.example.com/api/v1');
        config()->set('services.active_collab.api_token', 'global-token');
        config()->set('services.active_collab.project_id', 10);

        $this->repository = new ActiveCollabRepository();
    }

    // ── createTask ────────────────────────────────────────────────────────────────

    public function test_create_task_posts_to_correct_url_with_auth_header(): void
    {
        Http::fake([
            'https://ac.example.com/api/v1/projects/10/tasks' => Http::response([
                'single' => ['id' => 55, 'url_path' => '/projects/10/tasks/55'],
            ], 200),
        ]);

        $integration = $this->makeIntegration();
        $result = $this->repository->createTask($integration, ['name' => 'Test Task']);

        $this->assertSame(55, $result['single']['id']);

        Http::assertSent(fn ($request): bool => $request->method() === 'POST'
            && $request->url() === 'https://ac.example.com/api/v1/projects/10/tasks'
            && $request->hasHeader('X-Angie-AuthApiToken', ['integration-token'])
            && $request->data()['name'] === 'Test Task');
    }

    // ── updateTask ────────────────────────────────────────────────────────────────

    public function test_update_task_puts_to_correct_task_url_with_auth_header(): void
    {
        Http::fake([
            'https://ac.example.com/api/v1/projects/10/tasks/99' => Http::response([
                'single' => ['id' => 99, 'url_path' => '/projects/10/tasks/99'],
            ], 200),
        ]);

        $integration = $this->makeIntegration();
        $result = $this->repository->updateTask($integration, 99, ['name' => 'Updated']);

        $this->assertSame(99, $result['single']['id']);

        Http::assertSent(fn ($request): bool => $request->method() === 'PUT'
            && str_ends_with((string) $request->url(), '/tasks/99')
            && $request->hasHeader('X-Angie-AuthApiToken', ['integration-token']));
    }

    // ── getTaskLists ──────────────────────────────────────────────────────────────

    public function test_get_task_lists_uses_global_config_credentials(): void
    {
        Http::fake([
            'https://ac.example.com/api/v1/projects/10/task-lists' => Http::response([
                'data' => [['id' => 11, 'name' => 'Backlog']],
            ], 200),
        ]);

        $result = $this->repository->getTaskLists();

        $this->assertSame(11, $result['data'][0]['id']);

        Http::assertSent(fn ($request): bool => $request->hasHeader('X-Angie-AuthApiToken', ['global-token'])
            && $request->url() === 'https://ac.example.com/api/v1/projects/10/task-lists');
    }

    // ── getTaskListsForConnection ─────────────────────────────────────────────────

    public function test_get_task_lists_for_connection_uses_provided_base_url_and_token(): void
    {
        Http::fake([
            'https://custom.ac.com/api/v1/projects/5/task-lists' => Http::response(['data' => []], 200),
        ]);

        $this->repository->getTaskListsForConnection(
            'https://custom.ac.com/api/v1',
            'custom-token',
            5,
        );

        Http::assertSent(fn ($request): bool => $request->url() === 'https://custom.ac.com/api/v1/projects/5/task-lists'
            && $request->hasHeader('X-Angie-AuthApiToken', ['custom-token']));
    }

    // ── createTaskListForConnection ───────────────────────────────────────────────

    public function test_create_task_list_for_connection_posts_name(): void
    {
        Http::fake([
            'https://custom.ac.com/api/v1/projects/5/task-lists' => Http::response([
                'single' => ['id' => 7, 'name' => 'Sprint 1'],
            ], 200),
        ]);

        $result = $this->repository->createTaskListForConnection(
            'https://custom.ac.com/api/v1',
            'custom-token',
            5,
            'Sprint 1',
        );

        $this->assertSame(7, $result['single']['id']);

        Http::assertSent(fn ($request): bool => $request->method() === 'POST'
            && $request->data()['name'] === 'Sprint 1');
    }

    // ── getUsers ──────────────────────────────────────────────────────────────────

    public function test_get_users_hits_users_endpoint_with_global_token(): void
    {
        Http::fake([
            'https://ac.example.com/api/v1/users' => Http::response([
                'data' => [['id' => 3, 'display_name' => 'Tony Yats']],
            ], 200),
        ]);

        $result = $this->repository->getUsers();

        $this->assertSame('Tony Yats', $result['data'][0]['display_name']);

        Http::assertSent(fn ($request): bool => str_ends_with((string) $request->url(), '/users')
            && $request->hasHeader('X-Angie-AuthApiToken', ['global-token']));
    }

    // ── getLabels ─────────────────────────────────────────────────────────────────

    public function test_get_labels_hits_labels_endpoint_with_global_token(): void
    {
        Http::fake([
            'https://ac.example.com/api/v1/labels' => Http::response([
                'data' => [['id' => 7, 'name' => 'Critical']],
            ], 200),
        ]);

        $result = $this->repository->getLabels();

        $this->assertSame(7, $result['data'][0]['id']);
    }

    // ── error handling ────────────────────────────────────────────────────────────

    public function test_send_throws_on_4xx_response(): void
    {
        Http::fake(['*' => Http::response(['message' => 'Not Found'], 404)]);

        $this->expectException(RequestException::class);

        $this->repository->getLabels();
    }

    public function test_send_throws_on_5xx_response(): void
    {
        Http::fake(['*' => Http::response(['message' => 'Server Error'], 500)]);

        $this->expectException(RequestException::class);

        $this->repository->getUsers();
    }

    // ── helper ───────────────────────────────────────────────────────────────────

    private function makeIntegration(): ActiveCollabIntegration
    {
        return new ActiveCollabIntegration([
            'active_collab_base_url' => 'https://ac.example.com/api/v1',
            'active_collab_project_id' => 10,
            'active_collab_api_token' => 'integration-token',
        ]);
    }
}
