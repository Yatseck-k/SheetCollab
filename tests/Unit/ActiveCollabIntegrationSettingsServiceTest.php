<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Repositories\ActiveCollab\ActiveCollabRepository;
use App\Services\ActiveCollab\ActiveCollabIntegrationSettingsService;
use Tests\TestCase;

class ActiveCollabIntegrationSettingsServiceTest extends TestCase
{
    public function test_it_normalizes_spreadsheet_and_activecollab_settings(): void
    {
        $repository = $this->createMock(ActiveCollabRepository::class);
        $repository->expects($this->once())
            ->method('getTaskListsForConnection')
            ->with('https://ac.example.com/api/v1', 'secret-token', 42)
            ->willReturn([
                ['id' => 31, 'name' => 'Default'],
            ]);

        config()->set('services.active_collab.api_token', 'secret-token');
        config()->set('services.active_collab.project_id', 42);

        $service = new ActiveCollabIntegrationSettingsService($repository);

        $result = $service->prepareForStorage([
            'name' => 'Bugs',
            'spreadsheet_id' => 'https://docs.google.com/spreadsheets/d/abc123XYZ/edit#gid=0',
            'active_collab_base_url' => 'https://ac.example.com',
            'active_collab_project_id' => 'https://ac.example.com/projects/42',
            'active_collab_task_list_id' => '',
            'active_collab_api_token' => '',
        ]);

        $this->assertSame('abc123XYZ', $result['spreadsheet_id']);
        $this->assertSame('https://ac.example.com/api/v1', $result['active_collab_base_url']);
        $this->assertSame(42, $result['active_collab_project_id']);
        $this->assertSame(31, $result['active_collab_task_list_id']);
        $this->assertSame('secret-token', $result['active_collab_api_token']);
    }

    public function test_it_keeps_explicit_task_list_id_without_api_lookup(): void
    {
        $repository = $this->createMock(ActiveCollabRepository::class);
        $repository->expects($this->never())
            ->method('getTaskListsForConnection');

        $service = new ActiveCollabIntegrationSettingsService($repository);

        $result = $service->prepareForStorage([
            'name' => 'Bugs',
            'spreadsheet_id' => 'sheet-id',
            'active_collab_base_url' => 'https://ac.example.com/api/v1',
            'active_collab_project_id' => '10',
            'active_collab_task_list_id' => '77',
            'active_collab_api_token' => 'direct-token',
        ]);

        $this->assertSame(77, $result['active_collab_task_list_id']);
    }

    public function test_it_resolves_task_list_by_name_from_existing_project_lists(): void
    {
        $repository = $this->createMock(ActiveCollabRepository::class);
        $repository->expects($this->once())
            ->method('getTaskListsForConnection')
            ->with('https://ac.example.com/api/v1', 'direct-token', 10)
            ->willReturn([
                ['id' => 31, 'name' => 'Default'],
                ['id' => 77, 'name' => 'Backend'],
            ]);
        $repository->expects($this->never())
            ->method('createTaskListForConnection');

        $service = new ActiveCollabIntegrationSettingsService($repository);

        $result = $service->prepareForStorage([
            'name' => 'Bugs',
            'spreadsheet_id' => 'sheet-id',
            'active_collab_base_url' => 'https://ac.example.com/api/v1',
            'active_collab_project_id' => '10',
            'active_collab_task_list_id' => ' backend ',
            'active_collab_api_token' => 'direct-token',
        ]);

        $this->assertSame(77, $result['active_collab_task_list_id']);
    }

    public function test_it_creates_task_list_when_name_is_missing_in_project(): void
    {
        $repository = $this->createMock(ActiveCollabRepository::class);
        $repository->expects($this->once())
            ->method('getTaskListsForConnection')
            ->with('https://ac.example.com/api/v1', 'direct-token', 10)
            ->willReturn([
                ['id' => 31, 'name' => 'Default'],
            ]);
        $repository->expects($this->once())
            ->method('createTaskListForConnection')
            ->with('https://ac.example.com/api/v1', 'direct-token', 10, 'Backend')
            ->willReturn([
                'single' => [
                    'id' => 91,
                    'name' => 'Backend',
                ],
            ]);

        $service = new ActiveCollabIntegrationSettingsService($repository);

        $result = $service->prepareForStorage([
            'name' => 'Bugs',
            'spreadsheet_id' => 'sheet-id',
            'active_collab_base_url' => 'https://ac.example.com/api/v1',
            'active_collab_project_id' => '10',
            'active_collab_task_list_id' => 'Backend',
            'active_collab_api_token' => 'direct-token',
        ]);

        $this->assertSame(91, $result['active_collab_task_list_id']);
    }

    // ── normalizeBaseUrl ──────────────────────────────────────────────────────────

    public function test_normalize_base_url_keeps_existing_api_v1_url(): void
    {
        $service = $this->makeService();

        $result = $service->normalizeBaseUrl('https://ac.example.com/api/v1');

        $this->assertSame('https://ac.example.com/api/v1', $result);
    }

    public function test_normalize_base_url_strips_trailing_slash_and_adds_api_v1(): void
    {
        $service = $this->makeService();

        $result = $service->normalizeBaseUrl('https://ac.example.com/');

        $this->assertSame('https://ac.example.com/api/v1', $result);
    }

    public function test_normalize_base_url_strips_projects_path_before_adding_api(): void
    {
        $service = $this->makeService();

        $result = $service->normalizeBaseUrl('https://ac.example.com/api/v1/projects/10');

        $this->assertSame('https://ac.example.com/api/v1', $result);
    }

    // ── normalizeProjectId ────────────────────────────────────────────────────────

    public function test_normalize_project_id_extracts_id_from_project_url(): void
    {
        $service = $this->makeService();

        $result = $service->normalizeProjectId('https://ac.example.com/projects/42');

        $this->assertSame(42, $result);
    }

    public function test_normalize_project_id_parses_numeric_string(): void
    {
        $service = $this->makeService();

        $result = $service->normalizeProjectId('99');

        $this->assertSame(99, $result);
    }

    public function test_normalize_project_id_throws_on_non_numeric_invalid_value(): void
    {
        $service = $this->makeService();

        $this->expectException(\InvalidArgumentException::class);

        $service->normalizeProjectId('not-an-id');
    }

    // ── resolveApiToken ───────────────────────────────────────────────────────────

    public function test_resolve_api_token_throws_when_both_value_and_config_are_empty(): void
    {
        config()->set('services.active_collab.api_token', '');

        $service = $this->makeService();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Укажите ActiveCollab API Token');

        $service->resolveApiToken(null);
    }

    public function test_resolve_api_token_returns_provided_value_when_set(): void
    {
        $service = $this->makeService();

        $this->assertSame('my-token', $service->resolveApiToken('my-token'));
    }

    // ── normalizeSpreadsheetId ────────────────────────────────────────────────────

    public function test_normalize_spreadsheet_id_extracts_id_from_full_url(): void
    {
        $service = $this->makeService();

        $result = $service->normalizeSpreadsheetId(
            'https://docs.google.com/spreadsheets/d/abc123XYZ/edit#gid=0'
        );

        $this->assertSame('abc123XYZ', $result);
    }

    public function test_normalize_spreadsheet_id_returns_plain_id_unchanged(): void
    {
        $service = $this->makeService();

        $this->assertSame('sheet-id', $service->normalizeSpreadsheetId('sheet-id'));
    }

    public function test_normalize_spreadsheet_id_throws_when_empty(): void
    {
        $service = $this->makeService();

        $this->expectException(\InvalidArgumentException::class);

        $service->normalizeSpreadsheetId('   ');
    }

    // ── resolveTaskListId – edge cases ────────────────────────────────────────────

    public function test_resolve_task_list_id_extracts_id_from_task_list_url(): void
    {
        $repository = $this->createMock(ActiveCollabRepository::class);
        $repository->expects($this->never())->method('getTaskListsForConnection');

        $service = new ActiveCollabIntegrationSettingsService($repository);

        $result = $service->resolveTaskListId(
            'https://ac.example.com/api/v1/projects/10/task-lists/77',
            'https://ac.example.com/api/v1',
            'token',
            10,
        );

        $this->assertSame(77, $result);
    }

    public function test_resolve_task_list_id_throws_when_value_is_empty_and_project_has_no_lists(): void
    {
        $repository = $this->createMock(ActiveCollabRepository::class);
        $repository->expects($this->once())
            ->method('getTaskListsForConnection')
            ->willReturn([]);

        $service = new ActiveCollabIntegrationSettingsService($repository);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Не удалось определить Task List ID автоматически');

        $service->resolveTaskListId('', 'https://ac.example.com/api/v1', 'token', 10);
    }

    // ── helper ────────────────────────────────────────────────────────────────────

    private function makeService(): ActiveCollabIntegrationSettingsService
    {
        $repository = $this->createMock(ActiveCollabRepository::class);

        return new ActiveCollabIntegrationSettingsService($repository);
    }
}
