<?php

declare(strict_types=1);

namespace App\Data\ActiveCollab;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

readonly class ActiveCollabRecordUuidManifest
{
    public const int MAX_BYTES = 4194304;

    /** @param list<array{record_uuid: string, sheet_id: string, row_number: int, task_id: ?int, task_url: ?string}> $records */
    private function __construct(public string $spreadsheetId, public array $records) {}

    /** @param array<string, mixed> $input */
    public static function fromArray(array $input): self
    {
        // Reject oversized/ill-shaped collections before wildcard rule expansion.
        Validator::make(['manifest' => $input], [
            'manifest' => ['required', 'array:version,spreadsheet_id,records'],
            'manifest.version' => ['required', 'integer', 'in:1'],
            'manifest.spreadsheet_id' => ['required', 'string', 'max:255'],
            'manifest.records' => ['present', 'array', 'list', 'max:20000'],
        ])->validate();
        $records = [];
        $seen = [];
        foreach ($input['records'] as $record) {
            if (is_array($record) && is_string($record['record_uuid'] ?? null)) {
                $record['record_uuid'] = strtolower(trim($record['record_uuid']));
            }
            // Validate one row at a time: wildcard expansion/distinct across 20,000 rows is quadratic.
            $record = Validator::make(['record' => $record], [
                'record' => ['required', 'array:record_uuid,sheet_id,row_number,task_id,task_url'],
                'record.record_uuid' => ['required', 'uuid'],
                'record.sheet_id' => ['required', 'string', 'max:64'],
                'record.row_number' => ['required', 'integer', 'min:1'],
                'record.task_id' => ['nullable', 'integer', 'min:1'],
                'record.task_url' => ['nullable', 'url:http,https', 'max:2048'],
            ])->validate()['record'];
            if (isset($seen[$record['record_uuid']])) {
                throw ValidationException::withMessages(['records' => 'Duplicate record UUID.']);
            }
            $seen[$record['record_uuid']] = true;
            $records[] = [
                'record_uuid' => $record['record_uuid'], 'sheet_id' => $record['sheet_id'], 'row_number' => (int) $record['row_number'],
                'task_id' => isset($record['task_id']) ? (int) $record['task_id'] : null, 'task_url' => $record['task_url'] ?? null,
            ];
        }

        return new self($input['spreadsheet_id'], $records);
    }
}
