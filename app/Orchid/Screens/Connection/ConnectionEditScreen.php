<?php

declare(strict_types=1);

namespace App\Orchid\Screens\Connection;

use App\Models\ActiveCollabConnection;
use App\Models\ActiveCollabIntegration;
use App\Orchid\Layouts\Connection\ConnectionEditLayout;
use App\Repositories\ActiveCollab\ActiveCollabConnectionRepository;
use App\Repositories\ActiveCollab\ActiveCollabRepository;
use App\Services\ActiveCollab\ActiveCollabConnectionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Orchid\Screen\Actions\Button;
use Orchid\Screen\Actions\Link;
use Orchid\Screen\Screen;
use Orchid\Support\Facades\Toast;

class ConnectionEditScreen extends Screen
{
    public ?ActiveCollabConnection $connection = null;

    public string $projectsSummary = '';

    public function __construct(
        private readonly ActiveCollabConnectionService $service,
        private readonly ActiveCollabConnectionRepository $connections,
        private readonly ActiveCollabRepository $client,
    ) {}

    /** @return array<string, mixed> */
    public function query(ActiveCollabConnection $connection): array
    {
        Gate::authorize('manageConnection', ActiveCollabIntegration::class);
        if (! $connection->exists) {
            $connection->fill(['active' => true]);
        }

        // Never hydrate a saved credential into a form value.
        $connection->setAttribute('api_token', '');

        return ['connection' => $connection, 'projects_summary' => $this->projectsSummary];
    }

    public function name(): ?string
    {
        return $this->connection?->exists ? 'Редактировать подключение' : 'Новое подключение';
    }

    public function commandBar(): iterable
    {
        $exists = $this->connection instanceof ActiveCollabConnection && $this->connection->exists;

        return [
            Link::make('Назад')->icon('bs.arrow-left')->route('platform.connections'),
            Button::make('Проверить подключение')->icon('bs.wifi')->method('test')->canSee($exists),
            Button::make('Сохранить')->icon('bs.check-circle')->method('save'),
            Button::make('Удалить')->icon('bs.trash3')->confirm('Удалить подключение?')
                ->method('remove')->canSee($exists),
        ];
    }

    public function layout(): iterable
    {
        return [ConnectionEditLayout::class];
    }

    public function save(ActiveCollabConnection $connection, Request $request): RedirectResponse
    {
        $data = $request->validate([
            'connection.name' => ['required', 'string', 'max:255'],
            'connection.base_url' => ['required', 'string', 'max:255'],
            'connection.api_token' => ['nullable', 'string'],
            'connection.active' => ['nullable', 'boolean'],
        ])['connection'];

        Gate::authorize('manageConnection', ActiveCollabIntegration::class);
        $saved = $this->service->store($data, $connection->exists ? $connection->id : null,
            (int) $request->user()->getAuthIdentifier());
        Toast::info('Подключение сохранено.');

        return redirect()->route('platform.connections.edit', $saved->id);
    }

    public function test(ActiveCollabConnection $connection): void
    {
        Gate::authorize('manageConnection', ActiveCollabIntegration::class);
        if (! $connection->exists || ! $connection->active) {
            throw ValidationException::withMessages(['connection' => 'Сохраните и активируйте подключение перед проверкой.']);
        }

        $projects = $this->client->projectsForConnection($connection);
        $this->projectsSummary = $this->formatProjects($projects);
        Toast::info(sprintf('Подключение проверено. Доступно проектов: %d.', count($projects)));
    }

    public function remove(ActiveCollabConnection $connection, Request $request): RedirectResponse
    {
        Gate::authorize('manageConnection', ActiveCollabIntegration::class);
        $this->connections->deleteUnused($connection->id, (int) $request->user()->getAuthIdentifier());
        Toast::info('Подключение удалено.');

        return redirect()->route('platform.connections');
    }

    /** @param list<array{id: int, name: string}> $projects */
    private function formatProjects(array $projects): string
    {
        return implode("\n", array_map(static fn (array $project): string => $project['id'].' — '.$project['name'], $projects));
    }
}
