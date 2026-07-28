<?php

declare(strict_types=1);

namespace App\Services\ActiveCollab;

use App\Models\ActiveCollabIntegration;
use App\Repositories\ActiveCollab\ActiveCollabRepository;

class ActiveCollabIntegrationSettingsService
{
    public function __construct(
        private readonly ActiveCollabRepository $repository,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function prepareForStorage(array $data, ?ActiveCollabIntegration $existing = null): array
    {
        $baseUrl = $this->normalizeBaseUrl(
            $this->valueOrExisting($data, 'active_collab_base_url', $existing?->active_collab_base_url)
        );

        $projectId = $this->normalizeProjectId(
            $this->valueOrExisting($data, 'active_collab_project_id', $existing?->active_collab_project_id)
        );

        $token = $this->resolveApiToken(
            $this->valueOrExisting($data, 'active_collab_api_token', $existing?->active_collab_api_token)
        );

        return [
            ...$data,
            'spreadsheet_id' => $this->normalizeSpreadsheetId((string) ($data['spreadsheet_id'] ?? '')),
            'active_collab_base_url' => $baseUrl,
            'active_collab_project_id' => $projectId,
            'active_collab_task_list_id' => $this->resolveTaskListId(
                $data['active_collab_task_list_id'] ?? $existing?->active_collab_task_list_id,
                $baseUrl,
                $token,
                $projectId,
            ),
            'active_collab_api_token' => $token,
        ];
    }

    public function normalizeSpreadsheetId(string $value): string
    {
        $value = trim($value);

        if ($value === '') {
            throw new \InvalidArgumentException('Укажите Google Sheets ID или полную ссылку на таблицу.');
        }

        if (preg_match('#/spreadsheets/d/([a-zA-Z0-9_-]+)#', $value, $matches) === 1) {
            return $matches[1];
        }

        return $value;
    }

    public function normalizeBaseUrl(?string $value): string
    {
        $value = $this->normalizeUrl($value ?: (string) config('services.active_collab.base_url'));
        $value = preg_replace('#/projects/\d+.*$#', '', $value) ?? $value;

        if (preg_match('#/api/v\d+$#', $value) === 1) {
            return $value;
        }

        $value = preg_replace('#/api/v\d+.*$#', '', $value) ?? $value;

        return $value.'/api/v1';
    }

    public function normalizeProjectId(string|int|null $value): int
    {
        if (is_int($value) && $value > 0) {
            return $value;
        }

        $value = trim((string) $value);

        if ($value === '') {
            $value = (string) config('services.active_collab.project_id');
        }

        if (preg_match('#/projects/(\d+)#', $value, $matches) === 1) {
            return (int) $matches[1];
        }

        if (ctype_digit($value) && (int) $value > 0) {
            return (int) $value;
        }

        throw new \InvalidArgumentException('Project ID должен быть числом или ссылкой на проект ActiveCollab.');
    }

    public function resolveApiToken(?string $value): string
    {
        $value = trim((string) $value);

        if ($value !== '') {
            return $value;
        }

        $value = trim((string) config('services.active_collab.api_token'));

        if ($value !== '') {
            return $value;
        }

        throw new \InvalidArgumentException('Укажите ActiveCollab API Token или задайте ACTIVECOLLAB_API_TOKEN в .env.');
    }

    public function resolveTaskListId(
        string|int|null $value,
        string $baseUrl,
        string $token,
        int $projectId,
    ): int {
        if (is_int($value) && $value > 0) {
            return $value;
        }

        $value = trim((string) $value);

        if (preg_match('#/task-lists/(\d+)#', $value, $matches) === 1) {
            return (int) $matches[1];
        }

        if ($value !== '' && ctype_digit($value) && (int) $value > 0) {
            return (int) $value;
        }

        $taskLists = $this->repository->getTaskListsForConnection($baseUrl, $token, $projectId);

        if ($value !== '') {
            $taskListId = $this->findTaskListIdByName($taskLists, $value);

            if ($taskListId !== null) {
                return $taskListId;
            }

            $createdTaskList = $this->repository->createTaskListForConnection($baseUrl, $token, $projectId, $value);
            $taskListId = $this->extractFirstId($createdTaskList);

            if ($taskListId !== null) {
                return $taskListId;
            }

            throw new \InvalidArgumentException('Не удалось создать Task List в ActiveCollab. Проверьте права доступа и ответ API.');
        }

        $taskListId = $this->extractFirstId($taskLists);

        if ($taskListId !== null) {
            return $taskListId;
        }

        throw new \InvalidArgumentException('Не удалось определить Task List ID автоматически. Укажите его вручную.');
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

    private function normalizeUrl(string $value): string
    {
        $value = rtrim(trim($value), '/');

        if ($value === '') {
            throw new \InvalidArgumentException('Укажите корректный URL ActiveCollab.');
        }

        if (! preg_match('#^https?://#', $value)) {
            throw new \InvalidArgumentException('URL ActiveCollab должен начинаться с http:// или https://.');
        }

        return $value;
    }

    /** @param array<string, mixed> $payload */
    private function extractFirstId(array $payload): ?int
    {
        if (isset($payload['id']) && is_numeric($payload['id'])) {
            return (int) $payload['id'];
        }

        foreach ($payload as $value) {
            if (is_array($value)) {
                $id = $this->extractFirstId($value);

                if ($id !== null) {
                    return $id;
                }
            }
        }

        return null;
    }

    /** @param array<string, mixed> $payload */
    private function findTaskListIdByName(array $payload, string $name): ?int
    {
        $normalizedName = mb_strtolower(trim($name));

        foreach ($payload as $value) {
            if (! is_array($value)) {
                continue;
            }

            if (
                isset($value['name'], $value['id']) &&
                is_numeric($value['id']) &&
                mb_strtolower(trim((string) $value['name'])) === $normalizedName
            ) {
                return (int) $value['id'];
            }

            $nestedId = $this->findTaskListIdByName($value, $name);

            if ($nestedId !== null) {
                return $nestedId;
            }
        }

        return null;
    }
}
