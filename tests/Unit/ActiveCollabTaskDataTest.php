<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Data\ActiveCollab\ActiveCollabTaskData;
use PHPUnit\Framework\TestCase;

class ActiveCollabTaskDataTest extends TestCase
{
    public function test_from_request_builds_external_id_as_spreadsheet_sheet_row(): void
    {
        $data = ActiveCollabTaskData::fromRequest($this->minimalRequest([
            'spreadsheet_id' => 'abc123',
            'sheet_id' => 'sheet0',
            'row_number' => 8,
        ]));

        $this->assertSame('abc123:sheet0:8', $data->externalId);
        $this->assertSame('abc123', $data->spreadsheetId);
        $this->assertSame('sheet0', $data->sheetId);
        $this->assertSame(8, $data->rowNumber);
    }

    public function test_from_request_casts_row_number_string_to_int(): void
    {
        $data = ActiveCollabTaskData::fromRequest($this->minimalRequest(['row_number' => '15']));

        $this->assertSame(15, $data->rowNumber);
        $this->assertIsInt($data->rowNumber);
    }

    public function test_from_request_maps_description_key_to_problem_description(): void
    {
        $data = ActiveCollabTaskData::fromRequest($this->minimalRequest([
            'description' => 'Product card fails to load',
        ]));

        $this->assertSame('Product card fails to load', $data->problemDescription);
    }

    public function test_from_request_maps_screenshot_and_status_keys(): void
    {
        $data = ActiveCollabTaskData::fromRequest($this->minimalRequest([
            'screenshot' => 'https://example.com/shot.png',
            'status' => 'open',
        ]));

        $this->assertSame('https://example.com/shot.png', $data->screenshotUrl);
        $this->assertSame('open', $data->sheetStatus);
    }

    public function test_from_request_optional_fields_are_null_when_absent(): void
    {
        $data = ActiveCollabTaskData::fromRequest($this->minimalRequest());

        $this->assertNull($data->severity);
        $this->assertNull($data->priority);
        $this->assertNull($data->device);
        $this->assertNull($data->resolution);
        $this->assertNull($data->screenshotUrl);
        $this->assertNull($data->comment);
        $this->assertNull($data->taskType);
        $this->assertNull($data->responsible);
        $this->assertNull($data->reporter);
        $this->assertNull($data->sheetStatus);
    }

    public function test_to_array_contains_all_expected_keys_with_correct_values(): void
    {
        $data = ActiveCollabTaskData::fromRequest($this->minimalRequest([
            'spreadsheet_id' => 'sheet1',
            'sheet_id' => 'tab2',
            'row_number' => 5,
            'severity' => 'major',
            'description' => 'Button broken',
        ]));

        $array = $data->toArray();

        $this->assertSame('sheet1:tab2:5', $array['external_id']);
        $this->assertSame('sheet1', $array['spreadsheet_id']);
        $this->assertSame('tab2', $array['sheet_id']);
        $this->assertSame(5, $array['row_number']);
        $this->assertSame('major', $array['severity']);
        $this->assertSame('Button broken', $array['problem_description']);
        $this->assertArrayHasKey('screenshot_url', $array);
        $this->assertArrayHasKey('sheet_status', $array);
    }

    // ── helper ───────────────────────────────────────────────────────────────────

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function minimalRequest(array $overrides = []): array
    {
        return array_merge([
            'spreadsheet_id' => 'sheet-id',
            'sheet_id' => 'tab-id',
            'row_number' => 1,
            'environment' => 'prod',
            'section' => 'Home',
            'description' => 'Bug report',
        ], $overrides);
    }
}
