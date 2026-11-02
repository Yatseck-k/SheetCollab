<?php

declare(strict_types=1);

namespace App\Data\ActiveCollab;

use App\Models\ActiveCollabIntegration;

readonly class ActiveCollabAccessContext
{
    public string $integrationId;

    private string $spreadsheetId;

    private int $projectId;

    private string $connectionId;

    private string $baseUrl;

    private string $apiToken;

    public function __construct(ActiveCollabIntegration $integration, public ?string $tokenId, private string $bearer)
    {
        $this->integrationId = $integration->id;
        $this->spreadsheetId = $integration->spreadsheet_id;
        $this->projectId = $integration->active_collab_project_id;
        $this->connectionId = (string) $integration->connection_id;
        $this->baseUrl = $integration->resolvedBaseUrl();
        $this->apiToken = $integration->resolvedApiToken();
    }

    public function matches(ActiveCollabIntegration $integration): bool
    {
        try {
            return $integration->id === $this->integrationId && $integration->spreadsheet_id === $this->spreadsheetId
                && $integration->active_collab_project_id === $this->projectId
                && $integration->connection_id === $this->connectionId && $integration->activeCollabConnection?->active === true
                && $integration->resolvedBaseUrl() === $this->baseUrl
                && hash_equals($this->apiToken, $integration->resolvedApiToken());
        } catch (\LogicException) {
            return false;
        }
    }

    /** @return list<string> */
    public function secrets(): array
    {
        return [$this->bearer, $this->apiToken];
    }

    public function matchesGlobalToken(): bool
    {
        $configured = (string) config('services.google_sheets.integration_token');

        return $configured !== '' && hash_equals($configured, $this->bearer);
    }
}
