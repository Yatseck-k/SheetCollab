<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\ActiveCollab\ActiveCollabIntegrationTokenService;
use Illuminate\Console\Command;

class ManageActiveCollabIntegrationTokens extends Command
{
    protected $signature = 'integrations:tokens {action} {--integration=} {--actor=} {--reason=} {--name=} {--token-id=}';

    protected $description = 'Manage integration tokens and explicit legacy access with administrative audit';

    public function handle(ActiveCollabIntegrationTokenService $service): int
    {
        try {
            $result = $service->manage((string) $this->argument('action'), (string) $this->option('integration'),
                filter_var($this->option('actor'), FILTER_VALIDATE_INT) ?: 0, (string) $this->option('reason'),
                $this->option('name'), $this->option('token-id'));
        } catch (\Throwable) {
            $this->error('Token action failed. Verify arguments, permissions and storage; no token is disclosed.');

            return self::FAILURE;
        }
        $this->line(json_encode($result, JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
