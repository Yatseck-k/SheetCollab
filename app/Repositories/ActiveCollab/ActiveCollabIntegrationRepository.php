<?php

declare(strict_types=1);

namespace App\Repositories\ActiveCollab;

use App\Models\ActiveCollabIntegration;

class ActiveCollabIntegrationRepository
{
    public function findBySpreadsheetId(string $spreadsheetId): ?ActiveCollabIntegration
    {
        return ActiveCollabIntegration::where('spreadsheet_id', $spreadsheetId)
            ->where('active', true)
            ->first();
    }
}
