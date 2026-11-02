<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ScriptAsset;
use App\Models\User;
use App\Services\GoogleAppsScriptService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GoogleAppsScriptDownloadTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('app.key', 'base64:J63P4m4KjJ8l0RkGxJZ4Kf9P9+K6f2R4R6V4a2M1LxQ=');
    }

    public function test_download_uses_the_git_file_byte_for_byte_without_db_bootstrap(): void
    {
        $service = app(GoogleAppsScriptService::class);
        $expected = file_get_contents(resource_path('apps-script/activecollab_sync.gs'));
        $this->assertSame($expected, $service->read());

        $admin = User::factory()->create(['permissions' => ['platform.index' => true, 'platform.activecollab.admin' => true]]);
        $response = $this->actingAs($admin)->get(route('platform.script.download'));
        $response->assertOk()->assertDownload('activecollab_sync.gs');
        $this->assertSame($expected, $response->streamedContent());
        $this->assertDatabaseCount('script_assets', 0);
        $this->actingAs($admin)->get('/admin/script')->assertNotFound();
        $this->actingAs($admin)->post('/admin/script/save', ['script' => ['content' => 'should-not-save']])->assertNotFound();
        $this->assertDatabaseCount('script_assets', 0);
    }

    public function test_download_does_not_replace_archived_database_assets(): void
    {
        ScriptAsset::query()->create([
            'script_key' => 'activecollab_sync.gs', 'name' => 'Archived custom script', 'content' => 'archived-version',
        ]);

        $service = app(GoogleAppsScriptService::class);

        $this->assertSame(file_get_contents(resource_path('apps-script/activecollab_sync.gs')), $service->read());
        $this->assertDatabaseHas('script_assets', ['content' => 'archived-version']);
    }
}
