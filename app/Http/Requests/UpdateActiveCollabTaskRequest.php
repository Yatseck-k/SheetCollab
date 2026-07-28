<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateActiveCollabTaskRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
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
        $spreadsheetId = (string) $this->input('spreadsheet_id', '');

        if (preg_match('#/spreadsheets/d/([a-zA-Z0-9_-]+)#', $spreadsheetId, $matches) === 1) {
            $this->merge([
                'spreadsheet_id' => $matches[1],
            ]);
        }
    }
}
