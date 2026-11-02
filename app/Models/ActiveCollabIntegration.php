<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/** @property bool $allow_legacy_token */
class ActiveCollabIntegration extends Model
{
    use HasUuids;

    protected $fillable = [
        'name',
        'spreadsheet_id',
        'connection_id',
        'active_collab_base_url',
        'active_collab_project_id',
        'active_collab_task_list_id',
        'active_collab_api_token',
        'task_list_mapping',
        'assignee_mapping',
        'label_mapping',
        'active',
    ];

    protected $casts = [
        'active_collab_project_id' => 'integer',
        'active_collab_task_list_id' => 'integer',
        'active_collab_api_token' => 'encrypted',
        'task_list_mapping' => 'array',
        'assignee_mapping' => 'array',
        'label_mapping' => 'array',
        'active' => 'boolean',
        'uuid_only' => 'boolean',
        'allow_legacy_token' => 'boolean',
    ];

    /** @return BelongsTo<ActiveCollabConnection, $this> */
    public function activeCollabConnection(): BelongsTo
    {
        return $this->belongsTo(ActiveCollabConnection::class, 'connection_id');
    }

    /** @return BelongsToMany<User, $this> */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'active_collab_integration_user', 'integration_id', 'user_id')
            ->withTimestamps();
    }

    public function resolvedBaseUrl(): string
    {
        $baseUrl = $this->activeCollabConnection?->base_url;

        if (! is_string($baseUrl) || $baseUrl === '') {
            throw new \LogicException('ActiveCollab connection is not assigned.');
        }

        return rtrim(trim($baseUrl), '/');
    }

    public function resolvedInstanceUrl(): string
    {
        $value = preg_replace('#/api/v\d+$#', '', $this->resolvedBaseUrl()) ?: $this->resolvedBaseUrl();

        return rtrim($value, '/');
    }

    public function resolvedApiToken(): string
    {
        // The encrypted Eloquent cast may throw during decryption at runtime.
        try {
            $apiToken = $this->activeCollabConnection?->api_token;
        } catch (\Throwable $exception) { // @phpstan-ignore catch.neverThrown
            throw new \LogicException('ActiveCollab connection credentials are unavailable.', previous: $exception);
        }

        if (! is_string($apiToken) || trim($apiToken) === '') {
            throw new \LogicException('ActiveCollab connection credentials are unavailable.');
        }

        return trim($apiToken);
    }
}
