<?php

declare(strict_types=1);

namespace App\Repositories\ActiveCollab;

use App\Exceptions\ActiveCollabRequestException;
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

    /** @return array<string, mixed> */
    public function getTaskLists(): array
    {
        $projectId = config('services.active_collab.project_id');

        return $this->getTaskListsForConnection(
            (string) config('services.active_collab.base_url'),
            (string) config('services.active_collab.api_token'),
            (int) $projectId,
        );
    }

    /** @return array<string, mixed> */
    public function getTaskListsForConnection(string $baseUrl, string $apiToken, int $projectId): array
    {
        return $this->requestForConnection('get', $baseUrl, $apiToken, "/projects/{$projectId}/task-lists")->json();
    }

    /** @return array<string, mixed> */
    public function createTaskListForConnection(
        string $baseUrl,
        string $apiToken,
        int $projectId,
        string $name,
    ): array {
        return $this->requestForConnection('post', $baseUrl, $apiToken, "/projects/{$projectId}/task-lists", [
            'name' => $name,
        ])->json();
    }

    /** @return array<string, mixed> */
    public function getUsers(): array
    {
        return $this->request('get', '/users')->json();
    }

    /** @return array<string, mixed> */
    public function getLabels(): array
    {
        return $this->request('get', '/labels')->json();
    }

    /** @param array<string, mixed> $payload */
    private function requestWithIntegration(
        ActiveCollabIntegration $integration,
        string $method,
        string $path,
        array $payload = [],
    ): Response {
        return $this->requestForConnection(
            $method,
            $integration->resolvedBaseUrl(),
            $integration->resolvedApiToken(),
            $path,
            $payload,
        );
    }

    /** @param array<string, mixed> $payload */
    private function request(string $method, string $path, array $payload = []): Response
    {
        return $this->requestForConnection(
            $method,
            (string) config('services.active_collab.base_url'),
            (string) config('services.active_collab.api_token'),
            $path,
            $payload,
        );
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
