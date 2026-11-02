<?php

declare(strict_types=1);

namespace App\Services\ActiveCollab;

use App\Models\ActiveCollabConnection;
use App\Repositories\ActiveCollab\ActiveCollabConnectionRepository;
use Illuminate\Validation\ValidationException;

class ActiveCollabConnectionService
{
    public function __construct(private readonly ActiveCollabConnectionRepository $connections) {}

    /** @param array<string, mixed> $data */
    public function store(array $data, ?string $connectionId = null, ?int $actorId = null): ActiveCollabConnection
    {
        $baseUrl = $this->normalizeBaseUrl((string) ($data['base_url'] ?? ''));
        $name = trim((string) ($data['name'] ?? ''));
        $token = trim((string) ($data['api_token'] ?? ''));

        if ($name === '' || mb_strlen($name) > 255) {
            throw ValidationException::withMessages(['connection.name' => 'Укажите название подключения длиной до 255 символов.']);
        }

        if ($connectionId === null && $token === '') {
            throw ValidationException::withMessages(['connection.api_token' => 'Для нового подключения нужен API token.']);
        }

        $attributes = ['name' => $name, 'base_url' => $baseUrl, 'active' => (bool) ($data['active'] ?? false)];
        if ($token !== '') {
            $attributes['api_token'] = $token;
        }

        if ($connectionId === null) {
            /** @var array{name: string, base_url: string, api_token: string, active: bool} $attributes */
            return $this->connections->create($attributes, $actorId);
        }

        /** @var array{name: string, base_url: string, api_token?: string, active: bool} $attributes */
        return $this->connections->update($connectionId, $attributes, $actorId);
    }

    public function normalizeBaseUrl(string $value): string
    {
        $value = trim($value);
        $parts = parse_url($value);
        if ($parts === false || ! in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true)
            || empty($parts['host']) || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
            throw ValidationException::withMessages(['connection.base_url' => 'Укажите корректный URL ActiveCollab без учетных данных, query и fragment.']);
        }

        $scheme = strtolower((string) $parts['scheme']);
        $host = strtolower((string) $parts['host']);
        $port = isset($parts['port']) ? ':'.$parts['port'] : '';
        $path = rtrim((string) ($parts['path'] ?? ''), '/');
        $path = preg_replace('#/projects/\d+.*$#', '', $path) ?? $path;
        if (preg_match('#/api/v\d+$#', $path) !== 1) {
            $path = preg_replace('#/api/v\d+.*$#', '', $path) ?? $path;
            $path .= '/api/v1';
        }

        return $scheme.'://'.$host.$port.$path;
    }
}
