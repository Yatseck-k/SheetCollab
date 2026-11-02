<?php

declare(strict_types=1);

namespace App\Orchid\Screens\User;

use App\Models\ActiveCollabIntegration;
use App\Models\User;
use App\Orchid\Layouts\Role\RolePermissionLayout;
use App\Orchid\Layouts\User\UserEditLayout;
use App\Orchid\Layouts\User\UserIntegrationAccessLayout;
use App\Orchid\Layouts\User\UserPasswordLayout;
use App\Orchid\Layouts\User\UserRoleLayout;
use App\Services\AdminAccessManagementService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Orchid\Access\Impersonation;
use Orchid\Screen\Action;
use Orchid\Screen\Actions\Button;
use Orchid\Screen\Screen;
use Orchid\Support\Color;
use Orchid\Support\Facades\Layout;
use Orchid\Support\Facades\Toast;

class UserEditScreen extends Screen
{
    /**
     * @var User
     */
    public $user;

    /**
     * Fetch data to be displayed on the screen.
     *
     * @return iterable<string, mixed>
     */
    public function query(User $user): iterable
    {
        Gate::authorize('assign', ActiveCollabIntegration::class);
        $user->load(['roles', 'activeCollabIntegrations']);

        return [
            'user' => $user,
            'permission' => $user->statusOfPermissions(),
            'integration_access' => $user->activeCollabIntegrations->pluck('id')->all(),
        ];
    }

    /**
     * The name of the screen displayed in the header.
     */
    #[\Override]
    public function name(): ?string
    {
        return $this->user->exists ? 'Edit User' : 'Create User';
    }

    /**
     * Display header description.
     */
    #[\Override]
    public function description(): ?string
    {
        return 'User profile and privileges, including their associated role.';
    }

    /**
     * The screen's action buttons.
     *
     * @return Action[]
     */
    #[\Override]
    public function commandBar(): iterable
    {
        return [
            Button::make(__('Impersonate user'))
                ->icon('bg.box-arrow-in-right')
                ->confirm(__('You can revert to your original state by logging out.'))
                ->method('loginAs')
                ->canSee($this->user->exists && $this->user->id !== \request()->user()->id),

            Button::make(__('Remove'))
                ->icon('bs.trash3')
                ->confirm(__('Once the account is deleted, all of its resources and data will be permanently deleted. Before deleting your account, please download any data or information that you wish to retain.'))
                ->method('remove')
                ->canSee($this->user->exists),

            Button::make(__('Save'))
                ->icon('bs.check-circle')
                ->method('save'),
        ];
    }

    /**
     * @return \Orchid\Screen\Layout[]
     */
    public function layout(): iterable
    {
        $isAdmin = Gate::allows('assign', ActiveCollabIntegration::class);

        return [

            Layout::block(UserEditLayout::class)
                ->title(__('Profile Information'))
                ->description(__('Update your account\'s profile information and email address.'))
                ->commands(
                    Button::make(__('Save'))
                        ->type(Color::BASIC)
                        ->icon('bs.check-circle')
                        ->canSee($this->user->exists)
                        ->method('save')
                ),

            Layout::block(UserPasswordLayout::class)
                ->title(__('Password'))
                ->description(__('Ensure your account is using a long, random password to stay secure.'))
                ->commands(
                    Button::make(__('Save'))
                        ->type(Color::BASIC)
                        ->icon('bs.check-circle')
                        ->canSee($this->user->exists)
                        ->method('save')
                ),

            Layout::block(UserRoleLayout::class)
                ->title(__('Roles'))
                ->description(__('A Role defines a set of tasks a user assigned the role is allowed to perform.'))
                ->commands(
                    Button::make(__('Save'))
                        ->type(Color::BASIC)
                        ->icon('bs.check-circle')
                        ->canSee($this->user->exists)
                        ->method('save')
                ),

            Layout::block(RolePermissionLayout::class)
                ->title(__('Permissions'))
                ->description(__('Allow the user to perform some actions that are not provided for by his roles'))
                ->commands(
                    Button::make(__('Save'))
                        ->type(Color::BASIC)
                        ->icon('bs.check-circle')
                        ->canSee($this->user->exists)
                        ->method('save')
                ),

            Layout::block(UserIntegrationAccessLayout::class)
                ->title('Назначенные интеграции')
                ->description('Доступ admin к выбранным интеграциям для пользователей с разрешением manager или viewer.')
                ->commands(Button::make('Сохранить')->type(Color::BASIC)->icon('bs.check-circle')->method('save'))
                ->canSee($isAdmin),

        ];
    }

    /**
     * @return RedirectResponse
     */
    public function save(User $user, Request $request, AdminAccessManagementService $access)
    {
        Gate::authorize('assign', ActiveCollabIntegration::class);
        $request->validate([
            'user.email' => [
                'required',
                Rule::unique(User::class, 'email')->ignore($user),
            ],
        ]);

        $request->validate([
            'user.roles' => ['nullable', 'array'],
            'user.roles.*' => ['integer', 'distinct', 'exists:roles,id'],
            'integration_access' => ['nullable', 'array'],
            'integration_access.*' => ['uuid', 'distinct', 'exists:active_collab_integrations,id'],
        ]);

        $permissions = collect((array) $request->get('permissions'))
            ->map(fn ($value, $key): array => [base64_decode((string) $key) => $value])
            ->collapse()
            ->toArray();

        DB::transaction(function () use ($user, $request, $permissions, $access): void {
            $user->when($request->filled('user.password'), function (Builder $builder) use ($request): void {
                $builder->getModel()->password = Hash::make($request->input('user.password'));
            });

            $user->fill($request->collect('user')->except(['password', 'permissions', 'roles'])->toArray())->save();
            $access->syncUserAccess(
                $user,
                array_values((array) $request->input('user.roles', [])),
                $permissions,
                array_values((array) $request->input('integration_access', $user->activeCollabIntegrations()->pluck('id')->all())),
                (int) $request->user()->getAuthIdentifier(),
            );
        }, 3);

        Toast::info(__('User was saved.'));

        return redirect()->route('platform.systems.users');
    }

    /**
     * @return RedirectResponse
     *
     * @throws \Exception
     */
    public function remove(User $user, AdminAccessManagementService $access)
    {
        Gate::authorize('assign', ActiveCollabIntegration::class);
        DB::transaction(function () use ($user, $access): void {
            $before = $access->userAccessSnapshot($user);
            $access->auditUserDeletion((int) request()->user()->getAuthIdentifier(), $user, $before);
            $user->delete();
        }, 3);

        Toast::info(__('User was removed'));

        return redirect()->route('platform.systems.users');
    }

    /**
     * @return RedirectResponse
     */
    public function loginAs(User $user)
    {
        Gate::authorize('assign', ActiveCollabIntegration::class);
        Impersonation::loginAs($user);

        Toast::info(__('You are now impersonating this user'));

        return redirect()->route(config('platform.index'));
    }
}
