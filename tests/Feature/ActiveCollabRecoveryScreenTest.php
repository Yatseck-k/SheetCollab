<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ActiveCollabIntegration;
use App\Models\ActiveCollabTaskExport;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Orchid\Platform\Models\Role;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ActiveCollabRecoveryScreenTest extends TestCase
{
    use RefreshDatabase;

    private ActiveCollabTaskExport $export;

    private string $url;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('app.key', 'base64:J63P4m4KjJ8l0RkGxJZ4Kf9P9+K6f2R4R6V4a2M1LxQ=');
        $integration = ActiveCollabIntegration::create([
            'name' => 'Private integration', 'spreadsheet_id' => 'ui-sheet',
            'active_collab_project_id' => 10, 'active_collab_task_list_id' => 31,
            'active_collab_base_url' => 'https://ac.example.test/api/v1',
            'active_collab_api_token' => 'ui-ac-secret', 'active' => true,
        ]);
        $this->export = ActiveCollabTaskExport::create([
            'integration_id' => $integration->id, 'external_id' => 'ui-sheet:0:8',
            'spreadsheet_id' => 'ui-sheet', 'sheet_id' => '0', 'row_number' => 8, 'project_id' => 10,
            'source_payload' => ['comment' => '<script>unsafe</script> ui-ac-secret'],
            'status' => 'failed', 'last_attempt_status' => 'unknown', 'binding_status' => 'unbound',
        ]);
        $this->url = route('platform.exports.recovery', ['export' => $this->export->id]);
        Http::preventStrayRequests();
        Http::fake();
    }

    public function test_guest_cannot_read_or_recover(): void
    {
        $this->get($this->url)->assertRedirect();
        $this->post($this->url.'/confirmAbsence', $this->form())->assertRedirect();
        $this->assertDatabaseCount('admin_audit_logs', 0);
    }

    /** @return array<string, array{list<string>}> */
    public static function incompletePermissions(): array
    {
        return ['plain user' => [[]], 'recovery only' => [['platform.activecollab.recovery']],
            'users roles only' => [['platform.systems.users', 'platform.systems.roles']],
            'missing roles' => [['platform.activecollab.recovery', 'platform.systems.users']]];
    }

    /** @param list<string> $permissions */
    #[DataProvider('incompletePermissions')]
    public function test_permissions_are_required_together_and_do_not_leak_metadata(array $permissions): void
    {
        $user = User::factory()->create(['permissions' => ['platform.index' => true] + array_fill_keys($permissions, true)]);
        $this->actingAs($user)->get($this->url)->assertNotFound()->assertDontSee('Private integration');
        $this->post($this->url.'/confirmAbsence', $this->form())->assertNotFound();
        $this->assertDatabaseCount('active_collab_sync_attempts', 0);
        $this->assertDatabaseCount('admin_audit_logs', 0);
        Http::assertNothingSent();
    }

    public function test_admin_view_is_read_only_and_does_not_serialize_secrets_or_unescaped_source(): void
    {
        $this->actingAs($this->admin())->get($this->url)->assertOk()->assertSee('Восстановление синхронизации')
            ->assertDontSee('ui-ac-secret')->assertDontSee('<script>unsafe</script>', false);
        $this->assertDatabaseCount('admin_audit_logs', 0);
        $this->assertDatabaseCount('active_collab_sync_attempts', 0);
        Http::assertNothingSent();
    }

    public function test_role_permissions_allow_admin_and_post_confirmation_is_audited(): void
    {
        $user = User::factory()->create(['permissions' => ['platform.index' => true]]);
        $role = Role::create(['name' => 'Recovery admin', 'slug' => 'recovery-admin', 'permissions' => [
            'platform.activecollab.recovery' => true, 'platform.systems.users' => true, 'platform.systems.roles' => true,
        ]]);
        $user->roles()->attach($role);
        $this->actingAs($user)->get($this->url)->assertOk();
        $this->post($this->url.'/confirmAbsence', $this->form())->assertRedirect($this->url);
        $this->assertSame('succeeded', $this->export->fresh()->last_attempt_status);
        $this->assertDatabaseCount('admin_audit_logs', 2);
        Http::assertNothingSent();
    }

    public function test_get_action_and_unchecked_confirmation_do_not_change_state(): void
    {
        $this->actingAs($this->admin())->get($this->url.'/confirmAbsence')->assertStatus(405);
        $input = $this->form();
        unset($input['recovery']['acknowledged']);
        $this->post($this->url.'/confirmAbsence', $input)->assertRedirect()->assertSessionHas('errors');
        $this->assertSame('unknown', $this->export->fresh()->last_attempt_status);
        $this->assertDatabaseCount('admin_audit_logs', 0);
    }

    public function test_csrf_is_enforced_and_oversized_action_is_rejected(): void
    {
        $this->actingAs($this->admin());
        $large = $this->form();
        $large['padding'] = str_repeat('x', 17000);
        $this->postJson($this->url.'/confirmAbsence', $large)->assertStatus(413);
        app()->instance('env', 'local');
        try {
            $this->post($this->url.'/confirmAbsence', $this->form())->assertStatus(419);
        } finally {
            app()->instance('env', 'testing');
        }
        $this->assertDatabaseCount('admin_audit_logs', 0);
    }

    public function test_body_cannot_choose_actor_integration_or_a_different_action(): void
    {
        $input = $this->form();
        $input['recovery'] += ['actor_id' => 999, 'integration_id' => (string) Str::uuid(), 'action' => 'replace'];
        $admin = $this->admin();
        $this->actingAs($admin)->post($this->url.'/confirmAbsence', $input)->assertRedirect($this->url);
        $this->assertDatabaseHas('admin_audit_logs', ['actor_id' => $admin->id, 'action' => 'confirm_absence', 'subject_id' => $this->export->id]);
        Http::assertNothingSent();
    }

    private function admin(): User
    {
        return User::factory()->create(['permissions' => ['platform.index' => true,
            'platform.activecollab.recovery' => true, 'platform.systems.users' => true, 'platform.systems.roles' => true]]);
    }

    /** @return array<string, array<string, mixed>> */
    private function form(): array
    {
        return ['recovery' => ['request_id' => (string) Str::uuid(), 'expected_operation_id' => null,
            'expected_status' => 'unknown', 'expected_binding' => 'unbound',
            'reason' => 'Verified absent by administrator', 'acknowledged' => true]];
    }
}
