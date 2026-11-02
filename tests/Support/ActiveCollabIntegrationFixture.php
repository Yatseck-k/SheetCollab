<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Models\ActiveCollabConnection;
use App\Models\ActiveCollabIntegration;
use Illuminate\Support\Str;

final class ActiveCollabIntegrationFixture
{
    /** @param array<string, mixed> $attributes */
    public static function create(array $attributes = []): ActiveCollabIntegration
    {
        config()->set('app.key', config('app.key') ?: 'base64:J63P4m4KjJ8l0RkGxJZ4Kf9P9+K6f2R4R6V4a2M1LxQ=');
        if (! isset($attributes['connection_id'])) {
            $baseUrl = (string) ($attributes['active_collab_base_url'] ?? config('services.active_collab.base_url', 'https://ac.example.test/api/v1'));
            $token = (string) ($attributes['active_collab_api_token'] ?? config('services.active_collab.api_token', 'test-api-token'));
            if ($token === '') {
                $token = 'test-api-token';
            }
            if (! str_contains($baseUrl, '/api/v')) {
                $baseUrl = rtrim($baseUrl, '/').'/api/v1';
            }
            $connection = ActiveCollabConnection::query()->create([
                'name' => 'Test connection', 'base_url' => $baseUrl, 'api_token' => $token, 'active' => true,
            ]);
            $attributes['connection_id'] = $connection->id;
        }

        return ActiveCollabIntegration::query()->create($attributes);
    }

    /** @param array<string, mixed> $attributes */
    public static function make(array $attributes = []): ActiveCollabIntegration
    {
        config()->set('app.key', config('app.key') ?: 'base64:J63P4m4KjJ8l0RkGxJZ4Kf9P9+K6f2R4R6V4a2M1LxQ=');
        $baseUrl = (string) ($attributes['active_collab_base_url'] ?? 'https://ac.example.test/api/v1');
        $token = (string) ($attributes['active_collab_api_token'] ?? 'test-api-token');
        if (! str_contains($baseUrl, '/api/v')) {
            $baseUrl = rtrim($baseUrl, '/').'/api/v1';
        }
        $connection = new ActiveCollabConnection([
            'name' => 'Test connection', 'base_url' => $baseUrl, 'api_token' => $token, 'active' => true,
        ]);
        $connection->setAttribute('id', (string) ($attributes['connection_id'] ?? Str::uuid()));
        $attributes['connection_id'] = $connection->id;
        $attributes += ['active' => true];
        $integration = new ActiveCollabIntegration($attributes);
        $integration->setAttribute('id', (string) ($attributes['id'] ?? Str::uuid()));
        $integration->setRelation('activeCollabConnection', $connection);

        return $integration;
    }
}
