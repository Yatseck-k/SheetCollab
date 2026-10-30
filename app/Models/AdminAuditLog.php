<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class AdminAuditLog extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $fillable = [
        'actor_id', 'subject_type', 'subject_id', 'integration_id', 'connection_id',
        'action', 'request_id', 'operation_id', 'phase', 'before', 'after', 'context', 'created_at',
    ];

    protected $casts = ['actor_id' => 'integer', 'before' => 'array', 'after' => 'array', 'context' => 'array', 'created_at' => 'immutable_datetime'];
}
