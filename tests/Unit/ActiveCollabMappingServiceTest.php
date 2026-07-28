<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\ActiveCollabIntegration;
use App\Services\ActiveCollab\ActiveCollabMappingService;
use PHPUnit\Framework\TestCase;

class ActiveCollabMappingServiceTest extends TestCase
{
    public function test_it_returns_default_task_list_when_mapping_is_not_needed(): void
    {
        $service = new ActiveCollabMappingService();
        $integration = new ActiveCollabIntegration([
            'active_collab_task_list_id' => 31,
        ]);

        $this->assertSame(31, $service->resolveTaskListId(null, $integration));
        $this->assertSame(31, $service->resolveTaskListId('backend', $integration));
    }

    public function test_it_uses_task_type_mapping_and_throws_for_unknown_type(): void
    {
        $service = new ActiveCollabMappingService();
        $integration = new ActiveCollabIntegration([
            'active_collab_task_list_id' => 31,
            'task_list_mapping' => [
                'backend' => 101,
            ],
        ]);

        $this->assertSame(101, $service->resolveTaskListId(' Backend ', $integration));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Для типа задачи "frontend" не настроен ActiveCollab Task List.');

        $service->resolveTaskListId('frontend', $integration);
    }

    public function test_it_resolves_assignee_and_throws_when_mapping_is_missing(): void
    {
        $service = new ActiveCollabMappingService();
        $integration = new ActiveCollabIntegration([
            'assignee_mapping' => [
                'Tony Yats' => 3,
            ],
        ]);

        $this->assertSame(3, $service->resolveAssigneeId('Tony Yats', $integration));
        $this->assertNull($service->resolveAssigneeId(null, $integration));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Ответственный "Unknown User" не найден в маппинге.');

        $service->resolveAssigneeId('Unknown User', $integration);
    }

    public function test_it_resolves_label_case_insensitively(): void
    {
        $service = new ActiveCollabMappingService();
        $integration = new ActiveCollabIntegration([
            'label_mapping' => [
                'critical' => 7,
            ],
        ]);

        $this->assertSame(7, $service->resolveLabelId(' Critical ', $integration));
        $this->assertNull($service->resolveLabelId('minor', $integration));
        $this->assertNull($service->resolveLabelId(null, $integration));
    }
}
