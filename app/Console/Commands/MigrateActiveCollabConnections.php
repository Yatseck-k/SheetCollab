<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\ActiveCollab\ActiveCollabConnectionBackfillService;
use Illuminate\Console\Command;

class MigrateActiveCollabConnections extends Command
{
    protected $signature = 'integrations:migrate-activecollab-connections {--apply : Persist the reviewed mapping} {--writers-stopped : Confirm all sync writers are paused}';

    protected $description = 'Preview or migrate legacy ActiveCollab credentials into reusable connections';

    public function handle(ActiveCollabConnectionBackfillService $service): int
    {
        $apply = (bool) $this->option('apply');
        if ($apply && ! $this->option('writers-stopped')) {
            $this->error('Apply requires --writers-stopped. Pause all sync writers before migration.');

            return self::INVALID;
        }

        $report = $service->run($apply);
        $this->table(['Integration', 'Action', 'Connection', 'Host', 'Reason'], array_map(
            static fn (array $row): array => [$row['integration_id'], $row['action'], $row['connection_id'] ?? '—', $row['host'] ?? '—', $row['reason'] ?? '—'],
            $report,
        ));
        $unresolved = count(array_filter($report, static fn (array $row): bool => $row['action'] === 'unresolved'));
        $this->info(sprintf('%s: %d integrations reviewed, %d unresolved.', $apply ? 'Applied' : 'Dry run only', count($report), $unresolved));

        return $unresolved === 0 ? self::SUCCESS : self::FAILURE;
    }
}
