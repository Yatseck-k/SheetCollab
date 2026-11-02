<?php

declare(strict_types=1);

namespace App\Services\ActiveCollab;

use App\Data\ActiveCollab\ActiveCollabAccessContext;
use App\Repositories\ActiveCollab\ActiveCollabIntegrationTokenRepository;
use App\Repositories\ActiveCollab\ActiveCollabRepository;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class ActiveCollabIntegrationTokenService
{
    public function __construct(private readonly ActiveCollabIntegrationTokenRepository $repository,
        private readonly ActiveCollabRepository $client, private readonly ActiveCollabDataSanitizer $sanitizer) {}

    /** @return list<array<string, mixed>> */
    public function catalog(ActiveCollabAccessContext $access, string $kind): array
    {
        $integration = $this->repository->integration($access);
        $data = match ($kind) {
            'task-lists' => $this->client->taskListsForIntegration($integration),
            'users' => $this->client->usersForIntegration($integration),
            'labels' => $this->client->labelsForIntegration($integration),
            default => throw new \InvalidArgumentException('Unknown catalog.'),
        };
        $this->repository->integration($access, true);

        return array_values($this->sanitizer->clean($data, $integration, $access->secrets()));
    }

    /** @return array<string, mixed> */
    public function manage(string $action, string $integrationId, int $actorId, string $reason, ?string $name = null, ?string $tokenId = null): array
    {
        Validator::make(['action' => $action, 'integration' => $integrationId, 'actor' => $actorId, 'reason' => $reason, 'name' => $name, 'token_id' => $tokenId], [
            'action' => ['required', Rule::in(['issue', 'list', 'revoke', 'enable-legacy', 'disable-legacy', 'legacy-status'])],
            'integration' => [Rule::requiredIf($action !== 'legacy-status'), 'nullable', 'uuid'], 'actor' => ['required', 'integer', 'min:1'],
            'reason' => ['required', 'string', 'min:10', 'max:1000'],
            'name' => [Rule::requiredIf($action === 'issue'), 'nullable', 'string', 'min:1', 'max:100'],
            'token_id' => [Rule::requiredIf($action === 'revoke'), 'nullable', 'uuid'],
        ])->validate();

        return $this->repository->manage($action, $integrationId, $actorId, $reason, $name, $tokenId);
    }
}
