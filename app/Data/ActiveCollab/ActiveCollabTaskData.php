<?php

declare(strict_types=1);

namespace App\Data\ActiveCollab;

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
    ) {}

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
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
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
