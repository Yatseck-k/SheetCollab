<?php

declare(strict_types=1);

namespace App\Orchid\Layouts\User;

use App\Models\ActiveCollabIntegration;
use Orchid\Screen\Field;
use Orchid\Screen\Fields\Select;
use Orchid\Screen\Layouts\Rows;

class UserIntegrationAccessLayout extends Rows
{
    /** @return array<int, Field> */
    public function fields(): array
    {
        return [
            Select::make('integration_access')
                ->fromModel(ActiveCollabIntegration::class, 'name')
                ->multiple()
                ->title('Доступ к интеграциям')
                ->help('Назначения только ограничивают доступ; права manager/viewer задаются Orchid role permissions.'),
        ];
    }
}
