<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Repositories\ActiveCollab\ActiveCollabExportRepository;
use Illuminate\Console\Command;

class ExpireActiveCollabSyncOperations extends Command
{
    protected $signature = 'integrations:expire-sync-operations {--dry-run}';

    protected $description = 'Mark expired reservations unknown without retrying external writes';

    public function handle(ActiveCollabExportRepository $repository): int
    {
        $this->line(json_encode($repository->expireOperations((bool) $this->option('dry-run')), JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
