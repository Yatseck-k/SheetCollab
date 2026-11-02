<div class="card mb-3">
    <div class="card-body">
        <div class="row g-3">
            <div class="col-md-5"><span class="text-muted">Интеграция</span><div>{{ $export['integration_name'] ?? 'Интеграция недоступна' }}</div></div>
            <div class="col-md-4"><span class="text-muted">Исходная запись</span><div><code>{{ $export['external_id'] }}</code></div></div>
            <div class="col-md-3"><span class="text-muted">Связь</span><div>{{ ['bound' => 'Привязана', 'unbound' => 'Не привязана', 'remote_missing' => 'Задача не найдена'][$export['binding_status'] ?? ''] ?? 'Не классифицирована' }}</div></div>
            <div class="col-md-5"><span class="text-muted">Task ID</span><div>{{ $export['task_id'] ?? '—' }}</div></div>
            <div class="col-md-7"><span class="text-muted">Последняя попытка</span><div>{{ ['idle' => 'Не отправлялась', 'running' => 'Выполняется', 'succeeded' => 'Успешно', 'failed' => 'Ошибка', 'unknown' => 'Требуется сверка'][$export['last_attempt_status'] ?? ''] ?? 'Не классифицирована' }}</div></div>
        </div>
    </div>
</div>
