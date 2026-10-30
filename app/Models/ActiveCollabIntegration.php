<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class ActiveCollabIntegration extends Model
{
    use HasUuids;

    protected $fillable = [
        'name',
        'spreadsheet_id',
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
    ];

    public function resolvedBaseUrl(): string
    {
        return rtrim(
            trim((string) ($this->active_collab_base_url ?: config('services.active_collab.base_url'))),
            '/',
        );
    }

    public function resolvedInstanceUrl(): string
    {
        $value = preg_replace('#/api/v\d+$#', '', $this->resolvedBaseUrl()) ?: $this->resolvedBaseUrl();

        return rtrim($value, '/');
    }

    public function resolvedApiToken(): string
    {
        return trim((string) ($this->active_collab_api_token ?: config('services.active_collab.api_token')));
    }
}
