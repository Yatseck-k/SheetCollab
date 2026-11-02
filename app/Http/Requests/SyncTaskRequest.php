<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Validation\Validator;

class SyncTaskRequest extends StoreActiveCollabTaskRequest
{
    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        $rules = parent::rules();
        $rules['record_uuid'] = ['required', 'uuid'];
        $rules['row_number'][] = 'max:10000000';
        foreach (['spreadsheet_id' => 255, 'sheet_id' => 64, 'environment' => 100, 'section' => 255, 'description' => 16000,
            'comment' => 8000, 'screenshot' => 2048, 'severity' => 100, 'priority' => 100, 'status' => 100, 'task_type' => 100,
            'device' => 255, 'resolution' => 255, 'responsible' => 255, 'reporter' => 255] as $key => $max) {
            $rules[$key][] = 'max:'.$max;
        }
        $rules['screenshot'] = ['nullable', 'url:http,https', 'max:2048'];

        return $rules;
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function ($validator): void {
            if (array_diff(array_keys($this->all()), array_keys($this->rules())) !== []) {
                $validator->errors()->add('request', 'Unexpected request fields.');
            }
        });
    }
}
