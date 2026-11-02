<?php

declare(strict_types=1);

namespace App\Orchid\Screens\Connection;

use App\Models\ActiveCollabIntegration;
use App\Orchid\Layouts\Connection\ConnectionListLayout;
use App\Repositories\ActiveCollab\ActiveCollabConnectionRepository;
use Illuminate\Support\Facades\Gate;
use Orchid\Screen\Actions\Link;
use Orchid\Screen\Screen;

class ConnectionListScreen extends Screen
{
    public function __construct(private readonly ActiveCollabConnectionRepository $connections) {}

    /** @return array<string, mixed> */
    public function query(): array
    {
        Gate::authorize('manageConnection', ActiveCollabIntegration::class);

        return ['connections' => $this->connections->list()];
    }

    public function name(): ?string
    {
        return 'Подключения ActiveCollab';
    }

    public function commandBar(): iterable
    {
        return [Link::make('Добавить подключение')->icon('bs.plus-circle')->route('platform.connections.create')];
    }

    public function layout(): iterable
    {
        return [ConnectionListLayout::class];
    }
}
