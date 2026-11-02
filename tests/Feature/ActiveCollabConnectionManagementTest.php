<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ActiveCollabConnection;
use App\Repositories\ActiveCollab\ActiveCollabConnectionRepository;
use App\Services\ActiveCollab\ActiveCollabConnectionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\Support\ActiveCollabIntegrationFixture;
use Tests\TestCase;

class ActiveCollabConnectionManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('app.key', 'base64:J63P4m4KjJ8l0RkGxJZ4Kf9P9+K6f2R4R6V4a2M1LxQ=');
    }

    public function test_connection_normalizes_url_encrypts_token_and_hides_it(): void
    {
        $service = new ActiveCollabConnectionService(new ActiveCollabConnectionRepository());
        $connection = $service->store([
            'name' => 'Primary', 'base_url' => 'HTTPS://AC.EXAMPLE.TEST/projects/10', 'api_token' => 'plain-secret', 'active' => true,
        ]);

        $this->assertSame('https://ac.example.test/api/v1', $connection->base_url);
        $this->assertSame('plain-secret', $connection->api_token);
        $this->assertStringNotContainsString('plain-secret', $connection->getRawOriginal('api_token'));
        $this->assertArrayNotHasKey('api_token', $connection->toArray());
    }

    public function test_connection_token_rotation_preserves_a_blank_password_and_host_change_is_blocked_when_used(): void
    {
        $repository = new ActiveCollabConnectionRepository();
        $service = new ActiveCollabConnectionService($repository);
        $connection = $service->store([
            'name' => 'Primary', 'base_url' => 'https://ac.example.test', 'api_token' => 'old-secret', 'active' => true,
        ]);
        ActiveCollabIntegrationFixture::create([
            'name' => 'Sheet', 'spreadsheet_id' => 'sheet-id', 'connection_id' => $connection->id,
            'active_collab_project_id' => 10, 'active_collab_task_list_id' => 31, 'active' => true,
        ]);

        $same = $service->store([
            'name' => 'Primary', 'base_url' => 'https://ac.example.test/api/v1', 'api_token' => '', 'active' => true,
        ], $connection->id);
        $this->assertSame('old-secret', $same->api_token);

        $this->expectException(ValidationException::class);
        $service->store([
            'name' => 'Primary', 'base_url' => 'https://other.example.test', 'api_token' => 'new-secret', 'active' => true,
        ], $connection->id);
    }

    public function test_connection_in_use_cannot_be_deleted(): void
    {
        $connection = ActiveCollabConnection::query()->create([
            'name' => 'Primary', 'base_url' => 'https://ac.example.test/api/v1', 'api_token' => 'secret', 'active' => true,
        ]);
        ActiveCollabIntegrationFixture::create([
            'name' => 'Sheet', 'spreadsheet_id' => 'sheet-id', 'connection_id' => $connection->id,
            'active_collab_project_id' => 10, 'active_collab_task_list_id' => 31, 'active' => true,
        ]);

        $this->expectException(ValidationException::class);
        (new ActiveCollabConnectionRepository())->deleteUnused($connection->id);
    }

    public function test_connection_url_rejects_embedded_credentials_query_and_fragment(): void
    {
        $service = new ActiveCollabConnectionService(new ActiveCollabConnectionRepository());
        $rejectedUrls = 0;
        foreach (['https://user:pass@ac.example.test', 'https://ac.example.test?token=secret', 'https://ac.example.test/#fragment'] as $url) {
            try {
                $service->normalizeBaseUrl($url);
                $this->fail('Unsafe URL was accepted.');
            } catch (ValidationException) {
                $rejectedUrls++;
            }
        }

        $this->assertSame(3, $rejectedUrls);
    }
}
