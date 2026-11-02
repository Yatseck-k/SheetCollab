<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Exceptions\ActiveCollabRequestException;
use App\Models\ActiveCollabConnection;
use App\Models\ActiveCollabIntegration;
use App\Repositories\ActiveCollab\ActiveCollabRepository;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Support\ActiveCollabIntegrationFixture;
use Tests\TestCase;

class ActiveCollabRepositoryTest extends TestCase
{
    private ActiveCollabRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('app.key', 'base64:J63P4m4KjJ8l0RkGxJZ4Kf9P9+K6f2R4R6V4a2M1LxQ=');
        config()->set('services.active_collab.base_url', 'https://global.example.test/api/v1');
        config()->set('services.active_collab.api_token', 'global-token');
        config()->set('services.active_collab.connect_timeout', 5);
        config()->set('services.active_collab.timeout', 30);
        Http::preventStrayRequests();
        $this->repository = new ActiveCollabRepository();
    }

    public function test_create_and_update_use_integration_connection_credentials(): void
    {
        $integration = $this->makeIntegration();
        Http::fake([
            'https://ac.example.com/api/v1/projects/10/tasks' => Http::response(['single' => ['id' => 55, 'url_path' => '/projects/10/tasks/55']]),
            'https://ac.example.com/api/v1/projects/10/tasks/55' => Http::response(['single' => ['id' => 55, 'url_path' => '/projects/10/tasks/55']]),
        ]);

        $this->repository->createTask($integration, ['name' => 'Created']);
        $this->repository->updateTask($integration, 55, ['name' => 'Updated']);

        Http::assertSentCount(2);
        Http::assertSent(fn ($request): bool => $request->hasHeader('X-Angie-AuthApiToken', ['integration-token'])
            && str_starts_with((string) $request->url(), 'https://ac.example.com/api/v1/'));
    }

    public function test_projects_and_task_lists_are_read_from_selected_connection_only(): void
    {
        $connection = $this->connection('https://selected.example.test/api/v1', 'selected-token');
        Http::fake([
            'https://selected.example.test/api/v1/projects' => Http::response([
                ['id' => 10, 'name' => 'Project A', 'is_trashed' => false],
            ]),
            'https://selected.example.test/api/v1/projects/10/task-lists' => Http::response([
                ['id' => 31, 'name' => 'Backlog'],
            ]),
        ]);

        $this->assertSame([['id' => 10, 'name' => 'Project A']], $this->repository->projectsForConnection($connection));
        $this->assertSame([['id' => 31, 'name' => 'Backlog']], $this->repository->taskListsForConnection($connection, 10));
        Http::assertSentCount(2);
        Http::assertSent(fn ($request): bool => $request->hasHeader('X-Angie-AuthApiToken', ['selected-token'])
            && ! str_contains((string) $request->url(), 'global.example.test'));
    }

    public function test_scoped_catalog_methods_use_the_integration_connection(): void
    {
        $integration = $this->makeIntegration();
        Http::fake([
            'https://ac.example.com/api/v1/projects/10/task-lists' => Http::response([['id' => 31, 'name' => 'Default']]),
            'https://ac.example.com/api/v1/projects/10' => Http::response([
                'single' => ['id' => 10, 'is_trashed' => false, 'members' => [2], 'label_ids' => [7]],
                'label_ids' => [7],
            ]),
            'https://ac.example.com/api/v1/users' => Http::response([['id' => 2, 'display_name' => 'Member'], ['id' => 3, 'display_name' => 'Other']]),
            'https://ac.example.com/api/v1/labels/task-labels' => Http::response([
                ['id' => 7, 'name' => 'Critical', 'is_global' => false], ['id' => 8, 'name' => 'Private', 'is_global' => false],
            ]),
        ]);

        $this->assertSame([['id' => 31, 'name' => 'Default']], $this->repository->taskListsForIntegration($integration));
        $this->assertSame([['id' => 2, 'display_name' => 'Member']], $this->repository->usersForIntegration($integration));
        $this->assertSame([['id' => 7, 'name' => 'Critical', 'is_global' => false]], $this->repository->labelsForIntegration($integration));
        Http::assertSentCount(5);
    }

    public function test_missing_or_inactive_connection_fails_before_http(): void
    {
        $integration = ActiveCollabIntegrationFixture::make(['active_collab_project_id' => 10]);
        $integration->setRelation('activeCollabConnection', null);
        Http::preventStrayRequests();
        $this->expectException(ActiveCollabRequestException::class);

        $this->repository->taskListsForIntegration($integration);
    }

    public function test_project_catalog_rejects_malformed_and_trashed_entries(): void
    {
        $connection = $this->connection('https://selected.example.test/api/v1', 'selected-token');
        Http::fake(['*' => Http::response([['id' => 10, 'name' => 'Trash', 'is_trashed' => true]])]);
        $this->expectException(ActiveCollabRequestException::class);

        $this->repository->projectsForConnection($connection);
    }

    public function test_connection_timeouts_remain_configured(): void
    {
        $connection = $this->connection('https://ac.example.com/api/v1', 'integration-token');
        $options = [];
        Http::fake(function ($request, array $requestOptions) use (&$options) {
            $options = $requestOptions;

            return Http::response(['single' => ['id' => 55, 'url_path' => '/projects/10/tasks/55']]);
        });
        $this->repository->createTask(ActiveCollabIntegrationFixture::make([
            'active_collab_base_url' => $connection->base_url,
            'active_collab_api_token' => 'integration-token', 'active_collab_project_id' => 10,
        ]), ['name' => 'Task']);

        $this->assertSame(5, $options['connect_timeout']);
        $this->assertSame(30, $options['timeout']);
    }

    private function makeIntegration(): ActiveCollabIntegration
    {
        return ActiveCollabIntegrationFixture::make([
            'active_collab_base_url' => 'https://ac.example.com/api/v1',
            'active_collab_project_id' => 10,
            'active_collab_api_token' => 'integration-token',
        ]);
    }

    private function connection(string $baseUrl, string $token): ActiveCollabConnection
    {
        $connection = new ActiveCollabConnection(['name' => 'Test', 'base_url' => $baseUrl, 'api_token' => $token, 'active' => true]);
        $connection->setAttribute('id', (string) Str::uuid());

        return $connection;
    }
}
