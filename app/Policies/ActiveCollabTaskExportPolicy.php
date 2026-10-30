<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use Illuminate\Auth\Access\Response;

class ActiveCollabTaskExportPolicy
{
    public function recover(User $user): Response
    {
        return $user->hasAccess('platform.activecollab.recovery', false)
            && $user->hasAccess('platform.systems.users', false)
            && $user->hasAccess('platform.systems.roles', false)
                ? Response::allow() : Response::denyAsNotFound();
    }
}
