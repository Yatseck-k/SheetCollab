<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\ActiveCollabIntegration;
use App\Models\ActiveCollabTaskExport;
use App\Models\User;
use Illuminate\Auth\Access\Response;
use Illuminate\Support\Facades\Gate;

class ActiveCollabTaskExportPolicy
{
    public function viewAny(User $user): Response
    {
        return Gate::forUser($user)->allows('viewAny', ActiveCollabIntegration::class)
            ? Response::allow() : Response::denyAsNotFound();
    }

    public function view(User $user, ActiveCollabTaskExport $export): Response
    {
        if ($export->integration === null) {
            return $this->isAdmin($user) ? Response::allow() : Response::denyAsNotFound();
        }

        return Gate::forUser($user)->inspect('view', $export->integration);
    }

    public function recover(User $user): Response
    {
        return $user->hasAccess('platform.activecollab.recovery', false)
            && $user->hasAccess('platform.systems.users', false)
            && $user->hasAccess('platform.systems.roles', false)
                ? Response::allow() : Response::denyAsNotFound();
    }

    private function isAdmin(User $user): bool
    {
        return $user->hasAccess('platform.activecollab.admin', false)
            || ($user->hasAccess('platform.systems.settings', false)
                && $user->hasAccess('platform.systems.users', false)
                && $user->hasAccess('platform.systems.roles', false));
    }
}
