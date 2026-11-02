<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ActiveCollabIntegrationToken;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Tests\Support\ActiveCollabIntegrationFixture;
use Tests\TestCase;

class ActiveCollabIntegrationTokenTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Config::set('app.key', 'base64:J63P4m4KjJ8l0RkGxJZ4Kf9P9+K6f2R4R6V4a2M1LxQ=');
    }

    public function test_issue_list_revoke_and_repeat_revoke_never_disclose_stored_hash(): void
    {
        $integration = ActiveCollabIntegrationFixture::create(['name' => 'Token test', 'spreadsheet_id' => 'token-sheet',
            'active_collab_base_url' => 'https://ac.example.test/api/v1', 'active_collab_project_id' => 10,
            'active_collab_task_list_id' => 31, 'active_collab_api_token' => 'ac-secret', 'active' => true]);
        $actor = User::factory()->create();
        $actor->forceFill(['permissions' => ['platform.activecollab.recovery' => true,
            'platform.systems.users' => true, 'platform.systems.roles' => true]])->save();

        $this->assertSame(0, Artisan::call('integrations:tokens', ['action' => 'issue', '--integration' => $integration->id,
            '--actor' => $actor->id, '--reason' => 'Issue service credential for sync', '--name' => 'Sheet']));
        $issued = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertMatchesRegularExpression('/^sc_[a-f0-9]{64}$/', $issued['token']);
        $stored = ActiveCollabIntegrationToken::findOrFail($issued['token_id']);
        $this->assertSame(hash('sha256', $issued['token']), $stored->token_hash);
        $this->assertNotSame($issued['token'], $stored->token_hash);

        $this->assertSame(0, Artisan::call('integrations:tokens', ['action' => 'list', '--integration' => $integration->id,
            '--actor' => $actor->id, '--reason' => 'Review service credentials safely']));
        $listed = Artisan::output();
        $this->assertStringNotContainsString($issued['token'], $listed);
        $this->assertStringNotContainsString($stored->token_hash, $listed);

        $this->assertSame(0, Artisan::call('integrations:tokens', ['action' => 'revoke', '--integration' => $integration->id,
            '--actor' => $actor->id, '--reason' => 'Rotate service credential now', '--token-id' => $issued['token_id']]));
        $this->assertSame(0, Artisan::call('integrations:tokens', ['action' => 'revoke', '--integration' => $integration->id,
            '--actor' => $actor->id, '--reason' => 'Repeat credential revocation', '--token-id' => $issued['token_id']]));
        $this->assertNotNull($stored->fresh()->revoked_at);
        $this->assertDatabaseCount('admin_audit_logs', 2);

        Http::preventStrayRequests();
        $this->withToken($issued['token'])->postJson('/api/tasks/sync', ['record_uuid' => 'ae091ce2-282a-43ad-a258-85a2b8eecfc4'])
            ->assertUnauthorized()->assertJsonPath('error.code', 'unauthorized');
        $this->assertDatabaseCount('active_collab_task_exports', 0);
        Http::assertNothingSent();
    }

    public function test_token_management_requires_all_recovery_administrator_permissions(): void
    {
        $integration = ActiveCollabIntegrationFixture::create(['name' => 'Token test', 'spreadsheet_id' => 'token-sheet',
            'active_collab_base_url' => 'https://ac.example.test/api/v1', 'active_collab_project_id' => 10,
            'active_collab_task_list_id' => 31, 'active_collab_api_token' => 'ac-secret', 'active' => true]);
        $actor = User::factory()->create();
        $actor->forceFill(['permissions' => ['platform.activecollab.recovery' => true,
            'platform.systems.users' => true]])->save();

        $this->assertSame(1, Artisan::call('integrations:tokens', ['action' => 'enable-legacy', '--integration' => $integration->id,
            '--actor' => $actor->id, '--reason' => 'Explicitly enable legacy access']));
        $this->assertFalse((bool) $integration->fresh()->allow_legacy_token);
        $this->assertDatabaseCount('admin_audit_logs', 0);
    }
}
