<?php

declare(strict_types=1);

namespace App\Orchid\Screens\Integration;

use App\Models\ActiveCollabIntegration;
use App\Orchid\Layouts\Integration\IntegrationEditLayout;
use App\Orchid\Layouts\Integration\IntegrationMappingLayout;
use App\Services\ActiveCollab\ActiveCollabIntegrationSettingsService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Orchid\Screen\Actions\Button;
use Orchid\Screen\Actions\Link;
use Orchid\Screen\Screen;
use Orchid\Support\Facades\Layout;
use Orchid\Support\Facades\Toast;

class IntegrationEditScreen extends Screen
{
    public ?ActiveCollabIntegration $integration = null;

    public function __construct(
        private readonly ActiveCollabIntegrationSettingsService $settingsService,
    ) {}

    /** @return iterable<string, mixed> */
    public function query(ActiveCollabIntegration $integration): iterable
    {
        if (! $integration->exists) {
            $integration->fill([
                'active_collab_base_url' => config('services.active_collab.base_url'),
                'active_collab_project_id' => config('services.active_collab.project_id'),
                'active' => true,
            ]);
        }

        return [
            'integration' => $integration,
        ];
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
        return $this->integration?->name;
    }

    #[\Override]
    public function commandBar(): iterable
    {
        $hasIntegration = $this->integration instanceof ActiveCollabIntegration && $this->integration->exists;

        return [
            Link::make('Назад')
                ->icon('bs.arrow-left')
                ->route('platform.integrations'),

            Button::make('Сохранить')
                ->icon('bs.check-circle')
                ->method('save'),

            Button::make('Удалить')
                ->icon('bs.trash3')
                ->confirm('Удалить интеграцию? Это действие нельзя отменить.')
                ->method('remove')
                ->canSee($hasIntegration),
        ];
    }

    public function layout(): iterable
    {
        return [
            Layout::tabs([
                'Основные настройки' => new IntegrationEditLayout(),
                'Маппинги' => new IntegrationMappingLayout(),
            ]),
        ];
    }

    public function save(ActiveCollabIntegration $integration, Request $request): RedirectResponse
    {
        $data = $request->validate([
            'integration.name' => ['required', 'string', 'max:255'],
            'integration.spreadsheet_id' => ['required', 'string'],
            'integration.active_collab_base_url' => ['nullable', 'string'],
            'integration.active_collab_project_id' => ['nullable', 'string'],
            'integration.active_collab_task_list_id' => ['nullable', 'string'],
            'integration.active_collab_api_token' => ['nullable', 'string'],
            'integration.task_list_mapping' => ['nullable', 'array'],
            'integration.assignee_mapping' => ['nullable', 'array'],
            'integration.label_mapping' => ['nullable', 'array'],
            'integration.active' => ['nullable', 'boolean'],
        ])['integration'];

        // Преобразуем Matrix (массив строк [{key:..., value:...}]) в ассоциативный массив
        foreach (['task_list_mapping', 'assignee_mapping', 'label_mapping'] as $field) {
            $data[$field] = $this->normalizeMapping($data[$field] ?? null);
        }

        try {
            $data = $this->settingsService->prepareForStorage($data, $integration->exists ? $integration : null);
        } catch (\Throwable $e) {
            throw ValidationException::withMessages([
                'integration.active_collab_base_url' => $e->getMessage(),
            ]);
        }

        $duplicateExists = ActiveCollabIntegration::query()
            ->where('spreadsheet_id', $data['spreadsheet_id'])
            ->when($integration->exists, fn ($query) => $query->whereKeyNot($integration->id))
            ->exists();

        if ($duplicateExists) {
            throw ValidationException::withMessages([
                'integration.spreadsheet_id' => 'Интеграция для этой Google Sheets таблицы уже существует.',
            ]);
        }

        $data['active'] = (bool) ($data['active'] ?? false);

        $integration->fill($data)->save();

        Toast::info('Интеграция сохранена.');

        return redirect()->route('platform.integrations.edit', $integration->id);
    }

    public function remove(ActiveCollabIntegration $integration): RedirectResponse
    {
        $integration->delete();
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
