<?php

declare(strict_types=1);

namespace App\Orchid\Layouts\Export;

use Orchid\Screen\Actions\Button;
use Orchid\Screen\Actions\Link;
use Orchid\Screen\Field;
use Orchid\Screen\Fields\CheckBox;
use Orchid\Screen\Fields\Input;
use Orchid\Screen\Fields\TextArea;
use Orchid\Screen\Layouts\Rows;

class ExportRecoveryLayout extends Rows
{
    /** @return Field[] */
    public function fields(): array
    {
        $fields = [
            Input::make('integration_name')->title('Интеграция')->disabled()->style('opacity:1;color:inherit;-webkit-text-fill-color:currentColor'),
            Input::make('project_id')->title('Проект')->disabled()->style('opacity:1;color:inherit;-webkit-text-fill-color:currentColor'),
            Input::make('export.external_id')->title('Исходная запись')->disabled()->style('opacity:1;color:inherit;-webkit-text-fill-color:currentColor'),
            Input::make('export.task_id')->title('Связанная задача')->disabled()->style('opacity:1;color:inherit;-webkit-text-fill-color:currentColor'),
            Input::make('attempt_label')->title('Результат последней попытки')->disabled()->style('opacity:1;color:inherit;-webkit-text-fill-color:currentColor'),
            Input::make('binding_label')->title('Состояние связи')->disabled()
                ->help($this->query->get('export.binding_status') === 'remote_missing'
                    ? 'Задача не найдена или недоступна в этом проекте. Это не доказывает её удаления.' : 'Состояние привязки к задаче ActiveCollab.')
                ->style('opacity:1;color:inherit;-webkit-text-fill-color:currentColor'),
            TextArea::make('source_preview')->title('Выбранный сохранённый источник')->rows(12)->disabled()
                ->help('Для замены будут использованы эти данные и текущие маппинги. Они могут отличаться от актуальных Sheets.')
                ->style('opacity:1;color:inherit;-webkit-text-fill-color:currentColor'),
            Link::make('Использовать источник записи экспорта')->route('platform.exports.recovery', ['export' => $this->query->get('export.id')]),
        ];
        foreach ($this->query->get('attempts', []) as $attempt) {
            $fields[] = Link::make('Источник попытки '.$attempt['id'].' ('.$attempt['status'].')')
                ->route('platform.exports.recovery', ['export' => $this->query->get('export.id'), 'source_attempt_id' => $attempt['id']]);
        }
        foreach (['request_id', 'expected_operation_id', 'expected_status', 'expected_binding', 'source_attempt_id'] as $field) {
            $fields[] = Input::make('recovery.'.$field)->type('hidden');
        }

        return array_merge($fields, [
            TextArea::make('attempts_preview')->title('Последние 50 попыток (UTC)')->rows($this->query->get('attempts', []) === [] ? 2 : 8)->disabled()->style('opacity:1;color:inherit;-webkit-text-fill-color:currentColor'),
            TextArea::make('recovery.reason')->title('Причина решения')->required()->rows(3)->maxlength(1000)
                ->help('От 10 до 1000 символов. Не вводите токены или пароли.')->set('minlength', 10),
            Input::make('recovery.candidate_task_id')->type('number')->min(1)->title('ID существующей задачи для привязки'),
            Button::make('Привязать проверенную задачу')->method('bind')->class('btn btn-primary')->canSee((bool) $this->query->get('allowed.bind'))
                ->confirm('Задача будет проверена в выбранном проекте. Подтверждаете соответствие этой исходной записи?'),
            Button::make('Проверить текущую связь')->method('verify')->class('btn btn-outline-primary')->canSee((bool) $this->query->get('allowed.verify')),
            CheckBox::make('recovery.acknowledged')->sendTrueOrFalse()->title('Подтверждение отсутствия результата или замены')
                ->placeholder('Старые отправляющие процессы остановлены, ActiveCollab проверен, предыдущий результат отсутствует.')
                ->help('Один 404 или истёкшее время ожидания не доказывают отсутствие задачи. Без внешней сверки создание может привести к дубликату.'),
            Button::make('Подтвердить отсутствие результата')->method('confirmAbsence')->class('btn btn-warning')->canSee((bool) $this->query->get('allowed.confirm_absence'))
                ->confirm('Решение разрешит следующую попытку после вашей сверки. Сейчас задача создаваться не будет.'),
            Button::make('Создать замену недоступной задачи')->method('replace')->class('btn btn-danger')->canSee((bool) $this->query->get('allowed.replace'))
                ->confirm('Будет создана новая задача из выбранного источника. Подтверждаете сверку и необходимость замены?'),
        ]);
    }
}
