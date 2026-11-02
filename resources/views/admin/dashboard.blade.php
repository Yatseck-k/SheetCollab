<div class="container-fluid">
    <div class="row g-3 mb-4">
        <div class="col-sm-6 col-xl-3"><div class="card"><div class="card-body"><div class="text-muted">Интеграции</div><strong>{{ $summary['active_integrations'] }} / {{ $summary['integrations'] }}</strong><div class="small text-muted">активных / всего</div></div></div></div>
        <div class="col-sm-6 col-xl-3"><div class="card"><div class="card-body"><div class="text-muted">Связанные задачи</div><strong>{{ $summary['bound'] }}</strong></div></div></div>
        <div class="col-sm-6 col-xl-3"><div class="card"><div class="card-body"><div class="text-muted">Требуют внимания</div><strong>{{ $summary['unknown'] + $summary['failed'] + $summary['remote_missing'] }}</strong><div class="small text-muted">неизвестный результат, ошибка или недоступная задача</div></div></div></div>
        <div class="col-sm-6 col-xl-3"><div class="card"><div class="card-body"><div class="text-muted">Выполняются</div><strong>{{ $summary['running'] }}</strong></div></div></div>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-sm-6 col-xl-3"><div class="card"><div class="card-body"><div class="text-muted">Создания</div><strong>{{ $summary['created'] }}</strong><div class="small text-muted">успешные попытки</div></div></div></div>
        <div class="col-sm-6 col-xl-3"><div class="card"><div class="card-body"><div class="text-muted">Обновления</div><strong>{{ $summary['updated'] }}</strong><div class="small text-muted">успешные попытки</div></div></div></div>
    </div>

    <div class="card mb-4">
        <div class="card-header"><strong>Доступные интеграции</strong></div>
        <div class="table-responsive"><table class="table mb-0">
            <thead><tr><th>Название</th><th>Проект</th><th>Состояние</th><th></th></tr></thead>
            <tbody>
            @forelse ($integrations as $integration)
                <tr>
                    <td>{{ $integration->name }}</td>
                    <td>{{ $integration->active_collab_project_id ?? 'Не выбран' }}</td>
                    <td>{{ $integration->active ? 'Активна' : 'Отключена' }}</td>
                    <td><a href="{{ route('platform.integrations.edit', ['integration' => $integration->id]) }}">Открыть</a></td>
                </tr>
            @empty
                <tr><td colspan="4" class="text-muted">Нет назначенных интеграций.</td></tr>
            @endforelse
            </tbody>
        </table></div>
    </div>

    <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center">
            <strong>Последние попытки синхронизации</strong>
            <a href="{{ route('platform.exports') }}">Вся история</a>
        </div>
        <div class="table-responsive"><table class="table mb-0">
            <thead><tr><th>Время</th><th>Интеграция</th><th>Действие</th><th>Результат</th><th></th></tr></thead>
            <tbody>
            @forelse ($recent_attempts as $attempt)
                <tr>
                    <td>{{ $attempt->started_at?->format('d.m.Y H:i:s') ?? '—' }}</td>
                    <td>{{ $attempt->export?->integration?->name ?? 'Интеграция недоступна' }}</td>
                    <td>{{ ['create' => 'Создание', 'update' => 'Обновление', 'skip' => 'Без изменений', 'reconcile' => 'Сверка'][$attempt->action] ?? 'Операция' }}</td>
                    <td>{{ ['running' => 'Выполняется', 'succeeded' => 'Успешно', 'failed' => 'Ошибка', 'unknown' => 'Требуется сверка'][$attempt->status] ?? 'Неизвестно' }}</td>
                    <td>@if ($attempt->export)<a href="{{ route('platform.exports.attempts', ['export' => $attempt->export_id]) }}">{{ $attempt->export->external_id }}</a>@endif</td>
                </tr>
            @empty
                <tr><td colspan="5" class="text-muted">Попыток пока нет.</td></tr>
            @endforelse
            </tbody>
        </table></div>
    </div>
</div>
