<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\ActiveCollabIntegration;
use App\Models\User;
use App\Repositories\AdminAuditLogRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Orchid\Platform\Models\Role;

class AdminAccessManagementService
{
    public function __construct(private readonly AdminAuditLogRepository $audit) {}

    /** @param list<int|string> $roleIds
     * @param  array<string, mixed>  $permissions
     * @param  list<string>  $integrationIds
     */
    public function syncUserAccess(User $user, array $roleIds, array $permissions, array $integrationIds, int $actorId): void
    {
        DB::transaction(function () use ($user, $roleIds, $permissions, $integrationIds, $actorId): void {
            $lockedUser = User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            $integrationIds = array_values(array_unique($integrationIds));
            if ($integrationIds !== [] && ActiveCollabIntegration::query()->whereIn('id', $integrationIds)->count() !== count($integrationIds)) {
                throw ValidationException::withMessages(['integration_access' => 'Выберите существующие интеграции.']);
            }

            $before = $this->userAccessSnapshot($lockedUser);
            $lockedUser->forceFill(['permissions' => $permissions])->save();
            $lockedUser->replaceRoles($roleIds);
            $lockedUser->activeCollabIntegrations()->sync($integrationIds);
            $lockedUser->load('roles');
            $after = $this->userAccessSnapshot($lockedUser);

            if ($before !== $after) {
                $this->audit->recordMutation($actorId, 'user', (string) $lockedUser->id,
                    'user_access_updated', $before, $after);
            }
        }, 3);
    }

    /** @param array<string, mixed> $before
     * @param  array<string, mixed>  $after
     */
    public function auditRoleMutation(int $actorId, Role $role, array $before, array $after, string $action): void
    {
        $this->audit->recordMutation($actorId, 'role', (string) $role->id, $action, $before, $after);
    }

    /** @param array<string, mixed> $before */
    public function auditUserDeletion(int $actorId, User $user, array $before): void
    {
        $this->audit->recordMutation($actorId, 'user', (string) $user->id, 'user_deleted', $before, []);
    }

    /** @return array<string, mixed> */
    public function userAccessSnapshot(User $user): array
    {
        $user->loadMissing('roles');
        $permissions = (array) $user->permissions;
        $permissionSlugs = array_keys(array_filter($permissions, static fn ($value): bool => (bool) $value));
        sort($permissionSlugs);
        $roles = $user->roles->pluck('slug')->filter()->sort()->values()->all();
        $integrations = $user->activeCollabIntegrations()->orderBy('id')->pluck('active_collab_integrations.id')->all();

        return [
            'user_id' => $user->id,
            'role_ids' => $roles,
            'permission_slugs' => $permissionSlugs,
            'integration_ids' => $integrations,
        ];
    }
}
