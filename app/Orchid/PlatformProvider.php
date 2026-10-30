<?php

declare(strict_types=1);

namespace App\Orchid;

use Orchid\Platform\Dashboard;
use Orchid\Platform\ItemPermission;
use Orchid\Platform\OrchidServiceProvider;
use Orchid\Screen\Actions\Menu;

class PlatformProvider extends OrchidServiceProvider
{
    /**
     * Bootstrap the application services.
     */
    #[\Override]
    public function boot(Dashboard $dashboard): void
    {
        parent::boot($dashboard);

        // ...
    }

    /**
     * Register the application menu.
     *
     * @return Menu[]
     */
    #[\Override]
    public function menu(): array
    {
        return [
            Menu::make('Инструкция')
                ->icon('bs.info-circle')
                ->route('platform.guide')
                ->title('ActiveCollab'),

            Menu::make('Интеграции')
                ->icon('bs.link-45deg')
                ->route('platform.integrations'),

            Menu::make('Apps Script')
                ->icon('bs.code-slash')
                ->route('platform.script'),

            Menu::make('История экспортов')
                ->icon('bs.clock-history')
                ->route('platform.exports')
                ->divider(),

            Menu::make(__('Users'))
                ->icon('bs.people')
                ->route('platform.systems.users')
                ->permission('platform.systems.users')
                ->title(__('Access Controls')),

            Menu::make(__('Roles'))
                ->icon('bs.shield')
                ->route('platform.systems.roles')
                ->permission('platform.systems.roles'),
        ];
    }

    /**
     * Register permissions for the application.
     *
     * @return ItemPermission[]
     */
    #[\Override]
    public function permissions(): array
    {
        return [
            ItemPermission::group(__('System'))
                ->addPermission('platform.systems.roles', __('Roles'))
                ->addPermission('platform.systems.users', __('Users'))
                ->addPermission('platform.activecollab.recovery', 'Восстановление синхронизации (администратор)'),
        ];
    }
}
