<?php

declare(strict_types=1);

namespace App\Exceptions;

class ActiveCollabRequestException extends \RuntimeException
{
    public function __construct(
        public readonly ?int $httpStatus = null,
        public readonly bool $timeout = false,
        public readonly string $category = 'protocol_invalid',
    ) {
        parent::__construct('ActiveCollab request did not produce a confirmed result.');
    }
}
