<?php

declare(strict_types=1);

namespace App\Orchid\Screens\Guide;

use Orchid\Screen\Screen;
use Orchid\Support\Facades\Layout;

class GuideScreen extends Screen
{
    /** @return iterable<string, mixed> */
    public function query(): iterable
    {
        return [];
    }

    #[\Override]
    public function name(): ?string
    {
        return 'Инструкция';
    }

    #[\Override]
    public function description(): ?string
    {
        return 'Пошаговая настройка Google Sheets, ActiveCollab и их интеграции через SheetCollab.';
    }

    #[\Override]
    public function commandBar(): iterable
    {
        return [];
    }

    public function layout(): iterable
    {
        return [
            Layout::view('admin.guide'),
        ];
    }
}
