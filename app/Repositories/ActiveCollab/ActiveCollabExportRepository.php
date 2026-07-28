<?php

declare(strict_types=1);

namespace App\Repositories\ActiveCollab;

use App\Data\ActiveCollab\ActiveCollabTaskData;
use App\Models\ActiveCollabIntegration;
use App\Models\ActiveCollabTaskExport;

class ActiveCollabExportRepository
{
    public function findByExternalId(string $externalId): ?ActiveCollabTaskExport
    {
        return ActiveCollabTaskExport::where('external_id', $externalId)->first();
    }

    public function findByTaskId(int $taskId): ?ActiveCollabTaskExport
    {
        return ActiveCollabTaskExport::where('task_id', $taskId)->first();
    }

    public function createPending(ActiveCollabTaskData $data, ActiveCollabIntegration $integration): ActiveCollabTaskExport
    {
        return ActiveCollabTaskExport::create([
            'integration_id' => $integration->id,
            'external_id' => $data->externalId,
            'spreadsheet_id' => $data->spreadsheetId,
            'sheet_id' => $data->sheetId,
            'row_number' => $data->rowNumber,
            'project_id' => $integration->active_collab_project_id,
            'source_payload' => $data->toArray(),
            'status' => 'pending',
        ]);
    }

    /**
     * @param  array<string, mixed>  $acPayload
     * @param  array<string, mixed>  $acResponse
     */
    public function markCreated(
        ActiveCollabTaskExport $export,
        int $taskId,
        string $taskUrl,
        string $syncHash,
        array $acPayload,
        array $acResponse,
    ): void {
        $export->update([
            'task_id' => $taskId,
            'task_url' => $taskUrl,
            'sync_hash' => $syncHash,
            'active_collab_payload' => $acPayload,
            'active_collab_response' => $acResponse,
            'status' => 'created',
            'error_message' => null,
        ]);
    }

    /**
     * @param  array<string, mixed>  $sourcePayload
     * @param  array<string, mixed>  $acPayload
     * @param  array<string, mixed>  $acResponse
     */
    public function markUpdated(
        ActiveCollabTaskExport $export,
        string $syncHash,
        array $sourcePayload,
        array $acPayload,
        array $acResponse,
    ): void {
        $export->update([
            'source_payload' => $sourcePayload,
            'sync_hash' => $syncHash,
            'active_collab_payload' => $acPayload,
            'active_collab_response' => $acResponse,
            'status' => 'updated',
            'error_message' => null,
        ]);
    }

    public function markFailed(ActiveCollabTaskExport $export, string $errorMessage): void
    {
        $export->update([
            'status' => 'failed',
            'error_message' => $errorMessage,
        ]);
    }
}
