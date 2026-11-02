<?php

declare(strict_types=1);

namespace App\Orchid;

use Orchid\Platform\ItemPermission;
use Orchid\Platform\OrchidServiceProvider;
use Orchid\Screen\Actions\Menu;

class PlatformProvider extends OrchidServiceProvider
{
    /**
     * Register the application menu.
     *
     * @return Menu[]
     */
    #[\Override]
    public function menu(): array
    {
        return [
            Menu::make('Обзор')
                ->icon('bs.speedometer2')
                ->route('platform.main')
                ->permission([
                    'platform.activecollab.admin', 'platform.activecollab.integration.manage',
                    'platform.activecollab.integration.view', 'platform.systems.settings',
                ]),

            Menu::make('Инструкция')
                ->icon('bs.info-circle')
                ->route('platform.guide')
                ->title('ActiveCollab'),

            Menu::make('Интеграции')
                ->icon('bs.link-45deg')
                ->route('platform.integrations')
                ->permission([
                    'platform.activecollab.admin', 'platform.activecollab.integration.manage',
                    'platform.activecollab.integration.view', 'platform.systems.settings',
                ]),

            Menu::make('Подключения ActiveCollab')
                ->icon('bs.plug')
                ->route('platform.connections')
                ->permission(['platform.activecollab.admin', 'platform.systems.settings']),

            Menu::make('Скачать Apps Script')
                ->icon('bs.code-slash')
                ->route('platform.script.download'),

            Menu::make('История экспортов')
                ->icon('bs.clock-history')
                ->route('platform.exports')
                ->permission([
                    'platform.activecollab.admin', 'platform.activecollab.integration.manage',
                    'platform.activecollab.integration.view', 'platform.systems.settings',
                ])
                ->divider(),

            Menu::make(__('Users'))
                ->icon('bs.people')
                ->route('platform.systems.users')
                ->permission(['platform.activecollab.admin', 'platform.systems.users'])
                ->title(__('Access Controls')),

            Menu::make(__('Roles'))
                ->icon('bs.shield')
                ->route('platform.systems.roles')
                ->permission(['platform.activecollab.admin', 'platform.systems.roles']),
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
                ->addPermission('platform.activecollab.admin', 'ActiveCollab: администратор')
                ->addPermission('platform.activecollab.integration.manage', 'ActiveCollab: менеджер интеграций')
                ->addPermission('platform.activecollab.integration.view', 'ActiveCollab: просмотр назначенных интеграций')
                ->addPermission('platform.activecollab.recovery', 'Восстановление синхронизации (администратор)'),
        ];
    }
}
