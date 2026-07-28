<?php

declare(strict_types=1);

namespace App\Orchid\Layouts\Script;

use Orchid\Screen\Field;
use Orchid\Screen\Fields\Code;
use Orchid\Screen\Fields\Input;
use Orchid\Screen\Layouts\Rows;

class ScriptEditorLayout extends Rows
{
    /** @return array<int, Field> */
    public function fields(): array
    {
        return [
            Input::make('script.source')
                ->title('Источник')
                ->readonly(),

            Input::make('script.path')
                ->title('Файл на диске')
                ->readonly(),

            Code::make('script.content')
                ->title('Содержимое activecollab_sync.gs')
                ->language(Code::JS)
                ->lineNumbers()
                ->height('720px')
                ->help('Содержимое хранится в базе данных. Если файл resources/apps-script/activecollab_sync.gs существует, он используется как основной стартовый шаблон. Если файла нет, скрипт можно вставить вручную и сохранить.'),
        ];
    }
}
