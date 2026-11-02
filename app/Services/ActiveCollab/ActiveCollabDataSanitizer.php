<?php

declare(strict_types=1);

namespace App\Services\ActiveCollab;

use App\Models\ActiveCollabIntegration;

class ActiveCollabDataSanitizer
{
    /** @param array<string, mixed> $values
     * @return array<string, mixed>
     */
    public function source(array $values, ActiveCollabIntegration $integration): array
    {
        return $this->clean(array_intersect_key($values, array_flip([
            'record_uuid', 'external_id', 'spreadsheet_id', 'sheet_id', 'row_number', 'severity', 'priority', 'device', 'resolution',
            'environment', 'section', 'problem_description', 'screenshot_url', 'comment', 'task_type', 'responsible', 'reporter', 'sheet_status',
        ])), $integration);
    }

    /**
     * Callers supply source/task whitelists or validated upstream single fields, never raw response/headers.
     *
     * @param  array<array-key, mixed>  $values
     * @param  list<string>  $extraSecrets
     * @return array<array-key, mixed>
     */
    public function clean(array $values, ActiveCollabIntegration $integration, array $extraSecrets = []): array
    {
        try {
            $connectionToken = $integration->resolvedApiToken();
        } catch (\LogicException) {
            $connectionToken = '';
        }
        try {
            $legacyToken = (string) $integration->active_collab_api_token;
        } catch (\Throwable) { // @phpstan-ignore catch.neverThrown (The encrypted legacy cast can fail at runtime.)
            $legacyToken = '';
        }
        $tokens = array_values(array_filter([
            ...$extraSecrets, $connectionToken, $legacyToken,
            (string) config('services.active_collab.api_token'),
            (string) config('services.google_sheets.integration_token'),
        ], fn (string $value): bool => $value !== ''));
        array_walk_recursive($values, static function (&$value) use ($tokens): void {
            if (is_string($value)) {
                $value = str_replace($tokens, '[REDACTED]', $value);
            }
        });

        return $values;
    }
}
