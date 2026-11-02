<?php

declare(strict_types=1);

namespace App\Orchid\Layouts\Export;

use App\Models\ActiveCollabSyncAttempt;
use Orchid\Screen\Layouts\Table;
use Orchid\Screen\TD;

class ExportAttemptsLayout extends Table
{
    protected $target = 'attempts';

    /** @return array<int, TD> */
    protected function columns(): array
    {
        return [
            TD::make('started_at', 'Начало')->render(fn (ActiveCollabSyncAttempt $attempt) => $attempt->started_at->format('d.m.Y H:i:s')),
            TD::make('action', 'Действие')->render(fn (ActiveCollabSyncAttempt $attempt) => match ($attempt->action) {
                'create' => 'Создание', 'update' => 'Обновление', 'skip' => 'Без изменений', 'reconcile' => 'Сверка', default => 'Операция',
            }),
            TD::make('status', 'Результат')->render(fn (ActiveCollabSyncAttempt $attempt) => match ($attempt->status) {
                'running' => 'Выполняется', 'succeeded' => 'Успешно', 'failed' => 'Ошибка', 'unknown' => 'Требуется сверка', default => 'Неизвестно',
            }),
            TD::make('error_code', 'Причина')->render(fn (ActiveCollabSyncAttempt $attempt) => match ($attempt->error_code) {
                'upstream_rejected' => 'Запрос отклонён ActiveCollab', 'upstream_uncertain' => 'Неопределённый ответ ActiveCollab',
                'transport_uncertain' => 'Ошибка соединения или таймаут', 'protocol_invalid' => 'Ответ не подтверждён',
                'remote_task_missing' => 'Задача не найдена или недоступна', 'mapping_invalid' => 'Не настроен маппинг',
                'pre_dispatch_failed' => 'Отправка не началась', 'sync_outcome_unknown' => 'Результат не подтверждён', default => '—',
            }),
            TD::make('http_status', 'HTTP')->render(fn (ActiveCollabSyncAttempt $attempt) => $attempt->http_status ?? '—'),
            TD::make('finished_at', 'Завершение')->render(fn (ActiveCollabSyncAttempt $attempt) => $attempt->finished_at?->format('d.m.Y H:i:s') ?? '—'),
        ];
    }
}
