<?php

declare(strict_types=1);

namespace App\Exceptions;

class IntegrationNotFoundException extends \RuntimeException
{
    public function __construct(string $spreadsheetId)
    {
        parent::__construct("Для таблицы \"{$spreadsheetId}\" не настроена интеграция с ActiveCollab.");
    }
}
