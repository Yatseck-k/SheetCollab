<?php

declare(strict_types=1);

namespace App\Services\ActiveCollab;

use App\Models\ActiveCollabIntegration;

class ActiveCollabMappingService
{
    public function resolveTaskListId(?string $taskType, ActiveCollabIntegration $integration): int
    {
        $map = $integration->task_list_mapping ?? [];

        if (! $taskType || empty($map)) {
            return $integration->active_collab_task_list_id;
        }

        $key = strtolower(trim($taskType));

        if (! array_key_exists($key, $map)) {
            throw new \RuntimeException("Для типа задачи \"{$taskType}\" не настроен ActiveCollab Task List.");
        }

        return (int) $map[$key];
    }

    public function resolveAssigneeId(?string $responsible, ActiveCollabIntegration $integration): ?int
    {
        if (! $responsible) {
            return null;
        }

        $map = $integration->assignee_mapping ?? [];

        if (! array_key_exists($responsible, $map)) {
            throw new \RuntimeException("Ответственный \"{$responsible}\" не найден в маппинге.");
        }

        return (int) $map[$responsible];
    }

    public function resolveLabelId(?string $severity, ActiveCollabIntegration $integration): ?int
    {
        if (! $severity) {
            return null;
        }

        $map = $integration->label_mapping ?? [];
        $key = strtolower(trim($severity));

        return isset($map[$key]) ? (int) $map[$key] : null;
    }
}
