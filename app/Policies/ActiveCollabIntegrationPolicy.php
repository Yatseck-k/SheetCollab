<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\ActiveCollabIntegration;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class ActiveCollabIntegrationPolicy
{
    public function viewAny(User $user): Response
    {
        return $this->isAdmin($user)
            || $user->hasAccess('platform.activecollab.integration.manage', false)
            || $user->hasAccess('platform.activecollab.integration.view', false)
                ? Response::allow() : Response::denyAsNotFound();
    }

    public function view(User $user, ActiveCollabIntegration $integration): Response
    {
        return $this->isAdmin($user) || ($this->hasMembership($user, $integration)
            && ($user->hasAccess('platform.activecollab.integration.manage', false)
                || $user->hasAccess('platform.activecollab.integration.view', false)))
            ? Response::allow() : Response::denyAsNotFound();
    }

    public function create(User $user): bool
    {
        return $this->isAdmin($user);
    }

    public function update(User $user, ActiveCollabIntegration $integration): Response
    {
        return $this->isAdmin($user) || ($this->hasMembership($user, $integration)
            && $user->hasAccess('platform.activecollab.integration.manage', false))
            ? Response::allow() : Response::denyAsNotFound();
    }

    public function manageSettings(User $user, ActiveCollabIntegration $integration): Response
    {
        return $this->isAdmin($user) ? Response::allow() : Response::denyAsNotFound();
    }

    public function delete(User $user, ActiveCollabIntegration $integration): Response
    {
        return $this->isAdmin($user) ? Response::allow() : Response::denyAsNotFound();
    }

    public function manageConnection(User $user): Response
    {
        return $this->isAdmin($user) ? Response::allow() : Response::denyAsNotFound();
    }

    public function assign(User $user): bool
    {
        return $this->isAdmin($user);
    }

    private function hasMembership(User $user, ActiveCollabIntegration $integration): bool
    {
        return $integration->users()->whereKey($user->getKey())->exists();
    }

    private function isAdmin(User $user): bool
    {
        return $user->hasAccess('platform.activecollab.admin', false)
            || ($user->hasAccess('platform.systems.settings', false)
                && $user->hasAccess('platform.systems.users', false)
                && $user->hasAccess('platform.systems.roles', false));
    }
}
