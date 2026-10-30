<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Data\ActiveCollab\ActiveCollabRecordUuidManifest;
use App\Data\ActiveCollab\ActiveCollabTaskData;
use App\Models\ActiveCollabIntegration;
use App\Models\ActiveCollabTaskExport;
use App\Models\User;
use App\Services\ActiveCollab\ActiveCollabRecordUuidMigrationService;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class ActiveCollabSyncConcurrencyTest extends TestCase
{
    private string $directory;

    /** @var list<Process> */
    private array $workers = [];

    protected function setUp(): void
    {
        parent::setUp();
        if (config('database.default') === 'sqlite') {
            $this->markTestSkipped('Run with phpunit.mariadb.xml against isolated MariaDB.');
        }
        $this->assertTrue(app()->environment('testing'));
        $this->assertSame('mariadb', config('database.default'));
        $this->assertSame('sheetcollab_concurrency_test', config('database.connections.mariadb.database'));
        $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);
        config()->set('app.key', 'base64:J63P4m4KjJ8l0RkGxJZ4Kf9P9+K6f2R4R6V4a2M1LxQ=');
        ActiveCollabIntegration::create([
            'name' => 'Concurrency', 'spreadsheet_id' => 'race-sheet',
            'active_collab_base_url' => 'https://ac.example.test/api/v1',
            'active_collab_api_token' => 'test-ac-secret', 'active_collab_project_id' => 10,
            'active_collab_task_list_id' => 31, 'active' => true,
        ]);
        $this->directory = sys_get_temp_dir().'/sheetcollab-race-'.bin2hex(random_bytes(8));
        mkdir($this->directory, 0700);
    }

    protected function tearDown(): void
    {
        foreach ($this->workers as $worker) {
            $worker->stop(0);
        }
        if (isset($this->directory)) {
            foreach (glob($this->directory.'/*') ?: [] as $file) {
                unlink($file);
            }
            rmdir($this->directory);
        }
        parent::tearDown();
    }

    /** @return array<string, array{string}> */
    public static function races(): array
    {
        return ['first insert' => ['absent'], 'existing idle' => ['idle'], 'bound update' => ['update'], 'simultaneous insert' => ['simultaneous']];
    }

    #[DataProvider('races')]
    public function test_one_request_owns_the_external_call(string $scenario): void
    {
        if (in_array($scenario, ['idle', 'update'], true)) {
            $integration = ActiveCollabIntegration::firstOrFail();
            $attributes = [
                'integration_id' => $integration->id, 'external_id' => 'race-sheet:0:8',
                'spreadsheet_id' => 'race-sheet', 'sheet_id' => '0', 'row_number' => 8,
                'project_id' => 10, 'source_payload' => [], 'status' => 'pending',
            ];
            if (Schema::hasColumn('active_collab_task_exports', 'last_attempt_status')) {
                $attributes += ['last_attempt_status' => 'idle', 'binding_status' => 'unbound'];
            }
            if ($scenario === 'update') {
                $attributes = array_merge($attributes, [
                    'status' => 'created', 'task_id' => 123,
                    'task_url' => 'https://ac.example.test/projects/10/tasks/123',
                ]);
                if (isset($attributes['last_attempt_status'])) {
                    $attributes['last_attempt_status'] = 'succeeded';
                    $attributes['binding_status'] = 'bound';
                }
            }
            ActiveCollabTaskExport::create($attributes);
        }
        $action = $scenario === 'update' ? 'update' : 'create';
        $a = $this->startWorker('a', $action);
        if ($scenario === 'simultaneous') {
            $b = $this->startWorker('b', $action);
            touch($this->directory.'/start');
            $this->waitUntil(fn (): bool => is_file($this->directory.'/ready-a') || is_file($this->directory.'/ready-b'));
            $loser = is_file($this->directory.'/ready-a') ? $b : $a;
        } else {
            touch($this->directory.'/start');
            $this->waitUntil(fn (): bool => is_file($this->directory.'/ready-a'));
            $b = $this->startWorker('b', $action);
            $loser = $b;
        }
        $this->waitUntil(fn (): bool => ! $loser->isRunning() || count($this->calls()) > 1);
        $this->assertCount(1, $this->calls(), 'A competing request sent another HTTP call.');
        $loser->wait();
        $loserResponse = json_decode($loser->getOutput(), true);
        $this->assertSame(409, $loserResponse['status'] ?? null, $loser->getErrorOutput().$loser->getOutput());
        $this->assertSame('sync_in_progress', $loserResponse['body']['error']['code']);
        $this->assertSame(ActiveCollabTaskExport::firstOrFail()->operation_id, $loserResponse['body']['error']['operation_id']);
        touch($this->directory.'/release');
        $winner = $loser === $a ? $b : $a;
        $winner->wait();
        $this->assertSame($action === 'update' ? 200 : 201, json_decode($winner->getOutput(), true)['status'] ?? null, $winner->getOutput().$winner->getErrorOutput());
        $this->assertDatabaseCount('active_collab_task_exports', 1);
        $this->assertDatabaseCount('active_collab_sync_attempts', 1);
        $this->assertDatabaseHas('active_collab_sync_attempts', ['status' => 'succeeded']);
    }

    public function test_different_records_can_send_without_waiting_for_each_other(): void
    {
        $a = $this->startWorker('a', 'create', 8);
        $b = $this->startWorker('b', 'create', 9);
        touch($this->directory.'/start');
        $this->waitUntil(fn (): bool => count($this->calls()) === 2);
        touch($this->directory.'/release');
        foreach ([$a, $b] as $worker) {
            $worker->wait();
            $this->assertSame(201, json_decode($worker->getOutput(), true)['status'] ?? null, $worker->getOutput().$worker->getErrorOutput());
        }
        $this->assertDatabaseCount('active_collab_sync_attempts', 2);
    }

    public function test_recovery_blocks_a_regular_sync_while_remote_read_is_running(): void
    {
        $export = $this->prepareRecovery('a');
        $a = $this->startWorker('a', 'bind');
        touch($this->directory.'/start');
        $this->waitUntil(fn (): bool => is_file($this->directory.'/ready-a'));
        $b = $this->startWorker('b', 'create');
        $this->waitUntil(fn (): bool => ! $b->isRunning());
        $this->assertSame('sync_in_progress', json_decode($b->getOutput(), true)['body']['error']['code'] ?? null, $b->getOutput());
        $this->assertCount(1, $this->calls());
        touch($this->directory.'/release');
        $a->wait();
        $this->assertSame(200, json_decode($a->getOutput(), true)['status'] ?? null, $a->getOutput());
        $this->assertSame(123, $export->fresh()->task_id);
        $this->assertDatabaseCount('active_collab_sync_attempts', 1);
        $this->assertDatabaseCount('admin_audit_logs', 2);
    }

    public function test_two_recovery_requests_cannot_both_decide_the_same_snapshot(): void
    {
        $export = $this->prepareRecovery('a');
        $this->prepareRecovery('b', $export);
        $a = $this->startWorker('a', 'bind');
        touch($this->directory.'/start');
        $this->waitUntil(fn (): bool => is_file($this->directory.'/ready-a'));
        $b = $this->startWorker('b', 'bind');
        $this->waitUntil(fn (): bool => ! $b->isRunning());
        $this->assertSame('recovery_conflict', json_decode($b->getOutput(), true)['body']['error']['code'] ?? null, $b->getOutput());
        $this->assertCount(1, $this->calls());
        touch($this->directory.'/release');
        $a->wait();
        $this->assertSame(200, json_decode($a->getOutput(), true)['status'] ?? null, $a->getOutput());
        $this->assertDatabaseCount('admin_audit_logs', 2);
    }

    public function test_expiry_sweep_rejects_a_late_recovery_result(): void
    {
        $export = $this->prepareRecovery('a');
        $a = $this->startWorker('a', 'bind');
        touch($this->directory.'/start');
        $this->waitUntil(fn (): bool => is_file($this->directory.'/ready-a'));
        $export->update(['lease_expires_at' => now()->subMinute()]);
        $this->artisan('integrations:expire-sync-operations')->assertExitCode(0);
        touch($this->directory.'/release');
        $a->wait();
        $this->assertSame(409, json_decode($a->getOutput(), true)['status'] ?? null, $a->getOutput());
        $this->assertNull($export->fresh()->task_id);
        $this->assertSame('unknown', $export->fresh()->last_attempt_status);
        $this->assertDatabaseHas('active_collab_sync_attempts', ['action' => 'reconcile', 'status' => 'unknown']);
        $this->assertDatabaseCount('admin_audit_logs', 2);
    }

    public function test_audit_failure_in_a_worker_cannot_publish_the_binding(): void
    {
        $export = $this->prepareRecovery('a', failAudit: true);
        $a = $this->startWorker('a', 'bind');
        touch($this->directory.'/start');
        $this->waitUntil(fn (): bool => is_file($this->directory.'/ready-a'));
        touch($this->directory.'/release');
        $a->wait();
        $this->assertSame(409, json_decode($a->getOutput(), true)['status'] ?? null, $a->getOutput());
        $this->assertNull($export->fresh()->task_id);
        $this->assertSame('running', $export->fresh()->last_attempt_status);
        $this->assertDatabaseCount('admin_audit_logs', 1);
        $this->assertDatabaseHas('active_collab_sync_attempts', ['status' => 'running']);
    }

    public function test_same_uuid_at_different_positions_has_one_external_owner(): void
    {
        ActiveCollabIntegration::firstOrFail()->forceFill(['uuid_only' => true])->save();
        $uuid = (string) Str::uuid();
        $a = $this->startWorker('a', 'create', 8, $uuid);
        $b = $this->startWorker('b', 'create', 19, $uuid);
        touch($this->directory.'/start');
        $this->waitUntil(fn (): bool => is_file($this->directory.'/ready-a') || is_file($this->directory.'/ready-b'));
        $loser = is_file($this->directory.'/ready-a') ? $b : $a;
        $this->waitUntil(fn (): bool => ! $loser->isRunning());
        $this->assertSame('sync_in_progress', json_decode($loser->getOutput(), true)['body']['error']['code'] ?? null, $loser->getOutput());
        $this->assertCount(1, $this->calls());
        touch($this->directory.'/release');
        $winner = $loser === $a ? $b : $a;
        $winner->wait();
        $this->assertSame(201, json_decode($winner->getOutput(), true)['status'] ?? null, $winner->getOutput().$winner->getErrorOutput());
        $this->assertDatabaseCount('active_collab_task_exports', 1);
        $this->assertSame($uuid, ActiveCollabTaskExport::firstOrFail()->record_uuid);
        $this->assertDatabaseCount('active_collab_sync_attempts', 1);
    }

    public function test_different_uuids_can_share_a_locator_and_send_concurrently(): void
    {
        ActiveCollabIntegration::firstOrFail()->forceFill(['uuid_only' => true])->save();
        $a = $this->startWorker('a', 'create', 8, (string) Str::uuid());
        $b = $this->startWorker('b', 'create', 8, (string) Str::uuid());
        touch($this->directory.'/start');
        $this->waitUntil(fn (): bool => count($this->calls()) === 2);
        touch($this->directory.'/release');
        foreach ([$a, $b] as $worker) {
            $worker->wait();
            $this->assertSame(201, json_decode($worker->getOutput(), true)['status'] ?? null, $worker->getOutput().$worker->getErrorOutput());
        }
        $this->assertSame(2, ActiveCollabTaskExport::where('external_id', 'race-sheet:0:8')->count());
        $this->assertSame(2, ActiveCollabTaskExport::distinct()->count('task_id'));
    }

    #[DataProvider('migrationWriters')]
    public function test_migration_cannot_rebind_an_operation_in_flight(string $action): void
    {
        $export = $this->prepareRecovery('a');
        $export->update(['task_id' => 123, 'task_url' => 'https://ac.example.test/projects/10/tasks/123', 'binding_status' => 'bound',
            'last_attempt_status' => $action === 'update' ? 'succeeded' : 'unknown']);
        $input = json_decode(file_get_contents($this->directory.'/input-a'), true, flags: JSON_THROW_ON_ERROR);
        $input['input']['expected_binding'] = 'bound';
        file_put_contents($this->directory.'/input-a', json_encode($input, JSON_THROW_ON_ERROR));
        $a = $this->startWorker('a', $action);
        touch($this->directory.'/start');
        $this->waitUntil(fn (): bool => is_file($this->directory.'/ready-a'));
        $integration = ActiveCollabIntegration::firstOrFail();
        $integration->update(['active' => false]);
        file_put_contents($this->directory.'/input-b', json_encode([
            'integration_id' => $integration->id, 'actor_id' => $input['actor_id'],
            'manifest' => ['version' => 1, 'spreadsheet_id' => 'race-sheet', 'records' => [[
                'record_uuid' => (string) Str::uuid(), 'sheet_id' => '0', 'row_number' => 19, 'task_id' => 123,
                'task_url' => 'https://ac.example.test/projects/10/tasks/123',
            ]]],
        ], JSON_THROW_ON_ERROR));
        $b = $this->startWorker('b', 'migrate');
        $this->waitUntil(fn (): bool => ! $b->isRunning());
        $report = json_decode($b->getOutput(), true);
        $this->assertFalse($report['applied'] ?? true, $b->getOutput().$b->getErrorOutput());
        $this->assertContains('running_operation:'.$export->id, $report['conflicts']);
        $this->assertNull($export->fresh()->record_uuid);
        $this->assertFalse($integration->fresh()->uuid_only);
        $this->assertCount(1, $this->calls());
        touch($this->directory.'/release');
        $a->wait();
        $this->assertSame($action === 'update' ? 200 : 409, json_decode($a->getOutput(), true)['status'] ?? null, $a->getOutput());
        $retry = app(ActiveCollabRecordUuidMigrationService::class)->migrate($integration->id,
            ActiveCollabRecordUuidManifest::fromArray(json_decode(file_get_contents($this->directory.'/input-b'), true)['manifest']),
            $input['actor_id'], 'Confirmed links after writer stopped', false, true);
        $this->assertTrue($retry['applied']);
        $this->assertTrue($retry['uuid_only']);
    }

    /** @return list<array{string}> */
    public static function migrationWriters(): array
    {
        return [['update'], ['verify']];
    }

    private function prepareRecovery(string $name, ?ActiveCollabTaskExport $export = null, bool $failAudit = false): ActiveCollabTaskExport
    {
        if ($export === null) {
            $source = ActiveCollabTaskData::fromRequest([
                'spreadsheet_id' => 'race-sheet', 'sheet_id' => '0', 'row_number' => 8,
                'environment' => 'test', 'section' => 'Recovery', 'description' => 'Race recovery',
            ])->toArray();
            $export = ActiveCollabTaskExport::create([
                'integration_id' => ActiveCollabIntegration::firstOrFail()->id, 'external_id' => 'race-sheet:0:8',
                'spreadsheet_id' => 'race-sheet', 'sheet_id' => '0', 'row_number' => 8, 'project_id' => 10,
                'source_payload' => $source, 'status' => 'failed', 'last_attempt_status' => 'unknown', 'binding_status' => 'unbound',
            ]);
        }
        $actor = User::factory()->create(['permissions' => ['platform.index' => true,
            'platform.activecollab.recovery' => true, 'platform.systems.users' => true, 'platform.systems.roles' => true]]);
        file_put_contents($this->directory.'/input-'.$name, json_encode([
            'actor_id' => $actor->id, 'export_id' => $export->id, 'fail_audit' => $failAudit,
            'input' => ['request_id' => (string) Str::uuid(), 'expected_operation_id' => null,
                'expected_status' => 'unknown', 'expected_binding' => 'unbound',
                'candidate_task_id' => 123, 'reason' => 'Confirmed recovery in race test'],
        ], JSON_THROW_ON_ERROR));

        return $export;
    }

    private function startWorker(string $name, string $action, int $row = 8, ?string $uuid = null): Process
    {
        $connection = config('database.connections.mariadb');
        $worker = new Process([PHP_BINARY, base_path('tests/Fixtures/activecollab_sync_worker.php'), $this->directory, $name, $action, (string) $row], base_path(), [
            'APP_ENV' => 'testing', 'APP_KEY' => config('app.key'), 'LOG_CHANNEL' => 'null',
            'SYNC_RECORD_UUID' => $uuid ?? '',
            'DB_CONNECTION' => 'mariadb', 'DB_DATABASE' => $connection['database'],
            'DB_HOST' => $connection['host'], 'DB_PORT' => (string) $connection['port'],
            'DB_USERNAME' => $connection['username'], 'DB_PASSWORD' => $connection['password'],
            'DB_URL' => '', 'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array',
        ], timeout: 18);
        $this->workers[] = $worker;
        $worker->start();

        return $worker;
    }

    /** @return list<string> */
    private function calls(): array
    {
        return is_file($this->directory.'/calls') ? file($this->directory.'/calls', FILE_IGNORE_NEW_LINES) : [];
    }

    private function waitUntil(\Closure $condition): void
    {
        $deadline = microtime(true) + 8;
        while (! $condition()) {
            if (microtime(true) > $deadline) {
                $this->fail('Concurrency barrier timed out. '.implode(' ', array_map(fn (Process $p): string => $p->getOutput().$p->getErrorOutput(), $this->workers)));
            }
            usleep(10000);
        }
    }
}
