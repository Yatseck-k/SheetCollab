<?php

declare(strict_types=1);

namespace App\Exceptions;

class TaskAlreadyExistsException extends \RuntimeException
{
    public function __construct(
        public readonly int $taskId,
        public readonly string $taskUrl,
    ) {
        parent::__construct('Задача для этой строки уже создана.');
    }
}
