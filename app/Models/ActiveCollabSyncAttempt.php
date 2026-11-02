<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ActiveCollabSyncAttempt extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $fillable = [
        'export_id', 'integration_id', 'operation_id', 'action', 'status',
        'started_at', 'finished_at', 'http_status', 'error_code', 'error_message',
        'source_payload', 'active_collab_payload', 'active_collab_response', 'sync_hash', 'hash_version',
    ];

    protected $casts = [
        'started_at' => 'immutable_datetime', 'finished_at' => 'immutable_datetime',
        'source_payload' => 'array', 'active_collab_payload' => 'array',
        'active_collab_response' => 'array', 'http_status' => 'integer',
    ];

    /** @return BelongsTo<ActiveCollabTaskExport, $this> */
    public function export(): BelongsTo
    {
        return $this->belongsTo(ActiveCollabTaskExport::class, 'export_id');
    }
}
