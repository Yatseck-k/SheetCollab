<?php

declare(strict_types=1);

namespace App\Orchid\Layouts\Export;

use App\Models\ActiveCollabTaskExport;
use Orchid\Screen\Actions\Link;
use Orchid\Screen\Layouts\Table;
use Orchid\Screen\TD;

class ExportListLayout extends Table
{
    public $target = 'exports';

    /** @return array<int, TD> */
    public function columns(): array
    {
        return [
            TD::make('external_id', 'Строка')
                ->cantHide()
                ->render(fn (ActiveCollabTaskExport $e): string => '<code class="small">'.e($e->external_id).'</code>'),

            TD::make('status', 'Статус')
                ->align(TD::ALIGN_CENTER)
                ->render(fn (ActiveCollabTaskExport $e): string => match ($e->status) {
                    'created' => '<span class="badge bg-success">Создана</span>',
                    'updated' => '<span class="badge bg-info">Обновлена</span>',
                    'failed' => '<span class="badge bg-danger">Ошибка</span>',
                    default => '<span class="badge bg-secondary">Ожидание</span>',
                }),

            TD::make('task_id', 'Task ID')
                ->align(TD::ALIGN_CENTER)
                ->render(fn (ActiveCollabTaskExport $e) => $e->task_url
                    ? Link::make((string) $e->task_id)
                        ->href($e->task_url)
                        ->target('_blank')
                    : ($e->task_id ?? '—')),

            TD::make('row_number', 'Строка #')
                ->align(TD::ALIGN_CENTER)
                ->render(fn (ActiveCollabTaskExport $e) => $e->row_number ?? '—'),

            TD::make('error_message', 'Ошибка')
                ->defaultHidden()
                ->render(fn (ActiveCollabTaskExport $e): string => $e->error_message
                    ? '<span class="text-danger small">'.e(mb_substr($e->error_message, 0, 80)).'</span>'
                    : ''),

            TD::make('updated_at', 'Синхронизация')
                ->align(TD::ALIGN_RIGHT)
                ->render(fn (ActiveCollabTaskExport $e) => $e->updated_at?->format('d.m.Y H:i') ?? '—'),
        ];
    }
}
