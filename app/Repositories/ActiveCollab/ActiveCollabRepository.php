<?php

declare(strict_types=1);

namespace App\Repositories\ActiveCollab;

use App\Models\ActiveCollabIntegration;
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
        return $this->requestWithIntegration(
            $integration, 'post',
            "/projects/{$integration->active_collab_project_id}/tasks",
            $payload,
        )->json();
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function updateTask(ActiveCollabIntegration $integration, int $taskId, array $payload): array
    {
        return $this->requestWithIntegration(
            $integration, 'put',
            "/projects/{$integration->active_collab_project_id}/tasks/{$taskId}",
            $payload,
        )->json();
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
            ->acceptJson();

        return $this->send($client, $method, rtrim($baseUrl, '/').$path, $payload);
    }

    /** @param array<string, mixed> $payload */
    private function send(PendingRequest $client, string $method, string $url, array $payload): Response
    {
        $response = match ($method) {
            'get' => $client->get($url),
            'post' => $client->post($url, $payload),
            'put' => $client->put($url, $payload),
            default => throw new \InvalidArgumentException("Unsupported HTTP method: {$method}"),
        };

        if ($response->failed()) {
            Log::error('ActiveCollab API error', [
                'method' => strtoupper($method),
                'url' => $url,
                'status' => $response->status(),
                'body' => mb_substr($response->body(), 0, 500),
            ]);
        }

        $response->throw();

        return $response;
    }
}
