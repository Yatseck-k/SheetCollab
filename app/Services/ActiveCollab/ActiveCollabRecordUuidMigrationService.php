<?php

declare(strict_types=1);

namespace App\Services\ActiveCollab;

use App\Data\ActiveCollab\ActiveCollabRecordUuidManifest;
use App\Repositories\ActiveCollab\ActiveCollabRecordUuidMigrationRepository;
use Illuminate\Support\Facades\Validator;

class ActiveCollabRecordUuidMigrationService
{
    public function __construct(private readonly ActiveCollabRecordUuidMigrationRepository $repository) {}

    /** @return array<string, mixed> */
    public function migrate(string $integrationId, ActiveCollabRecordUuidManifest $manifest, int $actorId, string $reason, bool $dryRun, bool $enable): array
    {
        Validator::make(['integration' => $integrationId, 'actor' => $actorId, 'reason' => $reason], [
            'integration' => ['required', 'uuid'], 'actor' => ['required', 'integer', 'min:1'], 'reason' => ['required', 'string', 'min:10', 'max:1000'],
        ])->validate();

        return $this->repository->migrate($integrationId, $manifest, $actorId, $reason, $dryRun, $enable);
    }
}
