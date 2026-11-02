<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\ActiveCollabIntegration;
use App\Models\User;
use App\Repositories\ActiveCollab\ActiveCollabIntegrationRepository;
use App\Services\ActiveCollab\ActiveCollabIntegrationSettingsService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class CreateActiveCollabIntegration extends Command
{
    protected $signature = 'integrations:create-activecollab
        {--name= : Integration name}
        {--spreadsheet-id= : Google Sheets spreadsheet ID}
        {--connection-id= : Existing ActiveCollab connection UUID}
        {--actor= : Orchid administrator user ID for audit}
        {--project-id= : ActiveCollab project ID}
        {--task-list-id= : Default ActiveCollab task list ID or name}';

    protected $description = 'Create an ActiveCollab integration using an existing connection';

    public function __construct(
        private readonly ActiveCollabIntegrationSettingsService $settingsService,
        private readonly ActiveCollabIntegrationRepository $integrations,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $actorId = filter_var($this->option('actor'), FILTER_VALIDATE_INT);
        if ($actorId === false || $actorId < 1) {
            $this->error('Specify --actor with an existing Orchid administrator user ID.');

            return self::INVALID;
        }

        $data = [
            'name' => $this->option('name') ?? $this->ask('Integration name'),
            'spreadsheet_id' => $this->option('spreadsheet-id') ?? $this->ask('Google Sheets spreadsheet ID or full URL'),
            'connection_id' => $this->option('connection-id') ?? $this->ask('ActiveCollab connection UUID'),
            'active_collab_project_id' => $this->option('project-id') ?? $this->ask('ActiveCollab project ID or project URL'),
            'active_collab_task_list_id' => $this->option('task-list-id') ?? $this->ask('Existing task list ID or name (optional)', ''),
            'active' => true,
        ];

        try {
            Gate::forUser(User::findOrFail($actorId))->authorize('create', ActiveCollabIntegration::class);
            $data = $this->settingsService->prepareForStorage($data);
            $integration = $this->integrations->saveSettings(null, $data, $actorId);
        } catch (ValidationException $exception) {
            foreach ($exception->errors() as $messages) {
                foreach ($messages as $message) {
                    $this->error($message);
                }
            }

            return self::FAILURE;
        } catch (\Throwable) {
            $this->error('Integration could not be created. Check the connection and settings.');

            return self::FAILURE;
        }

        $this->info("Integration created: {$integration->id}");
        $this->table(['Field', 'Value'], [
            ['ID', $integration->id],
            ['Name', $integration->name],
            ['Spreadsheet ID', $integration->spreadsheet_id],
            ['Connection ID', $integration->connection_id],
            ['Project ID', $integration->active_collab_project_id],
            ['Task List ID', $integration->active_collab_task_list_id],
        ]);

        return self::SUCCESS;
    }
}
