<?php

declare(strict_types=1);

namespace App\Orchid\Layouts\Integration;

use Orchid\Screen\Field;
use Orchid\Screen\Fields\CheckBox;
use Orchid\Screen\Fields\Input;
use Orchid\Screen\Layouts\Rows;

class IntegrationEditLayout extends Rows
{
    /** @return array<int, Field> */
    public function fields(): array
    {
        return [
            Input::make('integration.name')
                ->title('Название')
                ->placeholder('SheetCollab Bug Tracker')
                ->required(),

            Input::make('integration.spreadsheet_id')
                ->title('Google Sheets ID или URL')
                ->placeholder('https://docs.google.com/spreadsheets/d/...')
                ->help('Можно вставить полную ссылку на таблицу. ID будет извлечен автоматически.')
                ->required(),

            Input::make('integration.active_collab_base_url')
                ->title('ActiveCollab API Base URL')
                ->placeholder('https://your-activecollab.example.com/api/v1')
                ->help('Можно вставить обычный URL ActiveCollab. Суффикс /api/v1 будет добавлен автоматически.')
                ->required(),

            Input::make('integration.active_collab_project_id')
                ->title('Project ID или URL проекта')
                ->placeholder('10 или https://your-activecollab.example.com/projects/10')
                ->help('Можно указать только ID проекта или вставить полную ссылку на проект.')
                ->required(),

            Input::make('integration.active_collab_task_list_id')
                ->title('Task List ID или название списка (необязательно)')
                ->placeholder('31 или Backend')
                ->help('Можно указать ID или название списка. Если указанное имя не найдено, сервис попробует создать такой task list в проекте. Если поле пустое, будет выбран первый доступный список проекта.'),

            Input::make('integration.active_collab_api_token')
                ->title('ActiveCollab API Token')
                ->type('password')
                ->help('Можно оставить пустым, если глобальный ACTIVECOLLAB_API_TOKEN уже задан в .env.'),

            CheckBox::make('integration.active')
                ->title('Активна')
                ->placeholder('Интеграция включена')
                ->sendTrueOrFalse(),
        ];
    }
}
