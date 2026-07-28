<?php

declare(strict_types=1);

namespace App\Orchid\Layouts\Integration;

use Orchid\Screen\Field;
use Orchid\Screen\Fields\Input;
use Orchid\Screen\Fields\Matrix;
use Orchid\Screen\Layouts\Rows;

class IntegrationMappingLayout extends Rows
{
    /** @return array<int, Field> */
    public function fields(): array
    {
        return [
            Matrix::make('integration.task_list_mapping')
                ->title('Маппинг Task List по типу задачи')
                ->help('Ключ — значение из колонки «Тип задач» (например: backend). Значение — ID Task List в ActiveCollab.')
                ->columns(['Тип задачи' => 'key', 'Task List ID' => 'value'])
                ->fields([
                    'key' => Input::make()->placeholder('backend'),
                    'value' => Input::make()->placeholder('101')->type('number'),
                ]),

            Matrix::make('integration.assignee_mapping')
                ->title('Маппинг ответственных')
                ->help('Ключ — имя из колонки «Ответственный» (точное совпадение). Значение — User ID в ActiveCollab.')
                ->columns(['Ответственный' => 'key', 'User ID' => 'value'])
                ->fields([
                    'key' => Input::make()->placeholder('Tony Yats'),
                    'value' => Input::make()->placeholder('3')->type('number'),
                ]),

            Matrix::make('integration.label_mapping')
                ->title('Маппинг меток по серьёзности')
                ->help('Ключ — значение из колонки «Серьезность» в нижнем регистре (например: critical). Значение — Label ID в ActiveCollab.')
                ->columns(['Серьёзность' => 'key', 'Label ID' => 'value'])
                ->fields([
                    'key' => Input::make()->placeholder('critical'),
                    'value' => Input::make()->placeholder('7')->type('number'),
                ]),
        ];
    }
}
