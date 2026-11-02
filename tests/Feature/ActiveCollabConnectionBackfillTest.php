<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ActiveCollabIntegration;
use App\Repositories\ActiveCollab\ActiveCollabConnectionRepository;
use App\Services\ActiveCollab\ActiveCollabConnectionBackfillService;
use App\Services\ActiveCollab\ActiveCollabConnectionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Tests\TestCase;

class ActiveCollabConnectionBackfillTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('app.key', 'base64:J63P4m4KjJ8l0RkGxJZ4Kf9P9+K6f2R4R6V4a2M1LxQ=');
        config()->set('services.active_collab.base_url', 'https://fallback.example.test/api/v1');
        config()->set('services.active_collab.api_token', 'fallback-token');
    }

    public function test_dry_run_does_not_write_and_apply_deduplicates_only_full_credentials(): void
    {
        $one = $this->legacyIntegration('https://AC.example.test', 'shared-secret');
        $two = $this->legacyIntegration('https://ac.example.test/api/v1/', 'shared-secret');
        $three = $this->legacyIntegration('https://ac.example.test/api/v1', 'different-secret');
        $service = $this->service();

        $preview = $service->run(false);
        $this->assertCount(3, $preview);
        $this->assertDatabaseCount('active_collab_connections', 0);
        $this->assertSame(3, ActiveCollabIntegration::query()->whereNull('connection_id')->count());

        $applied = $service->run(true);
        $this->assertCount(3, $applied);
        $one->refresh();
        $two->refresh();
        $three->refresh();
        $this->assertNotNull($one->connection_id);
        $this->assertSame($one->connection_id, $two->connection_id);
        $this->assertNotSame($one->connection_id, $three->connection_id);
        $this->assertDatabaseCount('active_collab_connections', 2);

        $this->assertSame([], $service->run(true));
        $this->assertDatabaseCount('active_collab_connections', 2);
    }

    public function test_apply_requires_writers_stopped_confirmation_and_dry_run_is_default(): void
    {
        $this->legacyIntegration('https://ac.example.test/api/v1', 'legacy-secret');

        $status = Artisan::call('integrations:migrate-activecollab-connections', ['--apply' => true]);

        $this->assertSame(2, $status);
        $this->assertDatabaseCount('active_collab_connections', 0);
        $this->assertSame(0, ActiveCollabIntegration::query()->whereNotNull('connection_id')->count());
    }

    public function test_unreadable_legacy_credentials_are_reported_without_using_global_fallback(): void
    {
        config()->set('services.active_collab.base_url', '');
        config()->set('services.active_collab.api_token', '');
        $integration = $this->legacyIntegration(null, null);

        $report = $this->service()->run(true);

        $this->assertSame('unresolved', $report[0]['action']);
        $this->assertSame('credentials_missing', $report[0]['reason']);
        $this->assertNull($integration->fresh()->connection_id);
        $this->assertDatabaseCount('active_collab_connections', 0);
    }

    private function legacyIntegration(?string $baseUrl, ?string $token): ActiveCollabIntegration
    {
        return ActiveCollabIntegration::query()->create([
            'name' => 'Legacy', 'spreadsheet_id' => (string) Str::uuid(),
            'active_collab_base_url' => $baseUrl, 'active_collab_project_id' => 10,
            'active_collab_task_list_id' => 31, 'active_collab_api_token' => $token, 'active' => true,
        ]);
    }

    private function service(): ActiveCollabConnectionBackfillService
    {
        return new ActiveCollabConnectionBackfillService(
            new ActiveCollabConnectionRepository(), new ActiveCollabConnectionService(new ActiveCollabConnectionRepository()),
        );
    }
}
