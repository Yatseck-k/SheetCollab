<?php

declare(strict_types=1);

use App\Orchid\Screens\Export\ExportListScreen;
use App\Orchid\Screens\Export\ExportRecoveryScreen;
use App\Orchid\Screens\Guide\GuideScreen;
use App\Orchid\Screens\Integration\IntegrationEditScreen;
use App\Orchid\Screens\Integration\IntegrationListScreen;
use App\Orchid\Screens\PlatformScreen;
use App\Orchid\Screens\Role\RoleEditScreen;
use App\Orchid\Screens\Role\RoleListScreen;
use App\Orchid\Screens\Script\ScriptEditorScreen;
use App\Orchid\Screens\User\UserEditScreen;
use App\Orchid\Screens\User\UserListScreen;
use App\Orchid\Screens\User\UserProfileScreen;
use App\Services\GoogleAppsScriptService;
use Illuminate\Support\Facades\Route;
use Tabuna\Breadcrumbs\Trail;

/*
|--------------------------------------------------------------------------
| Dashboard Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| contains the need "dashboard" middleware group. Now create something great!
|
*/

// Integrations
Route::screen('integrations/create', IntegrationEditScreen::class)
    ->name('platform.integrations.create')
    ->breadcrumbs(fn (Trail $trail) => $trail
        ->parent('platform.integrations')
        ->push('Новая интеграция'));

Route::screen('integrations/{integration}/edit', IntegrationEditScreen::class)
    ->name('platform.integrations.edit')
    ->breadcrumbs(fn (Trail $trail, $integration) => $trail
        ->parent('platform.integrations')
        ->push($integration->name));

Route::screen('integrations', IntegrationListScreen::class)
    ->name('platform.integrations')
    ->breadcrumbs(fn (Trail $trail) => $trail
        ->parent('platform.index')
        ->push('Интеграции'));

// Exports
Route::screen('exports/{export}/recovery', ExportRecoveryScreen::class)->name('platform.exports.recovery');

Route::screen('exports', ExportListScreen::class)
    ->name('platform.exports')
    ->breadcrumbs(fn (Trail $trail) => $trail
        ->parent('platform.index')
        ->push('История экспортов'));

// Guide
Route::screen('guide', GuideScreen::class)
    ->name('platform.guide')
    ->breadcrumbs(fn (Trail $trail) => $trail
        ->parent('platform.index')
        ->push('Инструкция'));

// Apps Script
Route::screen('script', ScriptEditorScreen::class)
    ->name('platform.script')
    ->breadcrumbs(fn (Trail $trail) => $trail
        ->parent('platform.index')
        ->push('Apps Script'));

Route::get('script/download', fn (GoogleAppsScriptService $scriptService) => $scriptService->download())
    ->name('platform.script.download');

// Main
Route::screen('/main', PlatformScreen::class)
    ->name('platform.main');

// Platform > Profile
Route::screen('profile', UserProfileScreen::class)
    ->name('platform.profile')
    ->breadcrumbs(fn (Trail $trail) => $trail
        ->parent('platform.index')
        ->push(__('Profile'), route('platform.profile')));

// Platform > System > Users > User
Route::screen('users/{user}/edit', UserEditScreen::class)
    ->name('platform.systems.users.edit')
    ->breadcrumbs(fn (Trail $trail, $user) => $trail
        ->parent('platform.systems.users')
        ->push($user->name, route('platform.systems.users.edit', $user)));

// Platform > System > Users > Create
Route::screen('users/create', UserEditScreen::class)
    ->name('platform.systems.users.create')
    ->breadcrumbs(fn (Trail $trail) => $trail
        ->parent('platform.systems.users')
        ->push(__('Create'), route('platform.systems.users.create')));

// Platform > System > Users
Route::screen('users', UserListScreen::class)
    ->name('platform.systems.users')
    ->breadcrumbs(fn (Trail $trail) => $trail
        ->parent('platform.index')
        ->push(__('Users'), route('platform.systems.users')));

// Platform > System > Roles > Role
Route::screen('roles/{role}/edit', RoleEditScreen::class)
    ->name('platform.systems.roles.edit')
    ->breadcrumbs(fn (Trail $trail, $role) => $trail
        ->parent('platform.systems.roles')
        ->push($role->name, route('platform.systems.roles.edit', $role)));

// Platform > System > Roles > Create
Route::screen('roles/create', RoleEditScreen::class)
    ->name('platform.systems.roles.create')
    ->breadcrumbs(fn (Trail $trail) => $trail
        ->parent('platform.systems.roles')
        ->push(__('Create'), route('platform.systems.roles.create')));

// Platform > System > Roles
Route::screen('roles', RoleListScreen::class)
    ->name('platform.systems.roles')
    ->breadcrumbs(fn (Trail $trail) => $trail
        ->parent('platform.index')
        ->push(__('Roles'), route('platform.systems.roles')));
