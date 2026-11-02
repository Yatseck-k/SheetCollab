<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\ActiveCollabIntegration;
use App\Models\ActiveCollabTaskExport;
use App\Policies\ActiveCollabIntegrationPolicy;
use App\Policies\ActiveCollabTaskExportPolicy;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Gate::policy(ActiveCollabTaskExport::class, ActiveCollabTaskExportPolicy::class);
        Gate::policy(ActiveCollabIntegration::class, ActiveCollabIntegrationPolicy::class);
    }
}
