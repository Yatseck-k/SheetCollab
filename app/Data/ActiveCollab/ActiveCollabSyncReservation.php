<?php

declare(strict_types=1);

namespace App\Data\ActiveCollab;

readonly class ActiveCollabSyncReservation
{
    public function __construct(
        public string $exportId,
        public ?string $operationId,
        public ?string $conflictCode = null,
    ) {}
}
