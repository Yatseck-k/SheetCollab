<?php

declare(strict_types=1);

namespace App\Orchid\Layouts\Connection;

use App\Models\ActiveCollabConnection;
use Orchid\Screen\Actions\DropDown;
use Orchid\Screen\Actions\Link;
use Orchid\Screen\Layouts\Table;
use Orchid\Screen\TD;

class ConnectionListLayout extends Table
{
    protected $target = 'connections';

    /** @return array<int, TD> */
    protected function columns(): array
    {
        return [
            TD::make('name', 'Название')->render(fn (ActiveCollabConnection $connection) => Link::make($connection->name)
                ->route('platform.connections.edit', ['connection' => $connection->id])),
            TD::make('base_url', 'API URL'),
            TD::make('active', 'Активно')->render(fn (ActiveCollabConnection $connection) => $connection->active ? 'Да' : 'Нет'),
            TD::make('updated_at', 'Изменено')->render(fn (ActiveCollabConnection $connection) => $connection->updated_at?->format('d.m.Y H:i') ?? '—'),
            TD::make('Действия')->render(fn (ActiveCollabConnection $connection) => DropDown::make()->icon('bs.three-dots-vertical')->list([
                Link::make('Редактировать')->icon('bs.pencil')->route('platform.connections.edit', ['connection' => $connection->id]),
            ])),
        ];
    }
}
