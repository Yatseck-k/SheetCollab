<?php

declare(strict_types=1);

namespace App\Orchid\Screens\Integration;

use App\Models\ActiveCollabIntegration;
use App\Orchid\Layouts\Integration\IntegrationEditLayout;
use App\Orchid\Layouts\Integration\IntegrationMappingLayout;
use App\Repositories\ActiveCollab\ActiveCollabConnectionRepository;
use App\Repositories\ActiveCollab\ActiveCollabIntegrationRepository;
use App\Services\ActiveCollab\ActiveCollabIntegrationSettingsService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Orchid\Screen\Actions\Button;
use Orchid\Screen\Actions\Link;
use Orchid\Screen\Screen;
use Orchid\Support\Facades\Layout;
use Orchid\Support\Facades\Toast;

class IntegrationEditScreen extends Screen
{
    public ?ActiveCollabIntegration $integration = null;

    /** @var array<string, string> */
    private array $connectionOptions = [];

    public function __construct(
        private readonly ActiveCollabIntegrationSettingsService $settingsService,
        private readonly ActiveCollabConnectionRepository $connections,
        private readonly ActiveCollabIntegrationRepository $integrations,
    ) {}

    /** @return iterable<string, mixed> */
    public function query(ActiveCollabIntegration $integration): iterable
    {
        if (! $integration->exists) {
            Gate::authorize('create', ActiveCollabIntegration::class);
            $integration->fill([
                'active' => true,
            ]);
        } else {
            Gate::authorize('view', $integration);
        }

        $this->connectionOptions = ($integration->exists
            ? Gate::allows('manageSettings', $integration)
            : Gate::allows('create', ActiveCollabIntegration::class))
            ? $this->connections->activeOptions() : [];

        return ['integration' => $integration];
    }

    #[\Override]
    public function name(): ?string
    {
        return $this->integration?->exists
            ? 'Редактировать интеграцию'
            : 'Новая интеграция';
    }

    #[\Override]
    public function description(): ?string
    {
        if ($this->integration?->exists) {
            return $this->integration->name.' · сначала настройте таблицу и назначение, затем маппинги';
        }

        return 'Шаг 1: таблица и назначение ActiveCollab. После сохранения настройте маппинги.';
    }

    #[\Override]
    public function commandBar(): iterable
    {
        $hasIntegration = $this->integration instanceof ActiveCollabIntegration && $this->integration->exists;

        return [
            Link::make('Назад')
                ->icon('bs.arrow-left')
                ->route('platform.integrations'),

            Link::make('Порядок настройки')
                ->icon('bs.info-circle')
                ->route('platform.guide'),

            Button::make('Сохранить')
                ->icon('bs.check-circle')
                ->method('save')
                ->canSee($hasIntegration
                    ? Gate::allows('update', $this->integration)
                    : Gate::allows('create', ActiveCollabIntegration::class)),

            Button::make('Удалить')
                ->icon('bs.trash3')
                ->confirm('Удалить интеграцию? Это действие нельзя отменить.')
                ->method('remove')
                ->canSee($hasIntegration && Gate::allows('delete', $this->integration)),
        ];
    }

    public function layout(): iterable
    {
        return [
            Layout::tabs([
                '1. Таблица и назначение' => new IntegrationEditLayout(
                    $this->connectionOptions,
                    $this->integration?->exists
                        ? Gate::allows('manageSettings', $this->integration)
                        : Gate::allows('create', ActiveCollabIntegration::class),
                ),
                '2. Маппинги' => new IntegrationMappingLayout(),
            ]),
        ];
    }

    public function save(ActiveCollabIntegration $integration, Request $request): RedirectResponse
    {
        $admin = $integration->exists
            ? Gate::allows('manageSettings', $integration)
            : Gate::allows('create', ActiveCollabIntegration::class);

        if (! $admin) {
            Gate::authorize('update', $integration);
            $input = $request->validate([
                'integration.task_list_mapping' => ['nullable', 'array'],
                'integration.assignee_mapping' => ['nullable', 'array'],
                'integration.label_mapping' => ['nullable', 'array'],
            ])['integration'] ?? [];
            $data = $integration->only([
                'name', 'spreadsheet_id', 'connection_id', 'active_collab_project_id',
                'active_collab_task_list_id', 'active',
            ]) + $input;
        } else {
            Gate::authorize($integration->exists ? 'manageSettings' : 'create', $integration->exists ? $integration : ActiveCollabIntegration::class);
            $data = $request->validate([
                'integration.name' => ['required', 'string', 'max:255'],
                'integration.spreadsheet_id' => ['required', 'string'],
                'integration.connection_id' => ['required', 'uuid', 'exists:active_collab_connections,id'],
                'integration.active_collab_project_id' => ['nullable', 'string'],
                'integration.active_collab_task_list_id' => ['nullable', 'string'],
                'integration.task_list_mapping' => ['nullable', 'array'],
                'integration.assignee_mapping' => ['nullable', 'array'],
                'integration.label_mapping' => ['nullable', 'array'],
                'integration.active' => ['nullable', 'boolean'],
            ])['integration'];
        }

        // Преобразуем Matrix (массив строк [{key:..., value:...}]) в ассоциативный массив
        foreach (['task_list_mapping', 'assignee_mapping', 'label_mapping'] as $field) {
            $data[$field] = $this->normalizeMapping($data[$field] ?? null);
        }

        $data = $this->settingsService->prepareForStorage($data, $integration->exists ? $integration : null);
        $data['active'] = (bool) ($data['active'] ?? false);
        $saved = $this->integrations->saveSettings($integration->exists ? $integration->id : null, $data, (int) $request->user()->getAuthIdentifier());

        Toast::info('Интеграция сохранена.');

        return redirect()->route('platform.integrations.edit', $saved->id);
    }

    public function remove(ActiveCollabIntegration $integration, ActiveCollabIntegrationRepository $repository, Request $request): RedirectResponse
    {
        Gate::authorize('delete', $integration);
        $repository->deleteUnused($integration->id, (int) $request->user()->getAuthIdentifier());
        Toast::info('Интеграция удалена.');

        return redirect()->route('platform.integrations');
    }

    /**
     * @param  array<int, array<string, mixed>>|null  $rows
     * @return array<string, int|string>|null
     */
    private function normalizeMapping(?array $rows): ?array
    {
        if ($rows === null || $rows === []) {
            return null;
        }

        $result = [];
        foreach ($rows as $row) {
            $key = trim((string) ($row['key'] ?? ''));
            $value = trim((string) ($row['value'] ?? ''));
            if ($key !== '' && $value !== '') {
                $result[$key] = is_numeric($value) ? (int) $value : $value;
            }
        }

        return $result ?: null;
    }
}
