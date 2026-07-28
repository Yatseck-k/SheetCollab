<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\ScriptAsset;
use Illuminate\Support\Facades\File;
use Symfony\Component\HttpFoundation\StreamedResponse;

class GoogleAppsScriptService
{
    private const string SCRIPT_KEY = 'activecollab_sync.gs';

    private const string SCRIPT_NAME = 'ActiveCollab Sync Script';

    private const string SCRIPT_FILENAME = 'activecollab_sync.gs';

    public function path(): string
    {
        return resource_path('apps-script/'.self::SCRIPT_FILENAME);
    }

    public function read(): string
    {
        return $this->asset()->content;
    }

    public function write(string $content): void
    {
        $asset = $this->asset();
        $asset->content = $content;
        $asset->save();
    }

    public function download(): StreamedResponse
    {
        $content = $this->read();

        return response()->streamDownload(function () use ($content): void {
            echo $content;
        }, self::SCRIPT_FILENAME, [
            'Content-Type' => 'application/javascript; charset=UTF-8',
        ]);
    }

    public function fileExists(): bool
    {
        return File::exists($this->path());
    }

    public function sourceLabel(): string
    {
        return $this->fileExists()
            ? 'База данных, исходный шаблон доступен в resources/apps-script/activecollab_sync.gs'
            : 'База данных';
    }

    private function asset(): ScriptAsset
    {
        $asset = ScriptAsset::where('script_key', self::SCRIPT_KEY)->first();

        if ($asset !== null) {
            return $asset;
        }

        return ScriptAsset::create([
            'script_key' => self::SCRIPT_KEY,
            'name' => self::SCRIPT_NAME,
            'content' => $this->defaultContent(),
        ]);
    }

    private function defaultContent(): string
    {
        if (File::exists($this->path())) {
            return File::get($this->path());
        }

        return <<<'JS'
// Вставьте сюда код Google Apps Script и сохраните его в админке.
// После сохранения файл можно скачать кнопкой "Скачать activecollab_sync.gs".
JS;
    }
}
