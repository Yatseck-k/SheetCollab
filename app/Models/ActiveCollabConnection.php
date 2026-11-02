<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ActiveCollabConnection extends Model
{
    use HasUuids;

    protected $fillable = ['name', 'base_url', 'api_token', 'active'];

    protected $hidden = ['api_token'];

    protected $casts = [
        'api_token' => 'encrypted',
        'active' => 'boolean',
    ];

    /** @return HasMany<ActiveCollabIntegration, $this> */
    public function integrations(): HasMany
    {
        return $this->hasMany(ActiveCollabIntegration::class, 'connection_id');
    }
}
