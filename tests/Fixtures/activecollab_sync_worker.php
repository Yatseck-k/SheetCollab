<?php

declare(strict_types=1);

use App\Data\ActiveCollab\ActiveCollabRecordUuidManifest;
use App\Data\ActiveCollab\ActiveCollabRecoveryData;
use App\Exceptions\TaskSyncConflictException;
use App\Models\AdminAuditLog;
use App\Services\ActiveCollab\ActiveCollabRecordUuidMigrationService;
use App\Services\ActiveCollab\ActiveCollabRecoveryService;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(ConsoleKernel::class)->bootstrap();
if (! $app->environment('testing') || config('database.default') !== 'mariadb'
    || config('database.connections.mariadb.database') !== 'sheetcollab_concurrency_test') {
    throw new RuntimeException('Unsafe concurrency worker configuration.');
}
$argv = $_SERVER['argv'] ?? [];
if (count($argv) !== 5) {
    throw new RuntimeException('Expected directory, name, action and row.');
}
[$script, $directory, $name, $action, $row] = $argv;
config()->set('app.debug', false);
config()->set('services.google_sheets.integration_token', 'concurrency-test-token');
Http::preventStrayRequests();
Http::fake(function () use ($directory, $name) {
    if (DB::transactionLevel() !== 0) {
        throw new RuntimeException('HTTP inside transaction.');
    }
    file_put_contents($directory.'/calls', $name."\n", FILE_APPEND | LOCK_EX);
    touch($directory.'/ready-'.$name);
    $deadline = microtime(true) + 12;
    while (! is_file($directory.'/release')) {
        if (microtime(true) > $deadline) {
            throw new RuntimeException('HTTP barrier timed out.');
        }
        usleep(10000);
    }

    $taskId = getenv('SYNC_RECORD_UUID') && $name === 'b' ? 456 : 123;

    return Http::response(['single' => ['id' => $taskId, 'url_path' => '/projects/10/tasks/'.$taskId, 'project_id' => 10, 'is_trashed' => false]]);
});
$deadline = microtime(true) + 12;
while (! is_file($directory.'/start')) {
    if (microtime(true) > $deadline) {
        throw new RuntimeException('Start barrier timed out.');
    }
    usleep(10000);
}
if ($action === 'migrate') {
    $values = json_decode(file_get_contents($directory.'/input-'.$name), true, flags: JSON_THROW_ON_ERROR);
    echo json_encode($app->make(ActiveCollabRecordUuidMigrationService::class)->migrate($values['integration_id'],
        ActiveCollabRecordUuidManifest::fromArray($values['manifest']), $values['actor_id'], 'Confirmed links in race test', false, true), JSON_THROW_ON_ERROR);
    exit;
}
if (in_array($action, ['bind', 'verify', 'confirm_absence', 'replace'], true)) {
    $values = json_decode(file_get_contents($directory.'/input-'.$name), true, flags: JSON_THROW_ON_ERROR);
    if ($values['fail_audit'] ?? false) {
        AdminAuditLog::creating(function (AdminAuditLog $log): void {
            if ($log->phase === 'result') {
                throw new RuntimeException('Simulated audit write failure.');
            }
        });
    }
    try {
        $data = ActiveCollabRecoveryData::fromInput($values['input'], $action);
        $result = $app->make(ActiveCollabRecoveryService::class)
            ->recover($values['actor_id'], $values['export_id'], $data);
        echo json_encode(['status' => 200, 'body' => $result], JSON_THROW_ON_ERROR);
    } catch (TaskSyncConflictException $e) {
        echo json_encode(['status' => $e->httpStatus, 'body' => ['error' => ['code' => $e->errorCode, 'operation_id' => $e->operationId]]], JSON_THROW_ON_ERROR);
    } catch (Throwable $e) {
        echo json_encode(['status' => 500, 'exception' => $e::class], JSON_THROW_ON_ERROR);
    }
    exit;
}
$kernel = $app->make(Kernel::class);
$request = Request::create(
    '/api/integrations/activecollab/tasks'.($action === 'update' ? '/123' : ''),
    $action === 'update' ? 'PUT' : 'POST',
    server: ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer concurrency-test-token'],
    content: json_encode([
        'record_uuid' => getenv('SYNC_RECORD_UUID') ?: null,
        'spreadsheet_id' => 'race-sheet', 'sheet_id' => '0', 'row_number' => (int) $row,
        'environment' => 'test', 'section' => 'Concurrency', 'description' => 'Race test',
    ], JSON_THROW_ON_ERROR),
);
$response = $kernel->handle($request);
echo json_encode(['status' => $response->getStatusCode(), 'body' => json_decode($response->getContent(), true)], JSON_THROW_ON_ERROR);
$kernel->terminate($request, $response);
