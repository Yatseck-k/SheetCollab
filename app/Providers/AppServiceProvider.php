<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\ActiveCollabTaskExport;
use App\Policies\ActiveCollabTaskExportPolicy;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    #[\Override]
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Gate::policy(ActiveCollabTaskExport::class, ActiveCollabTaskExportPolicy::class);
    }
}
