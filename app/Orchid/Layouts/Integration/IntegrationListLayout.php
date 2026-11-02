<?php

declare(strict_types=1);

namespace App\Orchid\Layouts\Integration;

use App\Models\ActiveCollabIntegration;
use Illuminate\Support\Facades\Gate;
use Orchid\Screen\Actions\Button;
use Orchid\Screen\Actions\DropDown;
use Orchid\Screen\Actions\Link;
use Orchid\Screen\Layouts\Table;
use Orchid\Screen\TD;

class IntegrationListLayout extends Table
{
    public $target = 'integrations';

    /** @return array<int, TD> */
    public function columns(): array
    {
        return [
            TD::make('name', 'Название')
                ->cantHide()
                ->render(fn (ActiveCollabIntegration $i): Link => Link::make($i->name)
                    ->route('platform.integrations.edit', ['integration' => $i->id])),

            TD::make('spreadsheet_id', 'Google Sheets ID')
                ->render(fn (ActiveCollabIntegration $i): string => '<code>'.e($i->spreadsheet_id).'</code>'),

            TD::make('active_collab_project_id', 'Project ID')
                ->align(TD::ALIGN_CENTER)
                ->render(fn (ActiveCollabIntegration $i) => $i->active_collab_project_id),

            TD::make('active_collab_task_list_id', 'Task List ID')
                ->align(TD::ALIGN_CENTER)
                ->render(fn (ActiveCollabIntegration $i) => $i->active_collab_task_list_id),

            TD::make('active', 'Активна')
                ->align(TD::ALIGN_CENTER)
                ->render(fn (ActiveCollabIntegration $i): string => $i->active
                    ? '<span class="badge bg-success">Да</span>'
                    : '<span class="badge bg-secondary">Нет</span>'),

            TD::make('updated_at', 'Обновлена')
                ->align(TD::ALIGN_RIGHT)
                ->render(fn (ActiveCollabIntegration $i) => $i->updated_at?->format('d.m.Y H:i') ?? '—'),

            TD::make('Действия')
                ->align(TD::ALIGN_CENTER)
                ->width('120px')
                ->render(fn (ActiveCollabIntegration $i) => DropDown::make()
                    ->icon('bs.three-dots-vertical')
                    ->list([
                        Link::make('Редактировать')
                            ->icon('bs.pencil')
                            ->route('platform.integrations.edit', ['integration' => $i->id]),

                        Button::make('Удалить')
                            ->icon('bs.trash3')
                            ->confirm('Удалить интеграцию «'.$i->name.'»? Это действие нельзя отменить.')
                            ->method('remove', ['id' => $i->id])
                            ->canSee(Gate::allows('delete', $i)),
                    ])),
        ];
    }
}
