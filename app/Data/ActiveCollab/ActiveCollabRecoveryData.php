<?php

declare(strict_types=1);

namespace App\Data\ActiveCollab;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

readonly class ActiveCollabRecoveryData
{
    private function __construct(
        public string $action,
        public string $requestId,
        public ?string $expectedOperationId,
        public ?string $expectedStatus,
        public ?string $expectedBinding,
        public ?int $candidateTaskId,
        public ?string $sourceAttemptId,
        public string $reason,
    ) {}

    /** @param array<string, mixed> $input */
    public static function fromInput(array $input, string $action): self
    {
        $values = Validator::make(array_merge($input, ['action' => $action]), [
            'action' => ['required', Rule::in(['bind', 'verify', 'confirm_absence', 'replace'])],
            'request_id' => ['required', 'uuid'],
            'expected_operation_id' => ['present', 'nullable', 'uuid'],
            'expected_status' => ['present', 'nullable', Rule::in(['idle', 'running', 'succeeded', 'failed', 'unknown'])],
            'expected_binding' => ['present', 'nullable', Rule::in(['unbound', 'bound', 'remote_missing'])],
            'candidate_task_id' => [Rule::requiredIf($action === 'bind'), 'nullable', 'integer', 'min:1'],
            'source_attempt_id' => ['nullable', 'uuid'],
            'reason' => ['required', 'string', 'min:10', 'max:1000'],
            'acknowledged' => in_array($action, ['confirm_absence', 'replace'], true) ? ['required', 'accepted'] : ['sometimes', 'boolean'],
        ])->validate();

        return new self($action, $values['request_id'], $values['expected_operation_id'], $values['expected_status'],
            $values['expected_binding'], $action === 'bind' ? (int) $values['candidate_task_id'] : null,
            $values['source_attempt_id'] ?? null, $values['reason']);
    }
}
