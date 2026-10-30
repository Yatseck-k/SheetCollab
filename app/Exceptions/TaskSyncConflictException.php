<?php

declare(strict_types=1);

namespace App\Exceptions;

class TaskSyncConflictException extends \RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        public readonly ?string $operationId,
        public readonly int $httpStatus = 409,
    ) {
        parent::__construct(match ($errorCode) {
            'legacy_binding_conflict' => 'Режим идентификации не совпадает. Завершите перенос UUID и обновите Apps Script.',
            'sync_in_progress' => 'Синхронизация уже выполняется.',
            'pre_dispatch_failed' => 'Подготовка синхронизации прервана до отправки.',
            'remote_task_missing' => 'Задача не найдена или недоступна в этом проекте.',
            'recovery_conflict' => 'Состояние изменилось. Обновите страницу и проверьте решение.',
            'mapping_invalid' => 'Не настроен обязательный маппинг ActiveCollab.',
            default => 'Результат синхронизации неизвестен. Требуется сверка.',
        });
    }
}
