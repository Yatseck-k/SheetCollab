<?php

declare(strict_types=1);

namespace App\Services\ActiveCollab;

use App\Models\ActiveCollabConnection;
use App\Models\ActiveCollabIntegration;
use App\Repositories\ActiveCollab\ActiveCollabConnectionRepository;
use App\Repositories\ActiveCollab\ActiveCollabRepository;
use Illuminate\Validation\ValidationException;

class ActiveCollabIntegrationSettingsService
{
    public function __construct(
        private readonly ActiveCollabRepository $repository,
        private readonly ActiveCollabConnectionRepository $connections,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function prepareForStorage(array $data, ?ActiveCollabIntegration $existing = null): array
    {
        $existingConnectionId = $existing instanceof ActiveCollabIntegration ? $existing->connection_id : null;
        $connectionId = trim((string) ($data['connection_id'] ?? $existingConnectionId ?? ''));
        $connection = $connectionId !== '' ? $this->connections->findActive($connectionId) : null;
        if (! $connection instanceof ActiveCollabConnection) {
            throw ValidationException::withMessages(['connection_id' => 'Выберите активное подключение ActiveCollab.']);
        }

        $projectId = $this->normalizeProjectId(
            $this->valueOrExisting($data, 'active_collab_project_id', $existing?->active_collab_project_id),
        );
        $projects = $this->repository->projectsForConnection($connection);
        if (! in_array($projectId, array_column($projects, 'id'), true)) {
            throw ValidationException::withMessages(['active_collab_project_id' => 'Проект не найден среди доступных проектов выбранного подключения.']);
        }
        $taskLists = $this->repository->taskListsForConnection($connection, $projectId);
        $taskListId = $this->resolveTaskListId(
            $this->valueOrExisting($data, 'active_collab_task_list_id', $existing?->active_collab_task_list_id),
            $taskLists,
        );

        $candidate = new ActiveCollabIntegration();
        $candidate->forceFill([
            'connection_id' => $connection->id,
            'active_collab_project_id' => $projectId,
            'active' => true,
        ]);
        $candidate->setRelation('activeCollabConnection', $connection);
        $this->assertMappingIds($data['task_list_mapping'] ?? null, array_column($taskLists, 'id'), 'task_list_mapping');
        if (! empty($data['assignee_mapping'])) {
            $this->assertMappingIds($data['assignee_mapping'],
                array_column($this->repository->usersForIntegration($candidate), 'id'), 'assignee_mapping');
        }
        if (! empty($data['label_mapping'])) {
            $this->assertMappingIds($data['label_mapping'],
                array_column($this->repository->labelsForIntegration($candidate), 'id'), 'label_mapping');
        }

        unset($data['active_collab_base_url'], $data['active_collab_api_token']);

        return [
            ...$data,
            'connection_id' => $connection->id,
            'spreadsheet_id' => $this->normalizeSpreadsheetId((string) ($data['spreadsheet_id'] ?? '')),
            'active_collab_project_id' => $projectId,
            'active_collab_task_list_id' => $taskListId,
        ];
    }

    public function normalizeSpreadsheetId(string $value): string
    {
        $value = trim($value);

        if ($value === '') {
            throw ValidationException::withMessages(['spreadsheet_id' => 'Укажите Google Sheets ID или полную ссылку на таблицу.']);
        }

        if (preg_match('#/spreadsheets/d/([a-zA-Z0-9_-]+)#', $value, $matches) === 1) {
            return $matches[1];
        }

        return $value;
    }

    public function normalizeProjectId(string|int|null $value): int
    {
        if (is_int($value) && $value > 0) {
            return $value;
        }

        $value = trim((string) $value);
        if (preg_match('#/projects/(\d+)#', $value, $matches) === 1) {
            return (int) $matches[1];
        }

        if ($value !== '' && ctype_digit($value) && (int) $value > 0) {
            return (int) $value;
        }

        throw ValidationException::withMessages(['active_collab_project_id' => 'Project ID должен быть числом или ссылкой на проект ActiveCollab.']);
    }

    /** @param list<array{id: int, name: string}> $taskLists */
    private function resolveTaskListId(mixed $value, array $taskLists): int
    {
        $value = trim((string) $value);
        if ($value !== '' && preg_match('#/task-lists/(\d+)#', $value, $matches) === 1) {
            $value = $matches[1];
        }

        if ($value !== '' && ctype_digit($value)) {
            $id = (int) $value;
            foreach ($taskLists as $taskList) {
                if ($taskList['id'] === $id) {
                    return $id;
                }
            }

            throw ValidationException::withMessages(['active_collab_task_list_id' => 'Task List не найден в выбранном проекте.']);
        }

        if ($value !== '') {
            foreach ($taskLists as $taskList) {
                if (mb_strtolower(trim($taskList['name'])) === mb_strtolower($value)) {
                    return $taskList['id'];
                }
            }

            throw ValidationException::withMessages(['active_collab_task_list_id' => 'Task List с таким названием не найден. Создайте его в ActiveCollab отдельно и выберите существующий список.']);
        }

        if (isset($taskLists[0]['id'])) {
            return $taskLists[0]['id'];
        }

        throw ValidationException::withMessages(['active_collab_task_list_id' => 'В выбранном проекте нет Task Lists. Создайте список в ActiveCollab отдельно.']);
    }

    /** @param array<string, mixed> $data */
    private function valueOrExisting(array $data, string $key, mixed $existingValue): mixed
    {
        $value = $data[$key] ?? null;
        if (is_string($value) && trim($value) === '') {
            return $existingValue;
        }

        return $value ?? $existingValue;
    }

    /** @param array<string, mixed>|null $mapping
     * @param  list<int>  $availableIds
     */
    private function assertMappingIds(?array $mapping, array $availableIds, string $field): void
    {
        foreach ($mapping ?? [] as $value) {
            if (! is_int($value) && ! (is_string($value) && ctype_digit($value))) {
                throw ValidationException::withMessages([$field => 'Значения маппинга должны быть ID из выбранного проекта.']);
            }
            if (! in_array((int) $value, $availableIds, true)) {
                throw ValidationException::withMessages([$field => 'Один из ID маппинга не найден в выбранном проекте.']);
            }
        }
    }
}
