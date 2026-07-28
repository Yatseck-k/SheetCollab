<?php

declare(strict_types=1);

namespace App\Orchid\Screens\Script;

use App\Orchid\Layouts\Script\ScriptEditorLayout;
use App\Services\GoogleAppsScriptService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Orchid\Screen\Actions\Button;
use Orchid\Screen\Actions\Link;
use Orchid\Screen\Screen;
use Orchid\Support\Facades\Toast;

class ScriptEditorScreen extends Screen
{
    public function __construct(
        private readonly GoogleAppsScriptService $scriptService,
    ) {}

    /** @return iterable<string, mixed> */
    public function query(): iterable
    {
        return [
            'script' => [
                'content' => $this->scriptService->read(),
                'source' => $this->scriptService->sourceLabel(),
                'path' => $this->scriptService->fileExists()
                    ? $this->scriptService->path()
                    : 'Файл на диске не требуется',
            ],
        ];
    }

    #[\Override]
    public function name(): ?string
    {
        return 'Apps Script';
    }

    #[\Override]
    public function description(): ?string
    {
        return 'Редактирование и скачивание activecollab_sync.gs. Каноническое содержимое хранится в базе данных.';
    }

    #[\Override]
    public function commandBar(): iterable
    {
        return [
            Link::make('Скачать activecollab_sync.gs')
                ->icon('bs.download')
                ->route('platform.script.download')
                ->download(),

            Button::make('Сохранить')
                ->icon('bs.check-circle')
                ->method('save'),
        ];
    }

    public function layout(): iterable
    {
        return [
            ScriptEditorLayout::class,
        ];
    }

    public function save(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'script.content' => ['required', 'string'],
        ])['script'];

        $this->scriptService->write($data['content']);

        Toast::info('Скрипт activecollab_sync.gs сохранен.');

        return redirect()->route('platform.script');
    }
}
