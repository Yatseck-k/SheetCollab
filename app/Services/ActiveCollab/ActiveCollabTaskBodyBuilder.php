<?php

declare(strict_types=1);

namespace App\Services\ActiveCollab;

use App\Data\ActiveCollab\ActiveCollabTaskData;

class ActiveCollabTaskBodyBuilder
{
    public function buildName(ActiveCollabTaskData $data): string
    {
        $parts = [];

        if ($data->environment !== '' && $data->environment !== '0') {
            $parts[] = "[{$data->environment}]";
        }
        if ($data->severity) {
            $parts[] = "[{$data->severity}]";
        }
        if ($data->section !== '' && $data->section !== '0') {
            $parts[] = "{$data->section}:";
        }

        $parts[] = mb_substr($data->problemDescription, 0, 100);

        return implode(' ', $parts);
    }

    public function buildBody(ActiveCollabTaskData $data): string
    {
        $description = nl2br(htmlspecialchars($data->problemDescription, ENT_QUOTES));

        $screenshotHtml = $data->screenshotUrl
            ? sprintf(
                '<h3>Скриншоты</h3><p><a href="%s">%s</a></p>',
                htmlspecialchars($data->screenshotUrl, ENT_QUOTES),
                htmlspecialchars($data->screenshotUrl, ENT_QUOTES),
            )
            : '';

        $commentHtml = $data->comment
            ? '<h3>Комментарий</h3><p>'.nl2br(htmlspecialchars($data->comment, ENT_QUOTES)).'</p>'
            : '';

        $sourceUrl = htmlspecialchars('https://docs.google.com/spreadsheets/d/'.rawurlencode($data->spreadsheetId).'/edit#gid='.rawurlencode($data->sheetId).'&range=A'.$data->rowNumber, ENT_QUOTES);

        return trim(<<<HTML
<h3>Описание проблемы</h3>
<p>{$description}</p>

<h3>Параметры</h3>
<ul>
  <li><strong>Серьезность:</strong> {$this->val($data->severity)}</li>
  <li><strong>Приоритет:</strong> {$this->val($data->priority)}</li>
  <li><strong>Устройство:</strong> {$this->val($data->device)}</li>
  <li><strong>Разрешение:</strong> {$this->val($data->resolution)}</li>
  <li><strong>Стенд:</strong> {$this->val($data->environment)}</li>
  <li><strong>Раздел:</strong> {$this->val($data->section)}</li>
  <li><strong>Тип задач:</strong> {$this->val($data->taskType)}</li>
  <li><strong>Ответственный:</strong> {$this->val($data->responsible)}</li>
  <li><strong>Постановщик:</strong> {$this->val($data->reporter)}</li>
  <li><strong>Статус в таблице:</strong> {$this->val($data->sheetStatus)}</li>
</ul>

{$screenshotHtml}
{$commentHtml}

<hr>
<p>Источник: <a href="{$sourceUrl}">Google Sheets</a>, строка {$data->rowNumber}.</p>
HTML);
    }

    private function val(?string $value): string
    {
        return htmlspecialchars($value ?: '—', ENT_QUOTES);
    }
}
