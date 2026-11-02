<?php

declare(strict_types=1);

namespace App\Orchid\Layouts\Integration;

use Orchid\Screen\Field;
use Orchid\Screen\Fields\CheckBox;
use Orchid\Screen\Fields\Input;
use Orchid\Screen\Fields\Select;
use Orchid\Screen\Layouts\Rows;

class IntegrationEditLayout extends Rows
{
    /** @param array<string, string> $connectionOptions */
    public function __construct(private readonly array $connectionOptions = [], private readonly bool $admin = false) {}

    /** @return array<int, Field> */
    public function fields(): array
    {
        $fields = [
            Input::make('integration.name')
                ->title('Название')
                ->placeholder('SheetCollab Bug Tracker')
                ->required()
                ->canSee($this->admin),

            Input::make('integration.spreadsheet_id')
                ->title('Google Sheets ID или URL')
                ->placeholder('https://docs.google.com/spreadsheets/d/...')
                ->help('Можно вставить полную ссылку на таблицу. ID будет извлечен автоматически.')
                ->required()
                ->canSee($this->admin),

            Select::make('integration.connection_id')
                ->title('ActiveCollab connection')
                ->options($this->connectionOptions)
                ->help('Сначала создайте и проверьте подключение в разделе «Подключения ActiveCollab». Проверка не выполняется при открытии этой формы.')
                ->required()
                ->canSee($this->admin),

            Input::make('integration.active_collab_project_id')
                ->title('Project ID или URL проекта')
                ->placeholder('10 или https://your-activecollab.example.com/projects/10')
                ->help('Список доступных проектов показан в настройках выбранного connection. Укажите ID или ссылку проекта.')
                ->required()
                ->canSee($this->admin),

            Input::make('integration.active_collab_task_list_id')
                ->title('Task List ID или название списка (необязательно)')
                ->placeholder('31 или Backend')
                ->help('Можно указать ID уже существующего списка. Сохранение не создаёт Task List.')
                ->canSee($this->admin),

        ];
        if ($this->admin) {
            $fields[] = CheckBox::make('integration.active')
                ->title('Активна')
                ->placeholder('Интеграция включена')
                ->sendTrueOrFalse();
        }

        return $fields;
    }
}
