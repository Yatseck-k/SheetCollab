<?php

declare(strict_types=1);

namespace App\Repositories\ActiveCollab;

use App\Models\ActiveCollabConnection;
use App\Models\ActiveCollabIntegration;
use App\Repositories\AdminAuditLogRepository;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ActiveCollabConnectionRepository
{
    public function __construct(private readonly AdminAuditLogRepository $audit = new AdminAuditLogRepository()) {}

    public function find(string $id): ?ActiveCollabConnection
    {
        return ActiveCollabConnection::query()->find($id);
    }

    public function findActive(string $id): ?ActiveCollabConnection
    {
        return ActiveCollabConnection::query()->whereKey($id)->where('active', true)->first();
    }

    /** @return Collection<int, ActiveCollabConnection> */
    public function list(): Collection
    {
        return ActiveCollabConnection::query()->orderBy('name')->get();
    }

    /** @return array<string, string> */
    public function activeOptions(): array
    {
        return ActiveCollabConnection::query()->where('active', true)->orderBy('name')->pluck('name', 'id')->all();
    }

    /** @return Collection<int, ActiveCollabConnection> */
    public function findByBaseUrl(string $baseUrl): Collection
    {
        return ActiveCollabConnection::query()->where('base_url', $baseUrl)->get();
    }

    /** @return Collection<int, ActiveCollabIntegration> */
    public function legacyIntegrations(): Collection
    {
        return ActiveCollabIntegration::query()->whereNull('connection_id')->orderBy('id')->get();
    }

    /** @param array{name: string, base_url: string, api_token: string, active: bool} $data */
    public function create(array $data, ?int $actorId = null): ActiveCollabConnection
    {
        return DB::transaction(function () use ($data, $actorId): ActiveCollabConnection {
            $connection = ActiveCollabConnection::query()->create($data);
            if ($actorId !== null) {
                $this->audit->recordMutation($actorId, 'active_collab_connection', $connection->id,
                    'connection_created', [], $this->safeSnapshot($connection, true), null, $connection->id,
                    [(string) $data['api_token']]);
            }

            return $connection;
        }, 3);
    }

    /** @param array{name: string, base_url: string, api_token?: string, active: bool} $data */
    public function update(string $id, array $data, ?int $actorId = null): ActiveCollabConnection
    {
        return DB::transaction(function () use ($id, $data, $actorId): ActiveCollabConnection {
            $connection = ActiveCollabConnection::query()->whereKey($id)->lockForUpdate()->firstOrFail();
            if ($connection->base_url !== $data['base_url'] && $connection->integrations()->exists()) {
                throw ValidationException::withMessages([
                    'connection.base_url' => 'URL используемого подключения нельзя менять. Создайте новое подключение.',
                ]);
            }

            $credentialChanged = array_key_exists('api_token', $data);
            $secrets = [];
            try {
                $secrets[] = (string) $connection->api_token;
            } catch (\Throwable) { // @phpstan-ignore catch.neverThrown (Encrypted Eloquent cast may fail at runtime.)
                // The connection will be rotated with the value provided below.
            }
            if ($credentialChanged) {
                $secrets[] = (string) $data['api_token'];
            }
            $before = $this->safeSnapshot($connection);
            $connection->fill($data)->save();
            if ($actorId !== null && ($before !== $this->safeSnapshot($connection)
                || $credentialChanged)) {
                $after = $this->safeSnapshot($connection);
                $after['credential_changed'] = $credentialChanged;
                $before['credential_changed'] = false;
                $this->audit->recordMutation($actorId, 'active_collab_connection', $connection->id,
                    'connection_updated', $before, $after, null, $connection->id, $secrets);
            }

            return $connection->refresh();
        }, 3);
    }

    public function deleteUnused(string $id, ?int $actorId = null): void
    {
        DB::transaction(function () use ($id, $actorId): void {
            $connection = ActiveCollabConnection::query()->whereKey($id)->lockForUpdate()->firstOrFail();
            if ($connection->integrations()->exists()) {
                throw ValidationException::withMessages([
                    'connection' => 'Подключение используется интеграциями. Отключите его вместо удаления.',
                ]);
            }
            if ($actorId !== null) {
                $this->audit->recordMutation($actorId, 'active_collab_connection', $connection->id,
                    'connection_deleted', $this->safeSnapshot($connection), [], null, $connection->id);
            }
            $connection->delete();
        }, 3);
    }

    /** @return array<string, mixed> */
    private function safeSnapshot(ActiveCollabConnection $connection, bool $credentialChanged = false): array
    {
        return [
            'name' => $connection->name,
            'base_url' => $connection->base_url,
            'active' => (bool) $connection->active,
            'credential_changed' => $credentialChanged,
        ];
    }

    /** @return array{connection_id: string, action: 'assigned'|'reused'} */
    public function migrateLegacyIntegration(
        string $integrationId,
        string $baseUrl,
        string $apiToken,
        string $connectionName,
    ): array {
        return DB::transaction(function () use ($integrationId, $baseUrl, $apiToken, $connectionName): array {
            $integration = ActiveCollabIntegration::query()->whereKey($integrationId)->lockForUpdate()->firstOrFail();
            if ($integration->connection_id !== null) {
                return ['connection_id' => $integration->connection_id, 'action' => 'reused'];
            }

            $connection = null;
            foreach (ActiveCollabConnection::query()->where('base_url', $baseUrl)->lockForUpdate()->get() as $candidate) {
                try {
                    if (hash_equals((string) $candidate->api_token, $apiToken)) {
                        $connection = $candidate;
                        break;
                    }
                } catch (\Throwable) {
                    // An unreadable legacy candidate cannot be proven equivalent.
                }
            }

            $action = 'reused';
            if ($connection === null) {
                $connection = ActiveCollabConnection::query()->create([
                    'name' => $connectionName,
                    'base_url' => $baseUrl,
                    'api_token' => $apiToken,
                    'active' => true,
                ]);
                $action = 'assigned';
            }

            $integration->connection_id = $connection->id;
            $integration->save();

            return ['connection_id' => $connection->id, 'action' => $action];
        }, 3);
    }
}
