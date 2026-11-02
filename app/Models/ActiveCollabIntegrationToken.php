<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class ActiveCollabIntegrationToken extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $fillable = ['integration_id', 'name', 'token_hash', 'created_at', 'revoked_at', 'last_used_at'];

    protected $hidden = ['token_hash'];

    protected $casts = ['created_at' => 'immutable_datetime', 'revoked_at' => 'immutable_datetime', 'last_used_at' => 'immutable_datetime'];
}
