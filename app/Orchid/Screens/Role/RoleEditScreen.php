<?php

declare(strict_types=1);

namespace App\Orchid\Screens\Role;

use App\Models\ActiveCollabIntegration;
use App\Orchid\Layouts\Role\RoleEditLayout;
use App\Orchid\Layouts\Role\RolePermissionLayout;
use App\Services\AdminAccessManagementService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Orchid\Platform\Models\Role;
use Orchid\Screen\Action;
use Orchid\Screen\Actions\Button;
use Orchid\Screen\Screen;
use Orchid\Support\Facades\Layout;
use Orchid\Support\Facades\Toast;

class RoleEditScreen extends Screen
{
    /**
     * @var Role
     */
    public $role;

    /**
     * Fetch data to be displayed on the screen.
     *
     * @return iterable<string, mixed>
     */
    public function query(Role $role): iterable
    {
        Gate::authorize('assign', ActiveCollabIntegration::class);

        return [
            'role' => $role,
            'permission' => $role->statusOfPermissions(),
        ];
    }

    /**
     * The name of the screen displayed in the header.
     */
    #[\Override]
    public function name(): ?string
    {
        return 'Edit Role';
    }

    /**
     * Display header description.
     */
    #[\Override]
    public function description(): ?string
    {
        return 'Modify the privileges and permissions associated with a specific role.';
    }

    /**
     * The permissions required to access this screen.
     */
    /**
     * The screen's action buttons.
     *
     * @return Action[]
     */
    #[\Override]
    public function commandBar(): iterable
    {
        return [
            Button::make(__('Save'))
                ->icon('bs.check-circle')
                ->method('save'),

            Button::make(__('Remove'))
                ->icon('bs.trash3')
                ->method('remove')
                ->canSee($this->role->exists),
        ];
    }

    /**
     * The screen's layout elements.
     *
     * @return string[]|\Orchid\Screen\Layout[]
     */
    public function layout(): iterable
    {
        return [
            Layout::block([
                RoleEditLayout::class,
            ])
                ->title('Role')
                ->description('Defines a set of privileges that grant users access to various services and allow them to perform specific tasks or operations.'),

            Layout::block([
                RolePermissionLayout::class,
            ])
                ->title('Permission/Privilege')
                ->description('A privilege is necessary to perform certain tasks and operations in an area.'),
        ];
    }

    /**
     * @return RedirectResponse
     */
    public function save(Request $request, Role $role, AdminAccessManagementService $access)
    {
        Gate::authorize('assign', ActiveCollabIntegration::class);
        $request->validate([
            'role.name' => 'required',
            'role.slug' => [
                'required',
                Rule::unique(Role::class, 'slug')->ignore($role),
            ],
        ]);

        $before = $this->snapshot($role);
        $permissions = collect((array) $request->get('permissions'))
            ->map(fn ($value, $key): array => [base64_decode((string) $key) => $value])
            ->collapse()
            ->toArray();
        DB::transaction(function () use ($request, $role, $permissions, $access, $before): void {
            $role->fill($request->get('role'));
            $role->permissions = $permissions;
            $role->save();
            $after = $this->snapshot($role);
            if ($role->wasRecentlyCreated || $before !== $after) {
                $access->auditRoleMutation((int) $request->user()->getAuthIdentifier(), $role, $before,
                    $after, $role->wasRecentlyCreated ? 'role_created' : 'role_updated');
            }
        }, 3);

        Toast::info(__('Role was saved'));

        return redirect()->route('platform.systems.roles');
    }

    /**
     * @return RedirectResponse
     *
     * @throws \Exception
     */
    public function remove(Role $role, AdminAccessManagementService $access)
    {
        Gate::authorize('assign', ActiveCollabIntegration::class);
        DB::transaction(function () use ($role, $access): void {
            $before = $this->snapshot($role);
            $access->auditRoleMutation((int) request()->user()->getAuthIdentifier(), $role, $before, [], 'role_deleted');
            $role->delete();
        }, 3);

        Toast::info(__('Role was removed'));

        return redirect()->route('platform.systems.roles');
    }

    /** @return array<string, mixed> */
    private function snapshot(Role $role): array
    {
        $permissions = array_keys(array_filter((array) $role->permissions, static fn ($value): bool => (bool) $value));
        sort($permissions);

        return ['role_id' => $role->id, 'name' => $role->name, 'slug' => $role->slug, 'permission_slugs' => $permissions];
    }
}
