<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\ScriptAsset;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ExportScriptAssets extends Command
{
    protected $signature = 'scripts:export-assets {--output= : Private JSON archive filename (basename only)}';

    protected $description = 'Export all legacy Apps Script database assets to private storage without changing them';

    public function handle(): int
    {
        $filename = (string) ($this->option('output') ?: 'script-assets-'.now()->format('Ymd-His').'-'.Str::lower(Str::random(6)).'.json');
        if (! preg_match('/\A[a-z0-9][a-z0-9._-]*\.json\z/i', $filename) || str_contains($filename, '..')) {
            $this->error('Export failed. Use a JSON basename without directories or traversal segments.');

            return self::FAILURE;
        }

        try {
            $assets = ScriptAsset::query()->orderBy('script_key')->orderBy('id')
                ->get(['id', 'script_key', 'name', 'content', 'created_at', 'updated_at'])
                ->map(static fn (ScriptAsset $asset): array => [
                    'id' => $asset->getRawOriginal('id'),
                    'script_key' => $asset->getRawOriginal('script_key'),
                    'name' => $asset->getRawOriginal('name'),
                    'content' => $asset->getRawOriginal('content'),
                    'created_at' => $asset->getRawOriginal('created_at'),
                    'updated_at' => $asset->getRawOriginal('updated_at'),
                ])->all();
            $json = json_encode([
                'format_version' => 1,
                'exported_at' => now()->toISOString(),
                'assets' => $assets,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n";

            $disk = Storage::disk('local');
            if ($disk->exists($filename)) {
                $this->error('Export failed. The target file already exists; choose a new filename.');

                return self::FAILURE;
            }

            $path = $disk->path($filename);
            $handle = @fopen($path, 'xb');
            if ($handle === false) {
                $this->error('Export failed. Private storage is unavailable or the target already exists.');

                return self::FAILURE;
            }

            try {
                $offset = 0;
                $length = strlen($json);
                while ($offset < $length) {
                    $written = fwrite($handle, substr($json, $offset));
                    if ($written === false || $written === 0) {
                        throw new \RuntimeException('Could not complete archive write.');
                    }
                    $offset += $written;
                }
                if (! fflush($handle)) {
                    throw new \RuntimeException('Could not flush archive.');
                }
            } catch (\Throwable) {
                fclose($handle);
                @unlink($path);
                throw new \RuntimeException('Could not write the private archive.');
            }
            fclose($handle);

            if (! @chmod($path, 0600)) {
                @unlink($path);
                throw new \RuntimeException('Could not restrict archive permissions.');
            }

            $this->info(sprintf('Exported %d asset(s) to private storage: %s', count($assets), $filename));
            $this->line('SHA-256: '.hash('sha256', $json));

            return self::SUCCESS;
        } catch (\Throwable) {
            $this->error('Export failed. Verify database availability and private storage; script contents were not printed.');

            return self::FAILURE;
        }
    }
}
