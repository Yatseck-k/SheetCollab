<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\File;
use Symfony\Component\HttpFoundation\StreamedResponse;

class GoogleAppsScriptService
{
    private const string SCRIPT_FILENAME = 'activecollab_sync.gs';

    public function path(): string
    {
        return resource_path('apps-script/'.self::SCRIPT_FILENAME);
    }

    public function read(): string
    {
        return File::get($this->path());
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
}
