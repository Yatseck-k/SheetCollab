<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ActiveCollabIntegration;
use App\Repositories\ActiveCollab\ActiveCollabIntegrationRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ActiveCollabIntegrationRepositoryTest extends TestCase
{
    use RefreshDatabase;

    private ActiveCollabIntegrationRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('app.key', 'base64:J63P4m4KjJ8l0RkGxJZ4Kf9P9+K6f2R4R6V4a2M1LxQ=');
        $this->repository = new ActiveCollabIntegrationRepository();
    }

    public function test_find_by_spreadsheet_id_returns_active_integration(): void
    {
        ActiveCollabIntegration::create([
            'name' => 'Active Integration',
            'spreadsheet_id' => 'my-sheet',
            'active_collab_base_url' => 'https://ac.example.com/api/v1',
            'active_collab_project_id' => 10,
            'active_collab_task_list_id' => 31,
            'active' => true,
        ]);

        $result = $this->repository->findBySpreadsheetId('my-sheet');

        $this->assertNotNull($result);
        $this->assertSame('my-sheet', $result->spreadsheet_id);
        $this->assertTrue($result->active);
    }

    public function test_find_by_spreadsheet_id_returns_null_for_inactive_integration(): void
    {
        ActiveCollabIntegration::create([
            'name' => 'Inactive Integration',
            'spreadsheet_id' => 'my-sheet',
            'active_collab_base_url' => 'https://ac.example.com/api/v1',
            'active_collab_project_id' => 10,
            'active_collab_task_list_id' => 31,
            'active' => false,
        ]);

        $result = $this->repository->findBySpreadsheetId('my-sheet');

        $this->assertNull($result);
    }

    public function test_find_by_spreadsheet_id_returns_null_when_no_integration_exists(): void
    {
        $result = $this->repository->findBySpreadsheetId('nonexistent-sheet');

        $this->assertNull($result);
    }

    public function test_find_by_spreadsheet_id_ignores_other_spreadsheet_ids(): void
    {
        ActiveCollabIntegration::create([
            'name' => 'Other Integration',
            'spreadsheet_id' => 'other-sheet',
            'active_collab_base_url' => 'https://ac.example.com/api/v1',
            'active_collab_project_id' => 10,
            'active_collab_task_list_id' => 31,
            'active' => true,
        ]);

        $result = $this->repository->findBySpreadsheetId('my-sheet');

        $this->assertNull($result);
    }
}
