<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Exceptions\TaskSyncConflictException;
use App\Models\ActiveCollabConnection;
use App\Models\ActiveCollabIntegration;
use App\Models\ActiveCollabIntegrationToken;
use App\Models\ActiveCollabTaskExport;
use App\Models\AdminAuditLog;
use App\Models\User;
use App\Orchid\Screens\Integration\IntegrationEditScreen;
use App\Orchid\Screens\Role\RoleEditScreen;
use App\Repositories\ActiveCollab\ActiveCollabConnectionRepository;
use App\Repositories\ActiveCollab\ActiveCollabIntegrationRepository;
use App\Repositories\ActiveCollab\ActiveCollabIntegrationTokenRepository;
use App\Repositories\ActiveCollab\ActiveCollabRepository;
use App\Services\ActiveCollab\ActiveCollabDataSanitizer;
use App\Services\ActiveCollab\ActiveCollabIntegrationTokenService;
use App\Services\AdminAccessManagementService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Orchid\Platform\Models\Role;
use Tests\Support\ActiveCollabIntegrationFixture;
use Tests\TestCase;

class ActiveCollabAccessControlTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('app.key', 'base64:J63P4m4KjJ8l0RkGxJZ4Kf9P9+K6f2R4R6V4a2M1LxQ=');
        Http::preventStrayRequests();
    }

    public function test_role_permissions_and_integration_membership_are_both_required(): void
    {
        $integration = $this->integration('Assigned sheet');
        $other = $this->integration('Private sheet');
        $viewer = User::factory()->create(['permissions' => ['platform.activecollab.integration.view' => true]]);
        $integration->users()->attach($viewer->id);

        $this->assertTrue(Gate::forUser($viewer)->allows('view', $integration));
        $this->assertFalse(Gate::forUser($viewer)->allows('view', $other));
        $this->assertFalse(Gate::forUser($viewer)->allows('update', $integration));
        $this->assertFalse(Gate::forUser($viewer)->allows('manageSettings', $integration));

        $manager = User::factory()->create(['permissions' => ['platform.activecollab.integration.manage' => true]]);
        $integration->users()->attach($manager->id);
        $this->assertTrue(Gate::forUser($manager)->allows('update', $integration));
        $this->assertFalse(Gate::forUser($manager)->allows('manageSettings', $integration));
        Http::assertNothingSent();
    }

    public function test_admin_can_view_all_and_membership_pair_is_unique(): void
    {
        $first = $this->integration('Admin first');
        $second = $this->integration('Admin second');
        $admin = User::factory()->create(['permissions' => ['platform.activecollab.admin' => true]]);
        $this->assertTrue(Gate::forUser($admin)->allows('view', $first));
        $this->assertTrue(Gate::forUser($admin)->allows('view', $second));

        $viewer = User::factory()->create(['permissions' => ['platform.activecollab.integration.view' => true]]);
        $first->users()->attach($viewer->id);
        $this->expectException(QueryException::class);
        $first->users()->attach($viewer->id);
    }

    public function test_integration_list_is_scoped_and_guessed_integration_id_returns_404(): void
    {
        $assigned = $this->integration('Assigned sheet');
        $private = $this->integration('Private sheet');
        $viewer = User::factory()->create(['permissions' => ['platform.index' => true,
            'platform.activecollab.integration.view' => true]]);
        $assigned->users()->attach($viewer->id);

        $this->actingAs($viewer)->get(route('platform.integrations'))
            ->assertOk()->assertSee('Assigned sheet')->assertDontSee('Private sheet');
        $this->actingAs($viewer)->get(route('platform.integrations.edit', ['integration' => $private->id]))
            ->assertNotFound();
        Http::assertNothingSent();
    }

    public function test_export_history_is_scoped_before_rendering_and_connections_are_admin_only(): void
    {
        $assigned = $this->integration('History assigned');
        $private = $this->integration('History private');
        ActiveCollabTaskExport::query()->create([
            'integration_id' => $assigned->id, 'external_id' => 'assigned:0:1', 'spreadsheet_id' => $assigned->spreadsheet_id,
            'sheet_id' => '0', 'row_number' => 1, 'project_id' => 10, 'status' => 'created', 'source_payload' => [],
        ]);
        ActiveCollabTaskExport::query()->create([
            'integration_id' => $private->id, 'external_id' => 'private-secret:0:2', 'spreadsheet_id' => $private->spreadsheet_id,
            'sheet_id' => '0', 'row_number' => 2, 'project_id' => 10, 'status' => 'created', 'source_payload' => [],
        ]);
        $viewer = User::factory()->create(['permissions' => ['platform.index' => true,
            'platform.activecollab.integration.view' => true]]);
        $assigned->users()->attach($viewer->id);

        $this->actingAs($viewer)->get(route('platform.exports'))
            ->assertOk()->assertSee('assigned:0:1')->assertDontSee('private-secret:0:2');
        $this->actingAs($viewer)->get(route('platform.connections'))->assertNotFound();
    }

    public function test_manager_can_change_mappings_but_cannot_change_destination_or_activity(): void
    {
        $integration = $this->integration('Manager sheet');
        $connectionId = $integration->connection_id;
        $spreadsheetId = $integration->spreadsheet_id;
        $manager = User::factory()->create(['permissions' => ['platform.activecollab.integration.manage' => true]]);
        $integration->users()->attach($manager->id);
        Http::fake(static function ($request) {
            if (str_ends_with($request->url(), '/projects')) {
                return Http::response([['id' => 10, 'name' => 'Project', 'is_trashed' => false]]);
            }
            if (str_ends_with($request->url(), '/projects/10/task-lists')) {
                return Http::response([['id' => 31, 'name' => 'Backlog']]);
            }

            return Http::response([], 404);
        });
        $request = Request::create('/admin/integrations/'.$integration->id.'/edit', 'POST', ['integration' => [
            'task_list_mapping' => [['key' => 'backend', 'value' => '31']],
            'active_collab_project_id' => '999', 'active_collab_task_list_id' => '999',
            'connection_id' => (string) Str::uuid(), 'spreadsheet_id' => 'attacker-sheet', 'active' => false,
        ]]);
        $request->setUserResolver(static fn () => $manager);
        $this->actingAs($manager);

        app(IntegrationEditScreen::class)->save($integration, $request);

        $integration->refresh();
        $this->assertSame($connectionId, $integration->connection_id);
        $this->assertSame(10, $integration->active_collab_project_id);
        $this->assertSame(31, $integration->active_collab_task_list_id);
        $this->assertSame($spreadsheetId, $integration->spreadsheet_id);
        $this->assertTrue($integration->active);
        $this->assertSame(['backend' => 31], $integration->task_list_mapping);
        Http::assertSentCount(2);
    }

    public function test_access_assignment_is_transactional_and_audited_without_credentials(): void
    {
        $integration = $this->integration('Assigned sheet');
        $user = User::factory()->create();
        $actor = User::factory()->create();
        $service = app(AdminAccessManagementService::class);

        $service->syncUserAccess($user, [], ['platform.activecollab.integration.view' => true], [$integration->id], (int) $actor->id);

        $this->assertDatabaseHas('active_collab_integration_user', [
            'user_id' => $user->id, 'integration_id' => $integration->id,
        ]);
        $audit = AdminAuditLog::query()->where('action', 'user_access_updated')->firstOrFail();
        $this->assertSame([$integration->id], $audit->after['integration_ids']);
        $this->assertSame(['platform.activecollab.integration.view'], $audit->after['permission_slugs']);
        $this->assertStringNotContainsString('token', strtolower(json_encode($audit->toArray(), JSON_THROW_ON_ERROR)));
    }

    public function test_admin_permission_unlocks_user_and_role_screens_without_system_permission_bypass(): void
    {
        $admin = User::factory()->create(['permissions' => ['platform.index' => true, 'platform.activecollab.admin' => true]]);
        $viewer = User::factory()->create(['permissions' => ['platform.systems.roles' => true]]);

        $this->actingAs($admin)->get(route('platform.systems.users'))->assertOk();
        $this->actingAs($admin)->get(route('platform.systems.roles'))->assertOk();
        $this->actingAs($viewer)->get(route('platform.systems.roles'))->assertForbidden();
    }

    public function test_role_permission_changes_are_audited(): void
    {
        $admin = User::factory()->create(['permissions' => ['platform.activecollab.admin' => true]]);
        ActiveCollabConnection::query()->create([
            'name' => 'Primary', 'base_url' => 'https://ac.example.test/api/v1', 'api_token' => 'role-audit-secret', 'active' => true,
        ]);
        Role::query()->create(['name' => 'Sheet role-audit-secret', 'slug' => 'sheet-viewer', 'permissions' => []]);
        $role = Role::query()->where('slug', 'sheet-viewer')->firstOrFail();
        $request = Request::create('/admin/roles/'.$role->id.'/edit', 'POST', [
            'role' => ['name' => 'Sheet viewer', 'slug' => 'sheet-viewer'],
            'permissions' => [base64_encode('platform.activecollab.integration.view') => '1'],
        ]);
        $request->setUserResolver(static fn () => $admin);
        $this->actingAs($admin);

        app(RoleEditScreen::class)->save($request, $role, app(AdminAccessManagementService::class));

        $this->assertTrue((bool) $role->fresh()->permissions['platform.activecollab.integration.view']);
        $audit = AdminAuditLog::query()->where('action', 'role_updated')->firstOrFail();
        $this->assertSame(['platform.activecollab.integration.view'], $audit->after['permission_slugs']);
        $this->assertStringNotContainsString('role-audit-secret', json_encode($audit->toArray(), JSON_THROW_ON_ERROR));
    }

    public function test_integration_settings_audit_contains_allowlisted_fields_only(): void
    {
        $connection = app(ActiveCollabConnectionRepository::class)->create([
            'name' => 'Primary', 'base_url' => 'https://ac.example.test/api/v1', 'api_token' => 'audit-secret', 'active' => true,
        ]);
        $actor = User::factory()->create();
        $integration = app(ActiveCollabIntegrationRepository::class)->saveSettings(null, [
            'name' => 'Audited integration', 'spreadsheet_id' => 'audit-sheet', 'connection_id' => $connection->id,
            'active_collab_project_id' => 10, 'active_collab_task_list_id' => 31,
            'active' => true,
        ], (int) $actor->id);

        $audit = AdminAuditLog::query()->where('integration_id', $integration->id)->firstOrFail();
        $serialized = json_encode($audit->toArray(), JSON_THROW_ON_ERROR);
        $this->assertStringContainsString('audit-sheet', $serialized);
        $this->assertStringNotContainsString('audit-secret', $serialized);
        $this->assertArrayNotHasKey('active_collab_api_token', $audit->after);
        $this->assertArrayNotHasKey('api_token', $audit->after);
    }

    public function test_integration_change_rolls_back_when_audit_write_fails(): void
    {
        $connection = app(ActiveCollabConnectionRepository::class)->create([
            'name' => 'Primary', 'base_url' => 'https://ac.example.test/api/v1', 'api_token' => 'audit-secret', 'active' => true,
        ]);
        $actor = User::factory()->create();
        AdminAuditLog::creating(static function (AdminAuditLog $log): void {
            if ($log->action === 'integration_created') {
                throw new \RuntimeException('audit store unavailable');
            }
        });

        try {
            app(ActiveCollabIntegrationRepository::class)->saveSettings(null, [
                'name' => 'Must roll back', 'spreadsheet_id' => 'rollback-sheet', 'connection_id' => $connection->id,
                'active_collab_project_id' => 10, 'active_collab_task_list_id' => 31, 'active' => true,
            ], (int) $actor->id);
            $this->fail('Integration change committed without its audit record.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('audit store unavailable', $exception->getMessage());
        } finally {
            AdminAuditLog::flushEventListeners();
        }

        $this->assertDatabaseMissing('active_collab_integrations', ['spreadsheet_id' => 'rollback-sheet']);
    }

    public function test_integration_deactivation_is_audited(): void
    {
        $connection = app(ActiveCollabConnectionRepository::class)->create([
            'name' => 'Primary', 'base_url' => 'https://ac.example.test/api/v1', 'api_token' => 'audit-secret', 'active' => true,
        ]);
        $actorId = (int) User::factory()->create()->id;
        $repository = app(ActiveCollabIntegrationRepository::class);
        $integration = $repository->saveSettings(null, [
            'name' => 'Audited integration', 'spreadsheet_id' => 'deactivation-sheet', 'connection_id' => $connection->id,
            'active_collab_project_id' => 10, 'active_collab_task_list_id' => 31, 'active' => true,
        ], $actorId);
        $repository->saveSettings($integration->id, [
            'name' => $integration->name, 'spreadsheet_id' => $integration->spreadsheet_id, 'connection_id' => $connection->id,
            'active_collab_project_id' => 10, 'active_collab_task_list_id' => 31, 'active' => false,
        ], $actorId);

        $audit = AdminAuditLog::query()->where('integration_id', $integration->id)
            ->where('action', 'integration_settings_updated')->firstOrFail();
        $this->assertTrue($audit->before['active']);
        $this->assertFalse($audit->after['active']);
    }

    public function test_connection_token_rotation_is_audited_as_boolean_without_secret(): void
    {
        $actor = User::factory()->create();
        $repository = app(ActiveCollabConnectionRepository::class);
        $connection = $repository->create([
            'name' => 'Primary old-audit-secret', 'base_url' => 'https://ac.example.test/api/v1', 'api_token' => 'old-audit-secret', 'active' => true,
        ], (int) $actor->id);
        $repository->update($connection->id, [
            'name' => 'Primary new-audit-secret', 'base_url' => $connection->base_url, 'api_token' => 'new-audit-secret', 'active' => true,
        ], (int) $actor->id);

        $audit = AdminAuditLog::query()->where('action', 'connection_updated')->firstOrFail();
        $this->assertTrue($audit->after['credential_changed']);
        $serialized = json_encode(AdminAuditLog::query()->get()->toArray(), JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('old-audit-secret', $serialized);
        $this->assertStringNotContainsString('new-audit-secret', $serialized);
        $this->assertStringNotContainsString('api_token', $serialized);
    }

    public function test_global_legacy_token_switch_does_not_disable_individual_tokens(): void
    {
        $integration = $this->integration('Token sheet', ['allow_legacy_token' => true]);
        $integration->forceFill(['allow_legacy_token' => true])->save();
        config()->set('services.google_sheets.integration_token', 'shared-token');
        config()->set('services.active_collab.allow_legacy_token', true);
        $repository = new ActiveCollabIntegrationTokenRepository();
        $legacy = $repository->authenticate('shared-token', $integration->spreadsheet_id, false);
        $this->assertSame($integration->id, $legacy->integrationId);

        config()->set('services.active_collab.allow_legacy_token', false);
        try {
            $repository->authenticate('shared-token', $integration->spreadsheet_id, false);
            $this->fail('Global legacy token remained accepted after disabling the master switch.');
        } catch (TaskSyncConflictException $exception) {
            $this->assertSame(401, $exception->httpStatus);
        }

        $plaintext = 'sc_'.str_repeat('a', 64);
        ActiveCollabIntegrationToken::query()->create([
            'integration_id' => $integration->id, 'name' => 'Script', 'token_hash' => hash('sha256', $plaintext),
            'created_at' => now(),
        ]);
        $individual = $repository->authenticate($plaintext, $integration->spreadsheet_id, true);
        $this->assertSame($integration->id, $individual->integrationId);
    }

    public function test_legacy_inventory_is_admin_only_and_never_contains_token_hashes(): void
    {
        $integration = $this->integration('Legacy sheet', ['allow_legacy_token' => true]);
        $integration->forceFill(['allow_legacy_token' => true])->save();
        $actor = User::factory()->create(['permissions' => [
            'platform.activecollab.recovery' => true,
            'platform.systems.users' => true,
            'platform.systems.roles' => true,
        ]]);
        $service = new ActiveCollabIntegrationTokenService(
            new ActiveCollabIntegrationTokenRepository(),
            app(ActiveCollabRepository::class),
            new ActiveCollabDataSanitizer(),
        );

        $result = $service->manage('legacy-status', '', (int) $actor->id, 'Review old scripts before switch');
        $this->assertTrue($result['legacy_enabled']);
        $this->assertSame($integration->id, $result['integrations'][0]['id']);
        $this->assertArrayNotHasKey('token_hash', $result['integrations'][0]);

        $viewer = User::factory()->create();
        $this->expectException(AuthorizationException::class);
        $service->manage('legacy-status', '', (int) $viewer->id, 'Review old scripts before switch');
    }

    /** @param array<string, mixed> $extra */
    private function integration(string $name, array $extra = []): ActiveCollabIntegration
    {
        return ActiveCollabIntegrationFixture::create($extra + [
            'name' => $name, 'spreadsheet_id' => Str::slug($name).'-'.Str::random(6),
            'active_collab_project_id' => 10, 'active_collab_task_list_id' => 31, 'active' => true,
        ]);
    }
}
