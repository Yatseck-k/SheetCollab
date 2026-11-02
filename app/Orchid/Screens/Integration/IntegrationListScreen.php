<?php

declare(strict_types=1);

namespace App\Orchid\Screens\Integration;

use App\Models\ActiveCollabConnection;
use App\Models\ActiveCollabIntegration;
use App\Orchid\Layouts\Integration\IntegrationListLayout;
use App\Repositories\ActiveCollab\ActiveCollabIntegrationRepository;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Orchid\Screen\Actions\Link;
use Orchid\Screen\Screen;
use Orchid\Support\Facades\Toast;

class IntegrationListScreen extends Screen
{
    /** @return iterable<string, mixed> */
    public function query(Request $request): iterable
    {
        abort_unless(Gate::allows('viewAny', ActiveCollabIntegration::class), 404);

        $integrations = ActiveCollabIntegration::query()->with('activeCollabConnection')
            ->when(! Gate::allows('manageConnection', ActiveCollabConnection::class), fn ($query) => $query->whereHas(
                'users', fn ($users) => $users->whereKey($request->user()->getAuthIdentifier()),
            ))
            ->orderBy('created_at', 'desc');

        return [
            'integrations' => $integrations->paginate(),
        ];
    }

    #[\Override]
    public function name(): ?string
    {
        return 'Интеграции ActiveCollab';
    }

    #[\Override]
    public function description(): ?string
    {
        return 'Связки Google Sheets ↔ ActiveCollab проект';
    }

    #[\Override]
    public function commandBar(): iterable
    {
        return [
            Link::make('Добавить интеграцию')
                ->icon('bs.plus-circle')
                ->route('platform.integrations.create')
                ->canSee(Gate::allows('create', ActiveCollabIntegration::class)),
        ];
    }

    public function layout(): iterable
    {
        return [
            IntegrationListLayout::class,
        ];
    }

    public function remove(Request $request, ActiveCollabIntegrationRepository $repository): void
    {
        $integration = ActiveCollabIntegration::query()->findOrFail((string) $request->get('id'));
        Gate::authorize('delete', $integration);
        $repository->deleteUnused($integration->id, (int) $request->user()->getAuthIdentifier());
        Toast::info('Интеграция удалена.');
    }
}
