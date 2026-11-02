<?php

declare(strict_types=1);

namespace App\Repositories\ActiveCollab;

use App\Data\ActiveCollab\ActiveCollabAccessContext;
use App\Data\ActiveCollab\ActiveCollabTaskData;
use App\Exceptions\IntegrationNotFoundException;
use App\Exceptions\TaskSyncConflictException;
use App\Models\ActiveCollabConnection;
use App\Models\ActiveCollabIntegration;
use App\Models\ActiveCollabIntegrationToken;
use App\Models\ActiveCollabTaskExport;
use App\Models\User;
use App\Repositories\AdminAuditLogRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class ActiveCollabIntegrationTokenRepository
{
    public function __construct(private readonly AdminAuditLogRepository $audit = new AdminAuditLogRepository()) {}

    public function authenticate(string $provided, ?string $spreadsheetId, bool $individualOnly): ActiveCollabAccessContext
    {
        if ($spreadsheetId !== null) {
            foreach ([$provided, (string) config('services.google_sheets.integration_token'),
                (string) config('services.active_collab.api_token')] as $secret) {
                if ($secret !== '' && str_contains($spreadsheetId, $secret)) {
                    throw new TaskSyncConflictException('forbidden', null, 403);
                }
            }
        }
        if (preg_match('/^sc_[a-f0-9]{64}$/D', $provided)) {
            $token = ActiveCollabIntegrationToken::where('token_hash', hash('sha256', $provided))->first();
            if ($token === null || $token->revoked_at !== null) {
                throw new TaskSyncConflictException('unauthorized', null, 401);
            }
            $integration = ActiveCollabIntegration::find($token->integration_id);
        } else {
            $expected = (string) config('services.google_sheets.integration_token');
            if ($individualOnly || ! config('services.active_collab.allow_legacy_token', true)
                || str_starts_with($provided, 'sc_') || $provided === '' || $expected === '' || ! hash_equals($expected, $provided)) {
                throw new TaskSyncConflictException('unauthorized', null, 401);
            }
            if ($spreadsheetId === null || $spreadsheetId === '') {
                throw new TaskSyncConflictException('forbidden', null, 403);
            }
            $integration = ActiveCollabIntegration::where('spreadsheet_id', ActiveCollabTaskData::normalizeSpreadsheetId($spreadsheetId))->first();
            if ($integration === null || ! $integration->active) {
                throw new IntegrationNotFoundException($spreadsheetId);
            }
            if (! $integration->allow_legacy_token) {
                throw new TaskSyncConflictException('forbidden', null, 403);
            }
        }
        if ($integration === null || ! $integration->active) {
            throw new TaskSyncConflictException('forbidden', null, 403);
        }
        if ($integration->activeCollabConnection === null || ! $integration->activeCollabConnection->active) {
            throw new TaskSyncConflictException('forbidden', null, 403);
        }
        if ($spreadsheetId !== null && ActiveCollabTaskData::normalizeSpreadsheetId($spreadsheetId) !== $integration->spreadsheet_id) {
            throw new TaskSyncConflictException('forbidden', null, 403);
        }

        return new ActiveCollabAccessContext($integration, isset($token) ? $token->id : null, $provided);
    }

    /** The caller holds the integration lock before this method locks the token. */
    public function assertAccess(ActiveCollabAccessContext $context, ActiveCollabIntegration $integration, bool $touch = false): void
    {
        $connection = $integration->connection_id !== null
            ? ActiveCollabConnection::query()->whereKey($integration->connection_id)->lockForUpdate()->first()
            : null;
        if ($connection !== null) {
            $integration->setRelation('activeCollabConnection', $connection);
        }

        if (! $integration->active || $connection?->active !== true || ! $context->matches($integration)) {
            throw new TaskSyncConflictException('forbidden', null, 403);
        }
        if ($context->tokenId !== null) {
            $token = ActiveCollabIntegrationToken::whereKey($context->tokenId)->where('integration_id', $integration->id)->lockForUpdate()->first();
            if ($token === null || $token->revoked_at !== null) {
                throw new TaskSyncConflictException('unauthorized', null, 401);
            }
            if ($touch) {
                $token->update(['last_used_at' => now()]);
            }
        } elseif (! $integration->allow_legacy_token || ! $context->matchesGlobalToken()) {
            throw new TaskSyncConflictException('forbidden', null, 403);
        }
    }

    public function integration(ActiveCollabAccessContext $context, bool $touch = false): ActiveCollabIntegration
    {
        return DB::transaction(function () use ($context, $touch): ActiveCollabIntegration {
            $integration = ActiveCollabIntegration::whereKey($context->integrationId)->lockForUpdate()->first();
            if ($integration === null) {
                throw new TaskSyncConflictException('forbidden', null, 403);
            }
            $this->assertAccess($context, $integration, $touch);

            return $integration;
        }, 3);
    }

    /** @return array<string, mixed> */
    public function manage(string $action, string $integrationId, int $actorId, string $reason, ?string $name, ?string $tokenId): array
    {
        if ($action === 'legacy-status') {
            Gate::forUser(User::findOrFail($actorId))->authorize('recover', ActiveCollabTaskExport::class);

            return ['legacy_enabled' => (bool) config('services.active_collab.allow_legacy_token', true),
                'integrations' => ActiveCollabIntegration::query()->where('allow_legacy_token', true)
                    ->orderBy('name')->get(['id', 'name', 'spreadsheet_id'])->toArray()];
        }

        return DB::transaction(function () use ($action, $integrationId, $actorId, $reason, $name, $tokenId): array {
            $integration = ActiveCollabIntegration::whereKey($integrationId)->lockForUpdate()->firstOrFail();
            Gate::forUser(User::findOrFail($actorId))->authorize('recover', ActiveCollabTaskExport::class);
            if ($action === 'list') {
                return ['tokens' => ActiveCollabIntegrationToken::where('integration_id', $integrationId)->orderBy('created_at')->get()->toArray()];
            }
            $before = [];
            $after = [];
            $result = [];
            if ($action === 'issue') {
                $plaintext = 'sc_'.bin2hex(random_bytes(32));
                $token = ActiveCollabIntegrationToken::create(['integration_id' => $integrationId, 'name' => $name,
                    'token_hash' => hash('sha256', $plaintext), 'created_at' => now()]);
                $after = $token->only(['id', 'name', 'created_at']);
                $result = ['token_id' => $token->id, 'token' => $plaintext];
            } elseif ($action === 'revoke') {
                $token = ActiveCollabIntegrationToken::whereKey($tokenId)->where('integration_id', $integrationId)->lockForUpdate()->firstOrFail();
                if ($token->revoked_at !== null) {
                    return ['token_id' => $token->id, 'changed' => false];
                }
                $before = $token->only(['id', 'name', 'revoked_at']);
                $token->update(['revoked_at' => now()]);
                $after = $token->only(['id', 'name', 'revoked_at']);
            } else {
                $enabled = $action === 'enable-legacy';
                if ((bool) $integration->allow_legacy_token === $enabled) {
                    return ['changed' => false];
                }
                $before = ['allow_legacy_token' => (bool) $integration->allow_legacy_token];
                $integration->forceFill(['allow_legacy_token' => $enabled])->save();
                $after = ['allow_legacy_token' => $enabled];
            }
            $this->audit->accessMutation($actorId, $integration, $action, $before, $after, $reason);

            return $result + ['changed' => true];
        }, 3);
    }
}
