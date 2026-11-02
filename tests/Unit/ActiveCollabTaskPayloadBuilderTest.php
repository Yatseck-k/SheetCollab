<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Data\ActiveCollab\ActiveCollabTaskData;
use App\Services\ActiveCollab\ActiveCollabMappingService;
use App\Services\ActiveCollab\ActiveCollabTaskBodyBuilder;
use App\Services\ActiveCollab\ActiveCollabTaskPayloadBuilder;
use Tests\Support\ActiveCollabIntegrationFixture;
use Tests\TestCase;

class ActiveCollabTaskPayloadBuilderTest extends TestCase
{
    public function test_hash_canonicalizes_object_keys_but_keeps_list_order_and_destination(): void
    {
        $builder = new ActiveCollabTaskPayloadBuilder(new ActiveCollabTaskBodyBuilder(), new ActiveCollabMappingService());
        $integration = ActiveCollabIntegrationFixture::make(['id' => '51bd4165-37d4-4fdc-9b1a-0d15b9b559a1',
            'connection_id' => '81bd4165-37d4-4fdc-9b1a-0d15b9b559a1',
            'active_collab_base_url' => 'https://ac.example.test/api/v1', 'active_collab_project_id' => 10]);
        $data = new ActiveCollabTaskData('sheet:0:8', 'sheet', '0', 8, null, null, null, null,
            'stage', 'Catalog', 'Bug', null, null, null, null, null, null);
        $first = ['body' => ['z' => 1, 'a' => 2], 'labels' => ['A', 'B']];
        $same = ['labels' => ['A', 'B'], 'body' => ['a' => 2, 'z' => 1]];
        $this->assertSame($builder->hash($first, $data, $integration, 7), $builder->hash($same, $data, $integration, 7));
        $this->assertNotSame($builder->hash($first, $data, $integration, 7),
            $builder->hash(['body' => ['a' => 2, 'z' => 1], 'labels' => ['B', 'A']], $data, $integration, 7));
        $this->assertNotSame($builder->hash($first, $data, $integration, 7), $builder->hash($same, $data, $integration, 8));
        $confirmedDestinationHash = $builder->hash($first, $data, $integration, 7);
        $integration->active_collab_project_id = 11;
        $this->assertNotSame($confirmedDestinationHash, $builder->hash($same, $data, $integration, 7));
    }

    public function test_label_mapping_requires_one_unambiguous_catalog_name(): void
    {
        $builder = new ActiveCollabTaskPayloadBuilder(new ActiveCollabTaskBodyBuilder(), new ActiveCollabMappingService());
        $prepared = ['payload' => ['name' => 'Bug', 'body' => 'Bug', 'task_list_id' => 31, 'labels' => []], 'label_id' => 7];
        $this->assertSame(['name' => 'Bug', 'body' => 'Bug', 'task_list_id' => 31, 'labels' => ['Critical']],
            $builder->finish($prepared, [['id' => 7, 'name' => 'Critical']]));
    }

    public function test_label_mapping_rejects_ambiguous_case_insensitive_catalog_names(): void
    {
        $builder = new ActiveCollabTaskPayloadBuilder(new ActiveCollabTaskBodyBuilder(), new ActiveCollabMappingService());
        $prepared = ['payload' => ['name' => 'Bug', 'body' => 'Bug', 'task_list_id' => 31, 'labels' => []], 'label_id' => 7];
        $this->expectException(\InvalidArgumentException::class);
        $builder->finish($prepared, [['id' => 7, 'name' => 'Critical'], ['id' => 8, 'name' => 'critical']]);
    }
}
