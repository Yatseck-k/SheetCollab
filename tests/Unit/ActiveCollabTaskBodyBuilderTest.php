<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Data\ActiveCollab\ActiveCollabTaskData;
use App\Services\ActiveCollab\ActiveCollabTaskBodyBuilder;
use PHPUnit\Framework\TestCase;

class ActiveCollabTaskBodyBuilderTest extends TestCase
{
    private ActiveCollabTaskBodyBuilder $builder;

    protected function setUp(): void
    {
        $this->builder = new ActiveCollabTaskBodyBuilder();
    }

    // ── buildName ────────────────────────────────────────────────────────────────

    public function test_build_name_includes_environment_severity_section_description(): void
    {
        $name = $this->builder->buildName($this->makeData());

        $this->assertSame('[prod] [critical] Checkout: Button broken', $name);
    }

    public function test_build_name_omits_severity_when_null(): void
    {
        $name = $this->builder->buildName($this->makeData(['severity' => null]));

        $this->assertSame('[prod] Checkout: Button broken', $name);
    }

    public function test_build_name_omits_environment_when_empty_string(): void
    {
        $name = $this->builder->buildName($this->makeData(['environment' => '']));

        $this->assertSame('[critical] Checkout: Button broken', $name);
    }

    public function test_build_name_truncates_description_at_100_chars(): void
    {
        $long = str_repeat('x', 150);
        $name = $this->builder->buildName($this->makeData(['problemDescription' => $long]));

        $this->assertSame('[prod] [critical] Checkout: '.str_repeat('x', 100), $name);
        $this->assertSame(28 + 100, mb_strlen($name));
    }

    public function test_build_name_without_environment_and_severity_uses_section_and_description(): void
    {
        $name = $this->builder->buildName($this->makeData([
            'environment' => '',
            'severity' => null,
        ]));

        $this->assertSame('Checkout: Button broken', $name);
    }

    // ── buildBody ────────────────────────────────────────────────────────────────

    public function test_build_body_contains_problem_description_and_parameters_sections(): void
    {
        $body = $this->builder->buildBody($this->makeData());

        $this->assertStringContainsString('<h3>Описание проблемы</h3>', $body);
        $this->assertStringContainsString('Button broken', $body);
        $this->assertStringContainsString('<h3>Параметры</h3>', $body);
        $this->assertStringContainsString('<strong>Серьезность:</strong> critical', $body);
        $this->assertStringContainsString('<strong>Стенд:</strong> prod', $body);
        $this->assertStringContainsString('<strong>Раздел:</strong> Checkout', $body);
    }

    public function test_build_body_omits_screenshot_section_when_null(): void
    {
        $body = $this->builder->buildBody($this->makeData(['screenshotUrl' => null]));

        $this->assertStringNotContainsString('<h3>Скриншоты</h3>', $body);
    }

    public function test_build_body_includes_screenshot_section_when_provided(): void
    {
        $body = $this->builder->buildBody($this->makeData(['screenshotUrl' => 'https://example.com/shot.png']));

        $this->assertStringContainsString('<h3>Скриншоты</h3>', $body);
        $this->assertStringContainsString('https://example.com/shot.png', $body);
    }

    public function test_build_body_omits_comment_section_when_null(): void
    {
        $body = $this->builder->buildBody($this->makeData(['comment' => null]));

        $this->assertStringNotContainsString('<h3>Комментарий</h3>', $body);
    }

    public function test_build_body_includes_comment_section_when_provided(): void
    {
        $body = $this->builder->buildBody($this->makeData(['comment' => 'Reproduced always']));

        $this->assertStringContainsString('<h3>Комментарий</h3>', $body);
        $this->assertStringContainsString('Reproduced always', $body);
    }

    public function test_build_body_html_escapes_description_to_prevent_xss(): void
    {
        $body = $this->builder->buildBody($this->makeData([
            'problemDescription' => '<script>alert("xss")</script>',
        ]));

        $this->assertStringContainsString('&lt;script&gt;', $body);
        $this->assertStringNotContainsString('<script>', $body);
    }

    public function test_build_body_html_escapes_screenshot_url(): void
    {
        $body = $this->builder->buildBody($this->makeData([
            'screenshotUrl' => 'https://example.com/path?a=1&b=2',
        ]));

        $this->assertStringContainsString('a=1&amp;b=2', $body);
        $this->assertStringNotContainsString('a=1&b=2"', $body);
    }

    public function test_build_body_html_escapes_comment(): void
    {
        $body = $this->builder->buildBody($this->makeData([
            'comment' => '<b>bold</b> & "quoted"',
        ]));

        $this->assertStringContainsString('&lt;b&gt;', $body);
        $this->assertStringContainsString('&amp;', $body);
    }

    public function test_build_body_applies_nl2br_to_multiline_description(): void
    {
        $body = $this->builder->buildBody($this->makeData([
            'problemDescription' => "line1\nline2",
        ]));

        $this->assertStringContainsString('<br />', $body);
    }

    public function test_build_body_shows_dash_for_null_optional_fields(): void
    {
        $body = $this->builder->buildBody($this->makeData([
            'priority' => null,
            'device' => null,
            'resolution' => null,
            'taskType' => null,
            'responsible' => null,
            'reporter' => null,
            'sheetStatus' => null,
        ]));

        $this->assertStringContainsString('<strong>Приоритет:</strong> —', $body);
        $this->assertStringContainsString('<strong>Устройство:</strong> —', $body);
        $this->assertStringContainsString('<strong>Разрешение:</strong> —', $body);
        $this->assertStringContainsString('<strong>Тип задач:</strong> —', $body);
        $this->assertStringContainsString('<strong>Ответственный:</strong> —', $body);
    }

    public function test_build_body_escapes_parameter_values_and_google_source_link(): void
    {
        $body = $this->builder->buildBody($this->makeData([
            'spreadsheetId' => 'sheet&<id>', 'sheetId' => 'tab"<x>', 'environment' => '<img src=x>',
            'section' => 'A & B', 'priority' => '"high"',
        ]));

        $this->assertStringContainsString('&lt;img src=x&gt;', $body);
        $this->assertStringContainsString('A &amp; B', $body);
        $this->assertStringContainsString('&quot;high&quot;', $body);
        $this->assertStringContainsString('sheet%26%3Cid%3E', $body);
        $this->assertStringContainsString('tab%22%3Cx%3E', $body);
        $this->assertStringNotContainsString('<img src=x>', $body);
    }

    public function test_build_body_includes_row_number_in_source_footer(): void
    {
        $body = $this->builder->buildBody($this->makeData(['rowNumber' => 42]));

        $this->assertStringContainsString('строка 42', $body);
        $this->assertStringContainsString('<hr>', $body);
    }

    // ── helper ───────────────────────────────────────────────────────────────────

    /** @param array<string, mixed> $overrides */
    private function makeData(array $overrides = []): ActiveCollabTaskData
    {
        $d = array_merge([
            'externalId' => 'sheet:tab:8',
            'spreadsheetId' => 'sheet',
            'sheetId' => 'tab',
            'rowNumber' => 8,
            'severity' => 'critical',
            'priority' => 'high',
            'device' => 'desktop',
            'resolution' => '1920x1080',
            'environment' => 'prod',
            'section' => 'Checkout',
            'problemDescription' => 'Button broken',
            'screenshotUrl' => null,
            'comment' => null,
            'taskType' => 'backend',
            'responsible' => 'Tony Yats',
            'reporter' => 'QA',
            'sheetStatus' => 'new',
        ], $overrides);

        return new ActiveCollabTaskData(
            externalId: $d['externalId'],
            spreadsheetId: $d['spreadsheetId'],
            sheetId: $d['sheetId'],
            rowNumber: $d['rowNumber'],
            severity: $d['severity'],
            priority: $d['priority'],
            device: $d['device'],
            resolution: $d['resolution'],
            environment: $d['environment'],
            section: $d['section'],
            problemDescription: $d['problemDescription'],
            screenshotUrl: $d['screenshotUrl'],
            comment: $d['comment'],
            taskType: $d['taskType'],
            responsible: $d['responsible'],
            reporter: $d['reporter'],
            sheetStatus: $d['sheetStatus'],
        );
    }
}
