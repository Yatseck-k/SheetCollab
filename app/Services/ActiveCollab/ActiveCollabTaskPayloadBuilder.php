<?php

declare(strict_types=1);

namespace App\Services\ActiveCollab;

use App\Data\ActiveCollab\ActiveCollabTaskData;
use App\Models\ActiveCollabIntegration;

class ActiveCollabTaskPayloadBuilder
{
    public const string HASH_VERSION = 'payload-sha256-v2';

    public const string LEGACY_VERSION = 'legacy-md5-v1';

    public function __construct(private readonly ActiveCollabTaskBodyBuilder $body, private readonly ActiveCollabMappingService $mappings) {}

    /** @return array{payload: array<string, mixed>, label_id: ?int} */
    public function prepare(ActiveCollabTaskData $data, ActiveCollabIntegration $integration): array
    {
        $listId = $this->mappings->resolveTaskListId($data->taskType, $integration);
        $assigneeId = $this->mappings->resolveAssigneeId($data->responsible, $integration);
        $labelId = $this->mappings->resolveLabelId($data->severity, $integration);
        if ($listId < 1 || ($assigneeId !== null && $assigneeId < 1) || ($labelId !== null && $labelId < 1)) {
            throw new \InvalidArgumentException('Configured mapping IDs must be positive.');
        }
        $payload = ['name' => $this->body->buildName($data), 'body' => $this->body->buildBody($data),
            'task_list_id' => $listId, 'assignee_id' => $assigneeId ?? 0, 'labels' => []];

        return ['payload' => $payload, 'label_id' => $labelId];
    }

    /** @param array{payload: array<string, mixed>, label_id: ?int} $prepared
     * @param  list<array<string, mixed>>  $labels
     * @return array<string, mixed>
     */
    public function finish(array $prepared, array $labels): array
    {
        $payload = $prepared['payload'];
        $labelId = $prepared['label_id'];
        if ($labelId !== null) {
            $matched = array_values(array_filter($labels, fn ($label) => $label['id'] === $labelId));
            if (count($matched) !== 1 || trim($matched[0]['name']) === ''
                || count(array_filter($labels, fn ($label) => mb_strtolower($label['name']) === mb_strtolower($matched[0]['name']))) !== 1) {
                throw new \InvalidArgumentException('Task label mapping is missing or ambiguous.');
            }
            $payload['labels'] = [$matched[0]['name']];
        }

        return $payload;
    }

    /** @param array<string, mixed> $payload */
    public function hash(array $payload, ActiveCollabTaskData $data, ActiveCollabIntegration $integration, ?int $labelId = null): string
    {
        $value = ['version' => self::HASH_VERSION, 'destination' => ['integration_id' => $integration->id,
            'connection_id' => $integration->connection_id,
            'base_url' => $integration->resolvedBaseUrl(), 'project_id' => $integration->active_collab_project_id],
            'mapping' => ['label_id' => $labelId], 'payload' => $payload];

        return hash('sha256', json_encode($this->canonical($value), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /** @param array<string|int, mixed> $value
     * @return array<string|int, mixed>
     */
    private function canonical(array $value): array
    {
        if (! array_is_list($value)) {
            ksort($value, SORT_STRING);
        }
        foreach ($value as &$item) {
            if (is_array($item)) {
                $item = $this->canonical($item);
            }
        }

        return $value;
    }
}
