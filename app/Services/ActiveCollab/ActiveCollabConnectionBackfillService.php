<?php

declare(strict_types=1);

namespace App\Services\ActiveCollab;

use App\Models\ActiveCollabIntegration;
use App\Repositories\ActiveCollab\ActiveCollabConnectionRepository;

class ActiveCollabConnectionBackfillService
{
    public function __construct(
        private readonly ActiveCollabConnectionRepository $connections,
        private readonly ActiveCollabConnectionService $connectionService,
    ) {}

    /** @return list<array{integration_id: string, action: string, connection_id: ?string, host: ?string, reason: ?string}> */
    public function run(bool $apply): array
    {
        $planned = [];
        $report = [];

        foreach ($this->connections->legacyIntegrations() as $integration) {
            try {
                $baseUrl = $this->effectiveBaseUrl($integration);
                $apiToken = $this->effectiveApiToken($integration);
                if ($baseUrl === '' || $apiToken === '') {
                    $report[] = $this->unresolved($integration, 'credentials_missing');

                    continue;
                }

                $baseUrl = $this->connectionService->normalizeBaseUrl($baseUrl);
            } catch (\Throwable) {
                $report[] = $this->unresolved($integration, 'source_invalid_or_unreadable');

                continue;
            }

            $host = (string) parse_url($baseUrl, PHP_URL_HOST);
            $match = $this->findExisting($baseUrl, $apiToken);
            if ($match !== null) {
                $action = 'reused';
                $connectionId = $match;
            } else {
                $match = $this->findPlanned($planned, $baseUrl, $apiToken);
                if ($match !== null) {
                    $action = 'reuse_planned';
                    $connectionId = null;
                } else {
                    $action = 'create';
                    $connectionId = null;
                    $planned[] = ['base_url' => $baseUrl, 'api_token' => $apiToken,
                        'connection_id' => 'planned-'.$integration->id];
                }
            }

            if ($apply) {
                $result = $this->connections->migrateLegacyIntegration(
                    $integration->id,
                    $baseUrl,
                    $apiToken,
                    $this->connectionName($integration),
                );
                $action = $result['action'];
                $connectionId = $result['connection_id'];
            }

            $report[] = ['integration_id' => $integration->id, 'action' => $action,
                'connection_id' => $connectionId, 'host' => $host, 'reason' => null];
        }

        return $report;
    }

    private function effectiveBaseUrl(ActiveCollabIntegration $integration): string
    {
        return trim((string) ($integration->active_collab_base_url ?: config('services.active_collab.base_url')));
    }

    private function effectiveApiToken(ActiveCollabIntegration $integration): string
    {
        try {
            $token = trim((string) $integration->active_collab_api_token);
        } catch (\Throwable) {
            return '';
        }

        return $token !== '' ? $token : trim((string) config('services.active_collab.api_token'));
    }

    private function findExisting(string $baseUrl, string $apiToken): ?string
    {
        foreach ($this->connections->findByBaseUrl($baseUrl) as $connection) {
            try {
                if (hash_equals((string) $connection->api_token, $apiToken)) {
                    return $connection->id;
                }
            } catch (\Throwable) {
                // Unreadable encrypted credentials are not candidates for migration.
            }
        }

        return null;
    }

    /** @param list<array{base_url: string, api_token: string, connection_id: string}> $planned */
    private function findPlanned(array $planned, string $baseUrl, string $apiToken): ?string
    {
        foreach ($planned as $item) {
            if ($item['base_url'] === $baseUrl && hash_equals($item['api_token'], $apiToken)) {
                return $item['connection_id'];
            }
        }

        return null;
    }

    /** @return array{integration_id: string, action: string, connection_id: ?string, host: ?string, reason: ?string} */
    private function unresolved(ActiveCollabIntegration $integration, string $reason): array
    {
        return ['integration_id' => $integration->id, 'action' => 'unresolved', 'connection_id' => null,
            'host' => null, 'reason' => $reason];
    }

    private function connectionName(ActiveCollabIntegration $integration): string
    {
        return 'Imported connection '.mb_substr($integration->id, 0, 8);
    }
}
