<?php

declare(strict_types=1);

namespace App\Orchid\Layouts\Connection;

use Orchid\Screen\Field;
use Orchid\Screen\Fields\CheckBox;
use Orchid\Screen\Fields\Input;
use Orchid\Screen\Fields\TextArea;
use Orchid\Screen\Layouts\Rows;

class ConnectionEditLayout extends Rows
{
    /** @return array<int, Field> */
    public function fields(): array
    {
        return [
            Input::make('connection.name')->title('Название')->required()->maxlength(255),
            Input::make('connection.base_url')->title('ActiveCollab API URL')
                ->placeholder('https://your-activecollab.example.com/api/v1')
                ->help('URL без логина, пароля, query и fragment. Для нового подключения будет добавлен /api/v1.'),
            Input::make('connection.api_token')->title('ActiveCollab API Token')->type('password')
                ->help('При редактировании оставьте пустым, чтобы не менять сохранённый token.'),
            CheckBox::make('connection.active')->title('Активно')->sendTrueOrFalse(),
            TextArea::make('projects_summary')->title('Проекты, доступные этому подключению')->rows(8)->disabled(),
        ];
    }
}
