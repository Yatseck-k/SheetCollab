<?php

declare(strict_types=1);

namespace App\Repositories\ActiveCollab;

use App\Exceptions\ActiveCollabRequestException;
use App\Models\ActiveCollabConnection;
use App\Models\ActiveCollabIntegration;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ActiveCollabRepository
{
    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function createTask(ActiveCollabIntegration $integration, array $payload): array
    {
        return $this->taskResponse($this->requestWithIntegration(
            $integration, 'post',
            "/projects/{$integration->active_collab_project_id}/tasks",
            $payload,
        ));
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function updateTask(ActiveCollabIntegration $integration, int $taskId, array $payload): array
    {
        return $this->taskResponse($this->requestWithIntegration(
            $integration, 'put',
            "/projects/{$integration->active_collab_project_id}/tasks/{$taskId}",
            $payload,
        ), $taskId);
    }

    /** @return array{single: array{id: int, url_path: string, project_id: int, is_trashed: bool}} */
    public function getTask(ActiveCollabIntegration $integration, int $taskId): array
    {
        $response = $this->requestWithIntegration($integration, 'get',
            "/projects/{$integration->active_collab_project_id}/tasks/{$taskId}");
        $binding = $this->taskResponse($response, $taskId);
        $projectId = $response->json('single.project_id');
        $trashed = $response->json('single.is_trashed');
        if ($projectId !== $integration->active_collab_project_id || ! is_bool($trashed)) {
            throw new ActiveCollabRequestException($response->status());
        }

        return ['single' => $binding['single'] + ['project_id' => $projectId, 'is_trashed' => $trashed]];
    }

    /** @param array<string, mixed> $payload
     * @param  list<int>  $labelIds
     * @return array<string, mixed>
     */
    public function syncTask(ActiveCollabIntegration $integration, ?int $taskId, array $payload, array $labelIds): array
    {
        $path = "/projects/{$integration->active_collab_project_id}/tasks".($taskId !== null ? '/'.$taskId : '');
        $response = $this->requestWithIntegration($integration, $taskId === null ? 'post' : 'put', $path, $payload);
        $safe = $this->taskResponse($response, $taskId);
        $actual = $response->json('single.labels');
        $ids = [];
        if (is_array($actual) && array_is_list($actual)) {
            foreach ($actual as $label) {
                if (! is_array($label) || ! is_int($label['id'] ?? null) || $label['id'] < 1) {
                    throw new ActiveCollabRequestException($response->status(), safeResponse: $safe);
                }
                $ids[] = $label['id'];
            }
        } else {
            throw new ActiveCollabRequestException($response->status(), safeResponse: $safe);
        }
        sort($ids);
        sort($labelIds);
        if ($response->json('single.project_id') !== $integration->active_collab_project_id || $response->json('single.is_trashed') !== false
            || $response->json('single.assignee_id') !== ($payload['assignee_id'] ?? 0) || $ids !== $labelIds) {
            throw new ActiveCollabRequestException($response->status(), safeResponse: $safe);
        }

        return $safe;
    }

    /** @return list<array<string, mixed>> */
    public function labelsForIntegration(ActiveCollabIntegration $integration): array
    {
        $labels = $this->catalog($this->requestWithIntegration($integration, 'get', '/labels/task-labels')->json());
        $allowed = null;
        $result = [];
        foreach ($labels as $label) {
            if (! is_bool($label['is_global'] ?? null)) {
                throw new ActiveCollabRequestException();
            }
            if (! $label['is_global']) {
                $allowed ??= $this->project($integration)['label_ids'];
                if (! in_array($label['id'], $allowed, true)) {
                    continue;
                }
            }
            $result[] = array_intersect_key($label, array_flip(['id', 'name', 'color', 'is_global']));
        }

        return $result;
    }

    /** @return list<array<string, mixed>> */
    public function usersForIntegration(ActiveCollabIntegration $integration): array
    {
        $members = $this->project($integration)['members'];
        $users = $this->catalog($this->requestWithIntegration($integration, 'get', '/users')->json(), 'display_name');
        $result = [];
        foreach ($users as $user) {
            if (in_array($user['id'], $members, true)) {
                $result[] = array_intersect_key($user, array_flip(['id', 'display_name']));
            }
        }

        return $result;
    }

    /** @return list<array<string, mixed>> */
    public function taskListsForIntegration(ActiveCollabIntegration $integration): array
    {
        return $this->taskListsForConnection($this->connectionForIntegration($integration), $integration->active_collab_project_id);
    }

    /** @return list<array{id: int, name: string}> */
    public function projectsForConnection(ActiveCollabConnection $connection): array
    {
        $data = $this->requestWithConnection($connection, 'get', '/projects')->json();
        if (! is_array($data) || ! array_is_list($data)) {
            throw new ActiveCollabRequestException();
        }

        $projects = [];
        $ids = [];
        foreach ($data as $project) {
            if (! is_array($project) || ! is_int($project['id'] ?? null) || $project['id'] < 1
                || ! is_string($project['name'] ?? null) || ($project['is_trashed'] ?? null) !== false
                || isset($ids[$project['id']])) {
                throw new ActiveCollabRequestException();
            }
            $ids[$project['id']] = true;
            $projects[] = ['id' => $project['id'], 'name' => $project['name']];
        }

        return $projects;
    }

    /** @return list<array{id: int, name: string}> */
    public function taskListsForConnection(ActiveCollabConnection $connection, int $projectId): array
    {
        $catalog = $this->catalog($this->requestWithConnection($connection, 'get', "/projects/{$projectId}/task-lists")->json());

        return array_map(static fn (array $item): array => ['id' => $item['id'], 'name' => $item['name']], $catalog);
    }

    /** @return array{members: list<int>, label_ids: list<int>} */
    private function project(ActiveCollabIntegration $integration): array
    {
        $data = $this->requestWithIntegration($integration, 'get', "/projects/{$integration->active_collab_project_id}")->json();
        if (! is_array($data) || ($data['single']['id'] ?? null) !== $integration->active_collab_project_id
            || ($data['single']['is_trashed'] ?? null) !== false) {
            throw new ActiveCollabRequestException();
        }
        $members = $data['single']['members'] ?? null;
        $labels = $data['label_ids'] ?? null;
        foreach ([$members, $labels] as $ids) {
            if (! is_array($ids) || ! array_is_list($ids)) {
                throw new ActiveCollabRequestException();
            }
            foreach ($ids as $id) {
                if (! is_int($id) || $id < 1) {
                    throw new ActiveCollabRequestException();
                }
            }
        }

        return ['members' => $members, 'label_ids' => $labels];
    }

    /** @return list<array<string, mixed>> */
    private function catalog(mixed $data, string $nameKey = 'name'): array
    {
        if (! is_array($data) || ! array_is_list($data)) {
            throw new ActiveCollabRequestException();
        }
        $ids = [];
        foreach ($data as $item) {
            if (! is_array($item) || ! is_int($item['id'] ?? null) || $item['id'] < 1 || ! is_string($item[$nameKey] ?? null)
                || isset($ids[$item['id']])) {
                throw new ActiveCollabRequestException();
            }
            $ids[$item['id']] = true;
        }

        return $data;
    }

    /** @param array<string, mixed> $payload */
    private function requestWithIntegration(
        ActiveCollabIntegration $integration,
        string $method,
        string $path,
        array $payload = [],
    ): Response {
        if (! $integration->active) {
            throw new ActiveCollabRequestException();
        }

        return $this->requestWithConnection($this->connectionForIntegration($integration), $method, $path, $payload);
    }

    /** @param array<string, mixed> $payload */
    private function requestWithConnection(ActiveCollabConnection $connection, string $method, string $path, array $payload = []): Response
    {
        try {
            $apiToken = trim((string) $connection->api_token);
        } catch (\Throwable) {
            throw new ActiveCollabRequestException();
        }
        if (! $connection->active || $apiToken === '' || $connection->base_url === '') {
            throw new ActiveCollabRequestException();
        }

        return $this->requestForConnection($method, $connection->base_url, $apiToken, $path, $payload);
    }

    private function connectionForIntegration(ActiveCollabIntegration $integration): ActiveCollabConnection
    {
        $connection = $integration->activeCollabConnection;
        if (! $connection instanceof ActiveCollabConnection || ! $connection->active) {
            throw new ActiveCollabRequestException();
        }

        return $connection;
    }

    /** @param array<string, mixed> $payload */
    private function requestForConnection(
        string $method,
        string $baseUrl,
        string $apiToken,
        string $path,
        array $payload = [],
    ): Response {
        $client = Http::withHeader('X-Angie-AuthApiToken', $apiToken)
            ->acceptJson()
            ->connectTimeout(max(1, (int) config('services.active_collab.connect_timeout', 5)))
            ->timeout(max(1, (int) config('services.active_collab.timeout', 30)));

        return $this->send($client, $method, rtrim($baseUrl, '/').$path, $payload);
    }

    /** @return array{single: array{id: int, url_path: string}} */
    private function taskResponse(Response $response, ?int $expectedId = null): array
    {
        $single = $response->json('single');
        if (! is_array($single) || ! is_int($single['id'] ?? null) || $single['id'] < 1
            || ! is_string($single['url_path'] ?? null) || ! str_starts_with($single['url_path'], '/')
            || str_starts_with($single['url_path'], '//') || str_contains($single['url_path'], '\\')
            || preg_match('/[\x00-\x20\x7f]/', $single['url_path'])
            || ($expectedId !== null && $single['id'] !== $expectedId)) {
            throw new ActiveCollabRequestException($response->status());
        }

        return ['single' => ['id' => $single['id'], 'url_path' => $single['url_path']]];
    }

    /** @param array<string, mixed> $payload */
    private function send(PendingRequest $client, string $method, string $url, array $payload): Response
    {
        try {
            $response = match ($method) {
                'get' => $client->get($url),
                'post' => $client->post($url, $payload),
                'put' => $client->put($url, $payload),
                default => throw new \InvalidArgumentException('Unsupported HTTP method.'),
            };
        } catch (ConnectionException $e) {
            throw new ActiveCollabRequestException(timeout: str_contains($e->getMessage(), 'cURL error 28'), category: 'transport_uncertain');
        }
        if ($response->failed()) {
            Log::warning('ActiveCollab API request failed', ['method' => strtoupper($method), 'status' => $response->status()]);
            throw new ActiveCollabRequestException($response->status(), category: $response->clientError() ? 'upstream_rejected' : 'upstream_uncertain');
        }

        return $response;
    }
}
