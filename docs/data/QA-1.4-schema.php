<?php
require '/app/vendor/autoload.php';
$app = require '/app/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use App\Data\ActiveCollab\ActiveCollabRecordUuidManifest;
use App\Models\ActiveCollabIntegration;
use App\Models\ActiveCollabTaskExport;
use App\Models\ActiveCollabSyncAttempt;
use App\Models\User;
use App\Services\ActiveCollab\ActiveCollabRecordUuidMigrationService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
function verify($value, $label) { if (!$value) throw new RuntimeException($label); }
try {
verify($app->environment('testing') && config('database.default') === 'mariadb'
    && DB::connection()->getDatabaseName() === 'sheetcollab_uuid_upgrade_test', 'Unsafe DB');
config()->set('app.key', 'base64:J63P4m4KjJ8l0RkGxJZ4Kf9P9+K6f2R4R6V4a2M1LxQ=');
Http::preventStrayRequests(); Http::fake();
verify(Artisan::call('migrate:fresh', ['--force' => true]) === 0, 'fresh');
$add = require '/app/database/migrations/2026_10_30_000002_add_record_identity_to_active_collab_exports_and_integrations.php';
$index = require '/app/database/migrations/2026_10_30_000003_replace_external_id_unique_with_index_on_active_collab_task_exports.php';
$index->down(); $add->down();
verify(!Schema::hasColumn('active_collab_task_exports', 'record_uuid'), 'empty down');
DB::table('migrations')->whereIn('migration', ['2026_10_30_000002_add_record_identity_to_active_collab_exports_and_integrations', '2026_10_30_000003_replace_external_id_unique_with_index_on_active_collab_task_exports'])->delete();
$integration = ActiveCollabIntegration::create(['name' => 'Upgrade fixture', 'spreadsheet_id' => 'old-sheet', 'active_collab_base_url' => 'https://ac.example.test/api/v1', 'active_collab_project_id' => 10, 'active_collab_task_list_id' => 31, 'active_collab_api_token' => 'fixture-secret', 'active' => false]);
$operation = (string)Str::uuid();
$source = ['spreadsheet_id' => 'old-sheet', 'sheet_id' => '0', 'row_number' => 8, 'comment' => 'Old source'];
$export = ActiveCollabTaskExport::create(['integration_id' => $integration->id, 'external_id' => 'old-sheet:0:8', 'spreadsheet_id' => 'old-sheet', 'sheet_id' => '0', 'row_number' => 8, 'project_id' => 10, 'task_id' => 123, 'task_url' => 'https://ac.example.test/projects/10/tasks/123', 'source_payload' => $source, 'status' => 'failed', 'last_attempt_status' => 'unknown', 'binding_status' => 'bound', 'operation_id' => $operation, 'sync_hash' => 'confirmed-hash', 'synced_at' => now()->subDay()]);
$attempt = ActiveCollabSyncAttempt::create(['export_id' => $export->id, 'integration_id' => $integration->id, 'operation_id' => $operation, 'action' => 'update', 'status' => 'unknown', 'started_at' => now()->subMinute(), 'source_payload' => $source]);
$before = $export->getAttributes(); $oldAttempt = $attempt->fresh()->getAttributes();
verify(Artisan::call('migrate', ['--force' => true]) === 0, 'upgrade');
verify($export->fresh()->record_uuid === null && $integration->fresh()->uuid_only === false, 'nullable additive defaults');
$actor = User::factory()->create(['permissions' => ['platform.activecollab.recovery' => true, 'platform.systems.users' => true, 'platform.systems.roles' => true]]);
$uuid = (string)Str::uuid();
$manifest = ActiveCollabRecordUuidManifest::fromArray(['version' => 1, 'spreadsheet_id' => 'old-sheet', 'records' => [['record_uuid' => $uuid, 'sheet_id' => 'moved', 'row_number' => 19, 'task_id' => 123, 'task_url' => 'https://ac.example.test/projects/10/tasks/123']]]);
$service = app(ActiveCollabRecordUuidMigrationService::class);
$dry = $service->migrate($integration->id, $manifest, $actor->id, 'Confirmed upgrade fixture links', true, true);
verify(!$dry['applied'] && $export->fresh()->record_uuid === null, 'dry run');
$result = $service->migrate($integration->id, $manifest, $actor->id, 'Confirmed upgrade fixture links', false, true);
$export->refresh();
verify($result['applied'] && $result['uuid_only'], 'migration applied');
foreach (['status', 'last_attempt_status', 'binding_status', 'operation_id', 'sync_hash', 'synced_at', 'source_payload', 'task_id', 'task_url'] as $key) verify($export->fresh()->getRawOriginal($key) === $before[$key], 'preserved '.$key);
verify($attempt->fresh()->getAttributes() == $oldAttempt, 'old attempt unchanged');
verify(ActiveCollabSyncAttempt::count() === 1 && DB::table('admin_audit_logs')->count() === 2, 'no fictional attempt');
verify($service->migrate($integration->id, $manifest, $actor->id, 'Confirmed upgrade fixture links', false, true)['planned'] === [] && DB::table('admin_audit_logs')->count() === 2, 'idempotent');
try { $export->replicate()->save(); throw new RuntimeException('Composite unique missing'); } catch (Illuminate\Database\UniqueConstraintViolationException) {}
$copy = $export->replicate(); $copy->record_uuid = (string)Str::uuid(); $copy->save();
verify(ActiveCollabTaskExport::where('external_id', 'old-sheet:moved:19')->count() === 2, 'nonunique locator');
foreach ([$index, $add] as $migration) {
    try { $migration->down(); throw new LogicException('Unsafe down allowed'); }
    catch (RuntimeException $e) { verify(str_contains($e->getMessage(), 'procedure'), 'guarded down'); }
}
Http::assertNothingSent();
echo "PASS: MariaDB fresh, empty down, 1.3 upgrade, UUID backfill/replay/audit/history, unique, reused locator, guarded down; no HTTP\n";
$records = [];
for ($i=1; $i<=20000; $i++) $records[] = ['record_uuid' => sprintf('00000000-0000-4000-8000-%012d', $i), 'sheet_id' => '0', 'row_number' => $i];
$started = microtime(true);
$large = ActiveCollabRecordUuidManifest::fromArray(['version' => 1, 'spreadsheet_id' => 'large-sheet', 'records' => $records]);
verify(count($large->records) === 20000, 'manifest upper limit');
echo 'PASS: 20,000 valid manifest records, '.round(microtime(true)-$started, 2)." seconds\n";

} catch (Throwable $e) { fwrite(STDERR, "FAIL: ".$e->getMessage()."\n"); exit(1); }
