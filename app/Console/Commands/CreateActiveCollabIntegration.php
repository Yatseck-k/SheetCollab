<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\ActiveCollabIntegration;
use App\Services\ActiveCollab\ActiveCollabIntegrationSettingsService;
use Illuminate\Console\Command;

class CreateActiveCollabIntegration extends Command
{
    protected $signature = 'integrations:create-activecollab
        {--name= : Integration name}
        {--spreadsheet-id= : Google Sheets spreadsheet ID}
        {--base-url= : ActiveCollab API base URL}
        {--project-id= : ActiveCollab project ID}
        {--task-list-id= : Default ActiveCollab task list ID or name}
        {--token= : ActiveCollab API token}';

    protected $description = 'Create a new ActiveCollab integration record';

    public function __construct(
        private readonly ActiveCollabIntegrationSettingsService $settingsService,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $name = $this->option('name') ?? $this->ask('Integration name');
        $spreadsheetId = $this->option('spreadsheet-id') ?? $this->ask('Google Sheets spreadsheet ID or full URL');
        $baseUrl = $this->option('base-url') ?? $this->ask('ActiveCollab API base URL or regular instance URL', (string) config('services.active_collab.base_url'));
        $projectId = $this->option('project-id') ?? $this->ask('ActiveCollab project ID or project URL', (string) config('services.active_collab.project_id'));
        $taskListId = $this->option('task-list-id') ?? $this->ask('Default task list ID or name (optional, Enter for auto-detect)', '');
        $token = $this->option('token') ?? $this->secret('ActiveCollab API token');

        $normalizedSpreadsheetId = $this->settingsService->normalizeSpreadsheetId($spreadsheetId);

        if (ActiveCollabIntegration::where('spreadsheet_id', $normalizedSpreadsheetId)->exists()) {
            $this->error("Integration for spreadsheet \"{$spreadsheetId}\" already exists.");

            return self::FAILURE;
        }

        try {
            $integration = ActiveCollabIntegration::create($this->settingsService->prepareForStorage([
                'name' => $name,
                'spreadsheet_id' => $spreadsheetId,
                'active_collab_base_url' => $baseUrl,
                'active_collab_project_id' => $projectId,
                'active_collab_task_list_id' => $taskListId,
                'active_collab_api_token' => $token,
                'active' => true,
            ]));
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info("Integration created: {$integration->id}");
        $this->table(
            ['Field', 'Value'],
            [
                ['ID', $integration->id],
                ['Name', $integration->name],
                ['Spreadsheet ID', $integration->spreadsheet_id],
                ['Project ID', $integration->active_collab_project_id],
                ['Task List ID', $integration->active_collab_task_list_id],
            ],
        );

        return self::SUCCESS;
    }
}
