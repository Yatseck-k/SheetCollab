<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ActiveCollabTaskExport extends Model
{
    use HasUuids;

    protected $fillable = [
        'integration_id',
        'external_id',
        'spreadsheet_id',
        'sheet_id',
        'row_number',
        'project_id',
        'task_id',
        'task_url',
        'source_payload',
        'active_collab_payload',
        'active_collab_response',
        'sync_hash',
        'status',
        'error_message',
    ];

    protected $casts = [
        'source_payload' => 'array',
        'active_collab_payload' => 'array',
        'active_collab_response' => 'array',
        'row_number' => 'integer',
        'project_id' => 'integer',
        'task_id' => 'integer',
    ];

    /** @return BelongsTo<ActiveCollabIntegration, $this> */
    public function integration(): BelongsTo
    {
        return $this->belongsTo(ActiveCollabIntegration::class);
    }
}
