<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Data\ActiveCollab\ActiveCollabRecordUuidManifest;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ActiveCollabRecordUuidManifestTest extends TestCase
{
    public function test_empty_manifest_and_uuid_normalization(): void
    {
        $this->assertSame([], ActiveCollabRecordUuidManifest::fromArray(['version' => 1, 'spreadsheet_id' => 'sheet', 'records' => []])->records);
        $manifest = ActiveCollabRecordUuidManifest::fromArray($this->manifest());
        $this->assertSame('ae091ce2-282a-43ad-a258-85a2b8eecfc4', $manifest->records[0]['record_uuid']);
    }

    /** @param array<string, mixed> $input */
    #[DataProvider('invalidManifests')]
    public function test_invalid_or_duplicate_manifest_is_rejected(array $input): void
    {
        $this->expectException(ValidationException::class);
        ActiveCollabRecordUuidManifest::fromArray($input);
    }

    /** @return array<string, array{array<string, mixed>}> */
    public static function invalidManifests(): array
    {
        $record = ['record_uuid' => 'ae091ce2-282a-43ad-a258-85a2b8eecfc4', 'sheet_id' => '0', 'row_number' => 8];
        $base = ['version' => 1, 'spreadsheet_id' => 'sheet', 'records' => [$record]];

        return [
            'version' => [array_replace($base, ['version' => 2])],
            'unknown keys' => [$base + ['token' => 'must-not-be-accepted']],
            'duplicate uuid' => [array_replace($base, ['records' => [$record, array_replace($record, ['row_number' => 9])]])],
            'uuid' => [array_replace($base, ['records' => [array_replace($record, ['record_uuid' => 'invalid'])]])],
            'row' => [array_replace($base, ['records' => [array_replace($record, ['row_number' => 0])]])],
            'url' => [array_replace($base, ['records' => [array_replace($record, ['task_url' => 'javascript:alert(1)'])]])],
            'business fields' => [array_replace($base, ['records' => [$record + ['description' => 'private']]])],
            'too many' => [array_replace($base, ['records' => array_fill(0, 20001, $record)])],
        ];
    }

    /** @return array<string, mixed> */
    private function manifest(): array
    {
        return ['version' => 1, 'spreadsheet_id' => 'sheet', 'records' => [
            ['record_uuid' => 'AE091CE2-282A-43AD-A258-85A2B8EECFC4', 'sheet_id' => '0', 'row_number' => 8],
        ]];
    }
}
