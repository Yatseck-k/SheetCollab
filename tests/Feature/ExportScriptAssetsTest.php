<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ScriptAsset;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ExportScriptAssetsTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_exports_every_asset_privately_without_modifying_rows_or_printing_content(): void
    {
        Storage::fake('local');
        ScriptAsset::query()->create([
            'script_key' => 'poc.gs', 'name' => 'Legacy', 'content' => 'legacy-script-content-marker',
        ]);
        ScriptAsset::query()->create([
            'script_key' => 'activecollab_sync.gs', 'name' => 'Current', 'content' => 'canonical-script-content-marker',
        ]);

        $this->artisan('scripts:export-assets', ['--output' => 'snapshot.json'])
            ->expectsOutputToContain('Exported 2 asset(s) to private storage: snapshot.json')
            ->assertSuccessful();

        $archive = Storage::disk('local')->get('snapshot.json');
        $decoded = json_decode($archive, true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(1, $decoded['format_version']);
        $this->assertSame(['activecollab_sync.gs', 'poc.gs'], array_column($decoded['assets'], 'script_key'));
        $this->assertSame(['canonical-script-content-marker', 'legacy-script-content-marker'], array_column($decoded['assets'], 'content'));
        $this->assertSame(2, ScriptAsset::query()->count());
        $this->assertSame(0600, fileperms(Storage::disk('local')->path('snapshot.json')) & 0777);

        Artisan::call('scripts:export-assets', ['--output' => 'second.json']);
        $output = Artisan::output();
        $this->assertStringNotContainsString('canonical-script-content-marker', $output);
        $this->assertStringNotContainsString('legacy-script-content-marker', $output);
        $this->assertStringContainsString(hash('sha256', Storage::disk('local')->get('second.json')), $output);
        $this->assertSame(2, ScriptAsset::query()->count());
    }

    public function test_it_rejects_traversal_and_never_overwrites_an_archive(): void
    {
        Storage::fake('local');
        $this->artisan('scripts:export-assets', ['--output' => '../outside.json'])->assertFailed();
        $this->assertFalse(Storage::disk('local')->exists('outside.json'));

        Storage::disk('local')->put('existing.json', 'preserve-this');
        $this->artisan('scripts:export-assets', ['--output' => 'existing.json'])->assertFailed();
        $this->assertSame('preserve-this', Storage::disk('local')->get('existing.json'));
    }
}
