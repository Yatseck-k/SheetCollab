<?php

declare(strict_types=1);

namespace App\Data\ActiveCollab;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

readonly class ActiveCollabTaskData
{
    public function __construct(
        public string $externalId,
        public string $spreadsheetId,
        public string $sheetId,
        public int $rowNumber,
        public ?string $severity,
        public ?string $priority,
        public ?string $device,
        public ?string $resolution,
        public string $environment,
        public string $section,
        public string $problemDescription,
        public ?string $screenshotUrl,
        public ?string $comment,
        public ?string $taskType,
        public ?string $responsible,
        public ?string $reporter,
        public ?string $sheetStatus,
        public ?string $recordUuid = null,
    ) {}

    public static function normalizeSpreadsheetId(string $value): string
    {
        $parts = parse_url(trim($value));
        if (($parts['scheme'] ?? null) === 'https' && ($parts['host'] ?? null) === 'docs.google.com'
            && preg_match('#^/spreadsheets/d/([a-zA-Z0-9_-]+)(?:/|$)#', $parts['path'] ?? '', $matches) === 1) {
            return $matches[1];
        }

        return trim($value);
    }

    /** @param array<string, mixed> $data */
    public static function fromRequest(array $data): self
    {
        $spreadsheetId = $data['spreadsheet_id'];
        $sheetId = $data['sheet_id'];
        $rowNumber = (int) $data['row_number'];

        return new self(
            externalId: "{$spreadsheetId}:{$sheetId}:{$rowNumber}",
            spreadsheetId: $spreadsheetId,
            sheetId: $sheetId,
            rowNumber: $rowNumber,
            severity: $data['severity'] ?? null,
            priority: $data['priority'] ?? null,
            device: $data['device'] ?? null,
            resolution: $data['resolution'] ?? null,
            environment: $data['environment'],
            section: $data['section'],
            problemDescription: $data['description'],
            screenshotUrl: $data['screenshot'] ?? null,
            comment: $data['comment'] ?? null,
            taskType: $data['task_type'] ?? null,
            responsible: $data['responsible'] ?? null,
            reporter: $data['reporter'] ?? null,
            sheetStatus: $data['status'] ?? null,
            recordUuid: empty($data['record_uuid']) ? null : strtolower($data['record_uuid']),
        );
    }

    /** @param array<string, mixed> $source */
    public static function fromStoredPayload(array $source): self
    {
        $input = $source;
        $input['description'] = $source['problem_description'] ?? null;
        $input['screenshot'] = $source['screenshot_url'] ?? null;
        $input['status'] = $source['sheet_status'] ?? null;
        $rules = [];
        foreach (['spreadsheet_id', 'sheet_id', 'environment', 'section', 'description'] as $field) {
            $rules[$field] = ['required', 'string'];
            if (is_string($input[$field] ?? null) && str_contains($input[$field], '[REDACTED]')) {
                throw ValidationException::withMessages(['source' => 'Источник очищен или неполон. Отправьте корректные данные из Sheets.']);
            }
        }
        $rules['record_uuid'] = ['nullable', 'uuid'];
        $rules['row_number'] = ['required', 'integer', 'min:1'];
        foreach (['severity', 'priority', 'device', 'resolution', 'screenshot', 'comment', 'task_type', 'responsible', 'reporter', 'status'] as $field) {
            $rules[$field] = ['nullable', 'string'];
        }

        $rules['screenshot'] = ['nullable', 'url'];

        return self::fromRequest(Validator::make($input, $rules)->validate());
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'record_uuid' => $this->recordUuid,
            'external_id' => $this->externalId,
            'spreadsheet_id' => $this->spreadsheetId,
            'sheet_id' => $this->sheetId,
            'row_number' => $this->rowNumber,
            'severity' => $this->severity,
            'priority' => $this->priority,
            'device' => $this->device,
            'resolution' => $this->resolution,
            'environment' => $this->environment,
            'section' => $this->section,
            'problem_description' => $this->problemDescription,
            'screenshot_url' => $this->screenshotUrl,
            'comment' => $this->comment,
            'task_type' => $this->taskType,
            'responsible' => $this->responsible,
            'reporter' => $this->reporter,
            'sheet_status' => $this->sheetStatus,
        ];
    }
}
