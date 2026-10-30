<?php

declare(strict_types=1);

namespace App\Exceptions;

class TaskBindingConflictException extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('Привязка задачи не найдена или не соответствует исходной записи.');
    }
}
