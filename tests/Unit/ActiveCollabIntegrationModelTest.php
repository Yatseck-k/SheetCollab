<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\ActiveCollabIntegration;
use Tests\Support\ActiveCollabIntegrationFixture;
use Tests\TestCase;

class ActiveCollabIntegrationModelTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config()->set('app.key', 'base64:J63P4m4KjJ8l0RkGxJZ4Kf9P9+K6f2R4R6V4a2M1LxQ=');
    }

    // ── resolvedBaseUrl ───────────────────────────────────────────────────────────

    public function test_resolved_base_url_returns_trimmed_model_value(): void
    {
        $integration = ActiveCollabIntegrationFixture::make([
            'active_collab_base_url' => '  https://ac.example.com/api/v1  ',
        ]);

        $this->assertSame('https://ac.example.com/api/v1', $integration->resolvedBaseUrl());
    }

    public function test_resolved_base_url_strips_trailing_slash(): void
    {
        $integration = ActiveCollabIntegrationFixture::make([
            'active_collab_base_url' => 'https://ac.example.com/api/v1/',
        ]);

        $this->assertSame('https://ac.example.com/api/v1', $integration->resolvedBaseUrl());
    }

    public function test_resolved_base_url_does_not_fall_back_to_config_when_connection_is_missing(): void
    {
        config()->set('services.active_collab.base_url', 'https://fallback.example.com/api/v1');
        $integration = new ActiveCollabIntegration();
        $this->expectException(\LogicException::class);
        $integration->resolvedBaseUrl();
    }

    // ── resolvedInstanceUrl ───────────────────────────────────────────────────────

    public function test_resolved_instance_url_strips_api_v1_suffix(): void
    {
        $integration = ActiveCollabIntegrationFixture::make([
            'active_collab_base_url' => 'https://app.activecollab.com/460565/api/v1',
        ]);

        $this->assertSame('https://app.activecollab.com/460565', $integration->resolvedInstanceUrl());
    }

    public function test_resolved_instance_url_strips_any_api_version_suffix(): void
    {
        $integration = ActiveCollabIntegrationFixture::make([
            'active_collab_base_url' => 'https://ac.example.com/api/v2',
        ]);

        $this->assertSame('https://ac.example.com', $integration->resolvedInstanceUrl());
    }

    // ── resolvedApiToken ──────────────────────────────────────────────────────────

    public function test_resolved_api_token_returns_trimmed_model_token(): void
    {
        $integration = ActiveCollabIntegrationFixture::make(['active_collab_api_token' => '  my-secret-token  ']);

        $this->assertSame('my-secret-token', $integration->resolvedApiToken());
    }

    public function test_resolved_api_token_does_not_fall_back_to_config_when_connection_is_missing(): void
    {
        config()->set('services.active_collab.api_token', 'config-level-token');
        $integration = new ActiveCollabIntegration();
        $this->expectException(\LogicException::class);
        $integration->resolvedApiToken();
    }
}
