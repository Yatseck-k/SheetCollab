<?php

declare(strict_types=1);

namespace App\Exceptions;

class ActiveCollabRequestException extends \RuntimeException
{
    /** @param array<string, mixed> $safeResponse */
    public function __construct(
        public readonly ?int $httpStatus = null,
        public readonly bool $timeout = false,
        public readonly string $category = 'protocol_invalid',
        public readonly array $safeResponse = [],
    ) {
        parent::__construct('ActiveCollab request did not produce a confirmed result.');
    }
}
