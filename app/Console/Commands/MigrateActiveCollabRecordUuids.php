<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Data\ActiveCollab\ActiveCollabRecordUuidManifest;
use App\Services\ActiveCollab\ActiveCollabRecordUuidMigrationService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;

class MigrateActiveCollabRecordUuids extends Command
{
    protected $signature = 'integrations:migrate-record-uuids {--integration=} {--manifest=} {--actor=} {--reason=} {--dry-run} {--enable-uuid-only}';

    protected $description = 'Bind confirmed Sheets UUIDs while the integration is inactive, without ActiveCollab writes';

    public function handle(ActiveCollabRecordUuidMigrationService $service): int
    {
        $path = (string) $this->option('manifest');
        $handle = $path !== '' && is_file($path) && is_readable($path) ? fopen($path, 'rb') : false;
        if ($handle === false) {
            $this->error('A readable local manifest is required.');

            return self::FAILURE;
        }
        try {
            $json = stream_get_contents($handle, ActiveCollabRecordUuidManifest::MAX_BYTES + 1);
        } finally {
            fclose($handle);
        }
        if ($json === false || strlen($json) > ActiveCollabRecordUuidManifest::MAX_BYTES) {
            $this->error('Manifest exceeds 4 MiB or could not be read.');

            return self::FAILURE;
        }
        try {
            $input = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
            if (! is_array($input)) {
                throw new \JsonException();
            }
            $report = $service->migrate((string) $this->option('integration'), ActiveCollabRecordUuidManifest::fromArray($input),
                filter_var($this->option('actor'), FILTER_VALIDATE_INT) ?: 0, (string) $this->option('reason'),
                (bool) $this->option('dry-run'), (bool) $this->option('enable-uuid-only'));
        } catch (ValidationException|\JsonException) {
            $this->error('Invalid manifest or command arguments. No identity was changed.');

            return self::FAILURE;
        } catch (AuthorizationException|ModelNotFoundException) {
            $this->error('Actor or integration is unavailable or access is denied.');

            return self::FAILURE;
        }
        $this->line(json_encode($report, JSON_THROW_ON_ERROR));

        return $report['conflicts'] === [] && $report['unresolved'] === [] ? self::SUCCESS : self::FAILURE;
    }
}
