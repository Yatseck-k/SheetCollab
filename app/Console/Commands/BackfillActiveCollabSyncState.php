<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Repositories\ActiveCollab\ActiveCollabExportRepository;
use Illuminate\Console\Command;

class BackfillActiveCollabSyncState extends Command
{
    protected $signature = 'integrations:backfill-sync-state {--dry-run}';

    protected $description = 'Classify legacy exports without inventing synchronization history';

    public function handle(ActiveCollabExportRepository $repository): int
    {
        $report = $repository->backfill((bool) $this->option('dry-run'));
        $this->line(json_encode($report, JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
