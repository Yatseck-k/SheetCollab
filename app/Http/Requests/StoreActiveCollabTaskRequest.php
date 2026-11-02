<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Data\ActiveCollab\ActiveCollabTaskData;
use Illuminate\Foundation\Http\FormRequest;

class StoreActiveCollabTaskRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            'record_uuid' => ['nullable', 'uuid'],
            'spreadsheet_id' => ['required', 'string'],
            'sheet_id' => ['required', 'string'],
            'row_number' => ['required', 'integer', 'min:1'],
            'environment' => ['required', 'string'],
            'section' => ['required', 'string'],
            'description' => ['required', 'string'],
            'severity' => ['nullable', 'string'],
            'priority' => ['nullable', 'string'],
            'device' => ['nullable', 'string'],
            'resolution' => ['nullable', 'string'],
            'screenshot' => ['nullable', 'url'],
            'comment' => ['nullable', 'string'],
            'task_type' => ['nullable', 'string'],
            'responsible' => ['nullable', 'string'],
            'reporter' => ['nullable', 'string'],
            'status' => ['nullable', 'string'],
        ];
    }

    #[\Override]
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('record_uuid'))) {
            $this->merge(['record_uuid' => strtolower(trim($this->input('record_uuid'))) ?: null]);
        }

        if (is_string($this->input('spreadsheet_id'))) {
            $this->merge(['spreadsheet_id' => ActiveCollabTaskData::normalizeSpreadsheetId($this->input('spreadsheet_id'))]);
        }
    }
}
