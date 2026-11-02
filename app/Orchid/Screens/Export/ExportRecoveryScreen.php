<?php

declare(strict_types=1);

namespace App\Orchid\Screens\Export;

use App\Data\ActiveCollab\ActiveCollabRecoveryData;
use App\Models\ActiveCollabTaskExport;
use App\Orchid\Layouts\Export\ExportRecoveryLayout;
use App\Services\ActiveCollab\ActiveCollabRecoveryService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Orchid\Screen\Screen;
use Orchid\Support\Facades\Toast;

class ExportRecoveryScreen extends Screen
{
    /** @return iterable<string, mixed> */
    public function query(Request $request, ActiveCollabTaskExport $export, ActiveCollabRecoveryService $service): iterable
    {
        $this->checkAccess($request);
        $input = $request->validate(['source_attempt_id' => ['nullable', 'uuid']]);
        $view = $service->view((int) $request->user()->getAuthIdentifier(), $export->id, $input['source_attempt_id'] ?? null);
        $state = $view['export'];

        return $view + [
            'source_preview' => $this->sourcePreview($view['source']),
            'attempts_preview' => $this->attemptsPreview($view['attempts']),
            'recovery' => ['request_id' => (string) Str::uuid(), 'expected_operation_id' => $state['operation_id'],
                'expected_status' => $state['last_attempt_status'], 'expected_binding' => $state['binding_status'],
                'source_attempt_id' => $input['source_attempt_id'] ?? null],
        ];
    }

    public function name(): ?string
    {
        return 'Восстановление синхронизации';
    }

    /** @return iterable<string> */
    public function permission(): ?iterable
    {
        return ['platform.activecollab.recovery'];
    }

    protected function checkAccess(Request $request): bool
    {
        abort_if($request->user() === null, 404);
        Gate::forUser($request->user())->authorize('recover', ActiveCollabTaskExport::class);
        abort_if($request->isMethodSafe() && $request->route('method') !== null && $request->route('method') !== 'view', 405);

        return true;
    }

    /** @return iterable<class-string<ExportRecoveryLayout>> */
    public function layout(): iterable
    {
        return [ExportRecoveryLayout::class];
    }

    public function bind(Request $request, ActiveCollabTaskExport $export, ActiveCollabRecoveryService $service): RedirectResponse
    {
        return $this->submit($request, $export, $service, 'bind');
    }

    public function verify(Request $request, ActiveCollabTaskExport $export, ActiveCollabRecoveryService $service): RedirectResponse
    {
        return $this->submit($request, $export, $service, 'verify');
    }

    public function confirmAbsence(Request $request, ActiveCollabTaskExport $export, ActiveCollabRecoveryService $service): RedirectResponse
    {
        return $this->submit($request, $export, $service, 'confirm_absence');
    }

    public function replace(Request $request, ActiveCollabTaskExport $export, ActiveCollabRecoveryService $service): RedirectResponse
    {
        return $this->submit($request, $export, $service, 'replace');
    }

    /** @param array<string, mixed> $source */
    private function sourcePreview(array $source): string
    {
        $labels = ['spreadsheet_id' => 'Таблица', 'sheet_id' => 'Лист', 'row_number' => 'Строка', 'severity' => 'Серьёзность',
            'priority' => 'Приоритет', 'device' => 'Устройство', 'resolution' => 'Разрешение', 'environment' => 'Стенд',
            'section' => 'Раздел', 'problem_description' => 'Описание', 'screenshot_url' => 'Скриншот', 'comment' => 'Комментарий',
            'task_type' => 'Тип задачи', 'responsible' => 'Ответственный', 'reporter' => 'Автор', 'sheet_status' => 'Статус в таблице'];
        $lines = [];
        foreach ($labels as $key => $label) {
            if (isset($source[$key])) {
                $lines[] = $label.': '.(is_scalar($source[$key]) ? (string) $source[$key] : '(некорректное значение)');
            }
        }

        return $lines === [] ? 'Сохранённого источника нет.' : implode("\n", $lines);
    }

    /** @param list<array<string, mixed>> $attempts */
    private function attemptsPreview(array $attempts): string
    {
        $lines = [];
        foreach ($attempts as $attempt) {
            $action = match ($attempt['action']) {
                'create' => 'Создание', 'update' => 'Обновление', 'skip' => 'Без изменений', 'reconcile' => 'Восстановление', default => 'Проверка'
            };
            $status = match ($attempt['status']) {
                'running' => 'Выполняется', 'succeeded' => 'Успешно', 'failed' => 'Ошибка', default => 'Требуется сверка'
            };
            $date = $attempt['started_at'] instanceof \DateTimeInterface ? $attempt['started_at']->format('d.m.Y H:i:s') : '';
            $reason = match ($attempt['error_code']) {
                'upstream_rejected' => 'Запрос отклонён ActiveCollab', 'upstream_uncertain' => 'Ошибка ActiveCollab',
                'transport_uncertain' => 'Соединение прервано или истёк таймаут', 'protocol_invalid' => 'Неподтверждённый ответ',
                'remote_task_missing' => 'Задача не найдена или недоступна', 'mapping_invalid' => 'Не настроен маппинг',
                'pre_dispatch_failed' => 'Отправка не началась', 'sync_outcome_unknown' => 'Результат не подтверждён', default => '',
            };
            $http = isset($attempt['http_status']) ? ' (HTTP '.(int) $attempt['http_status'].')' : '';
            $candidate = isset($attempt['candidate_task_id']) ? ' Получен кандидат #'.(int) $attempt['candidate_task_id'].': '.($attempt['candidate_task_url'] ?? '').'. Требуется проверка связи.' : '';
            $lines[] = $date.' — '.$action.': '.$status.($reason !== '' ? '. '.$reason : '').$http.$candidate;
        }

        return $lines === [] ? 'Попыток пока нет.' : implode("\n", $lines);
    }

    private function submit(Request $request, ActiveCollabTaskExport $export, ActiveCollabRecoveryService $service, string $action): RedirectResponse
    {
        $this->checkAccess($request);
        abort_if(strlen($request->getContent()) > 16384, 413);
        $input = $request->validate(['recovery' => ['required', 'array']]);
        try {
            $data = ActiveCollabRecoveryData::fromInput($input['recovery'], $action);
        } catch (ValidationException $e) {
            $errors = [];
            foreach ($e->errors() as $field => $messages) {
                $errors['recovery.'.$field] = $messages;
            }
            throw ValidationException::withMessages($errors);
        }
        $result = $service->recover((int) $request->user()->getAuthIdentifier(), $export->id, $data);
        if ($result['status'] === 'succeeded') {
            Toast::info('Решение сохранено. Содержимое задачи подтверждается отдельной синхронизацией.');
        } else {
            Toast::warning('Попытка не подтверждена. Проверьте историю перед новым решением.');
        }

        return redirect()->route('platform.exports.recovery', ['export' => $export->id]);
    }
}
