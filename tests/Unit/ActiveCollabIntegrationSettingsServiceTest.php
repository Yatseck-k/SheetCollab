<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\ActiveCollabConnection;
use App\Repositories\ActiveCollab\ActiveCollabConnectionRepository;
use App\Repositories\ActiveCollab\ActiveCollabRepository;
use App\Services\ActiveCollab\ActiveCollabIntegrationSettingsService;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ActiveCollabIntegrationSettingsServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config()->set('app.key', 'base64:J63P4m4KjJ8l0RkGxJZ4Kf9P9+K6f2R4R6V4a2M1LxQ=');
    }

    public function test_it_uses_an_explicit_connection_and_existing_task_list_without_external_writes(): void
    {
        $connection = $this->connection();
        $connections = $this->createMock(ActiveCollabConnectionRepository::class);
        $connections->expects($this->once())->method('findActive')->with($connection->id)->willReturn($connection);
        $repository = $this->createMock(ActiveCollabRepository::class);
        $repository->expects($this->once())->method('projectsForConnection')->with($connection)
            ->willReturn([['id' => 42, 'name' => 'Bugs']]);
        $repository->expects($this->once())->method('taskListsForConnection')->with($connection, 42)
            ->willReturn([['id' => 31, 'name' => 'Default']]);
        $service = new ActiveCollabIntegrationSettingsService($repository, $connections);

        $result = $service->prepareForStorage([
            'name' => 'Bugs', 'spreadsheet_id' => 'https://docs.google.com/spreadsheets/d/abc123XYZ/edit#gid=0',
            'connection_id' => $connection->id, 'active_collab_project_id' => 'https://ac.example.com/projects/42',
            'active_collab_task_list_id' => '',
        ]);

        $this->assertSame('abc123XYZ', $result['spreadsheet_id']);
        $this->assertSame($connection->id, $result['connection_id']);
        $this->assertSame(42, $result['active_collab_project_id']);
        $this->assertSame(31, $result['active_collab_task_list_id']);
        $this->assertArrayNotHasKey('active_collab_api_token', $result);
        $this->assertArrayNotHasKey('active_collab_base_url', $result);
    }

    public function test_it_resolves_a_task_list_name_from_the_selected_project(): void
    {
        [$service, $connection] = $this->serviceForCatalogs(
            [['id' => 42, 'name' => 'Bugs']],
            [['id' => 31, 'name' => 'Default'], ['id' => 77, 'name' => 'Backend']],
        );

        $result = $service->prepareForStorage([
            'name' => 'Bugs', 'spreadsheet_id' => 'sheet-id', 'connection_id' => $connection->id,
            'active_collab_project_id' => '42', 'active_collab_task_list_id' => ' backend ',
        ]);

        $this->assertSame(77, $result['active_collab_task_list_id']);
    }

    public function test_it_rejects_a_task_list_name_instead_of_creating_a_remote_list(): void
    {
        [$service, $connection] = $this->serviceForCatalogs(
            [['id' => 42, 'name' => 'Bugs']], [['id' => 31, 'name' => 'Default']],
        );
        $this->expectException(ValidationException::class);

        $service->prepareForStorage([
            'name' => 'Bugs', 'spreadsheet_id' => 'sheet-id', 'connection_id' => $connection->id,
            'active_collab_project_id' => 42, 'active_collab_task_list_id' => 'Backend',
        ]);
    }

    public function test_it_rejects_a_project_not_available_in_the_selected_connection(): void
    {
        [$service, $connection] = $this->serviceForCatalogs([['id' => 42, 'name' => 'Bugs']], []);
        $this->expectException(ValidationException::class);

        $service->prepareForStorage([
            'name' => 'Bugs', 'spreadsheet_id' => 'sheet-id', 'connection_id' => $connection->id,
            'active_collab_project_id' => 43,
        ]);
    }

    public function test_normalizes_spreadsheet_and_project_ids(): void
    {
        [$service] = $this->serviceForCatalogs([], []);
        $this->assertSame('abc123XYZ', $service->normalizeSpreadsheetId('https://docs.google.com/spreadsheets/d/abc123XYZ/edit'));
        $this->assertSame(42, $service->normalizeProjectId('https://ac.example.com/projects/42'));
        $this->assertSame(99, $service->normalizeProjectId('99'));
    }

    private function connection(): ActiveCollabConnection
    {
        $connection = new ActiveCollabConnection([
            'name' => 'Test connection', 'base_url' => 'https://ac.example.com/api/v1',
            'api_token' => 'test-token', 'active' => true,
        ]);
        $connection->setAttribute('id', (string) Str::uuid());

        return $connection;
    }

    /** @param list<array{id: int, name: string}> $projects
     * @param  list<array{id: int, name: string}>  $taskLists
     * @return array{ActiveCollabIntegrationSettingsService, ActiveCollabConnection}
     */
    private function serviceForCatalogs(array $projects, array $taskLists): array
    {
        $connection = $this->connection();
        $connections = $this->createMock(ActiveCollabConnectionRepository::class);
        $connections->method('findActive')->willReturn($connection);
        $repository = $this->createMock(ActiveCollabRepository::class);
        $repository->method('projectsForConnection')->willReturn($projects);
        $repository->method('taskListsForConnection')->willReturn($taskLists);

        return [new ActiveCollabIntegrationSettingsService($repository, $connections), $connection];
    }
}
