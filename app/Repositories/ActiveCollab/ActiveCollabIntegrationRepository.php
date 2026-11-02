<?php

declare(strict_types=1);

namespace App\Repositories\ActiveCollab;

use App\Models\ActiveCollabConnection;
use App\Models\ActiveCollabIntegration;
use App\Models\ActiveCollabIntegrationToken;
use App\Models\ActiveCollabTaskExport;
use App\Repositories\AdminAuditLogRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ActiveCollabIntegrationRepository
{
    public function __construct(private readonly AdminAuditLogRepository $audit = new AdminAuditLogRepository()) {}

    /** @param array<string, mixed> $data */
    public function saveSettings(?string $id, array $data, ?int $actorId = null): ActiveCollabIntegration
    {
        return DB::transaction(function () use ($id, $data, $actorId): ActiveCollabIntegration {
            $integration = $id === null
                ? new ActiveCollabIntegration()
                : ActiveCollabIntegration::query()->whereKey($id)->lockForUpdate()->firstOrFail();

            $connection = ActiveCollabConnection::query()->whereKey($data['connection_id'] ?? null)
                ->where('active', true)->lockForUpdate()->first();
            if ($connection === null) {
                throw ValidationException::withMessages(['integration.connection_id' => 'Выбранное подключение недоступно.']);
            }

            if ($integration->exists && ($integration->connection_id !== $connection->id
                || $integration->active_collab_project_id !== (int) $data['active_collab_project_id'])
                && ActiveCollabTaskExport::query()->where('integration_id', $integration->id)->exists()) {
                throw ValidationException::withMessages([
                    'integration.connection_id' => 'Назначение интеграции с сохранённой историей менять нельзя. Создайте новую интеграцию.',
                ]);
            }

            $duplicate = ActiveCollabIntegration::query()->where('spreadsheet_id', $data['spreadsheet_id'])
                ->when($integration->exists, fn ($query) => $query->whereKeyNot($integration->id))->exists();
            if ($duplicate) {
                throw ValidationException::withMessages(['integration.spreadsheet_id' => 'Интеграция для этой Google Sheets таблицы уже существует.']);
            }

            $before = $integration->exists ? $this->safeSnapshot($integration) : [];
            $integration->fill($data)->save();
            $after = $this->safeSnapshot($integration);
            if ($actorId !== null && ($before !== $after || $integration->wasRecentlyCreated)) {
                $this->audit->recordMutation($actorId, 'active_collab_integration', $integration->id,
                    $integration->wasRecentlyCreated ? 'integration_created' : 'integration_settings_updated',
                    $before, $after, $integration->id, $integration->connection_id);
            }

            return $integration->refresh();
        }, 3);
    }

    public function deleteUnused(string $id, ?int $actorId = null): void
    {
        DB::transaction(function () use ($id, $actorId): void {
            $integration = ActiveCollabIntegration::whereKey($id)->lockForUpdate()->firstOrFail();
            if (ActiveCollabTaskExport::where('integration_id', $id)->exists()
                || ActiveCollabIntegrationToken::where('integration_id', $id)->exists()) {
                throw ValidationException::withMessages(['integration' => 'Интеграция используется. Отключите её вместо удаления.']);
            }
            if ($actorId !== null) {
                $this->audit->recordMutation($actorId, 'active_collab_integration', $integration->id,
                    'integration_deleted', $this->safeSnapshot($integration), [], $integration->id, $integration->connection_id);
            }
            $integration->delete();
        }, 3);
    }

    /** @return array<string, mixed> */
    private function safeSnapshot(ActiveCollabIntegration $integration): array
    {
        return $integration->only([
            'name', 'spreadsheet_id', 'connection_id', 'active_collab_project_id', 'active_collab_task_list_id',
            'task_list_mapping', 'assignee_mapping', 'label_mapping', 'active',
        ]);
    }

    public function findBySpreadsheetId(string $spreadsheetId): ?ActiveCollabIntegration
    {
        return ActiveCollabIntegration::where('spreadsheet_id', $spreadsheetId)
            ->where('active', true)
            ->first();
    }
}
