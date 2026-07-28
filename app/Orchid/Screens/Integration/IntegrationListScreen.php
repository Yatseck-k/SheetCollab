<?php

declare(strict_types=1);

namespace App\Orchid\Screens\Integration;

use App\Models\ActiveCollabIntegration;
use App\Orchid\Layouts\Integration\IntegrationListLayout;
use Illuminate\Http\Request;
use Orchid\Screen\Actions\Link;
use Orchid\Screen\Screen;
use Orchid\Support\Facades\Toast;

class IntegrationListScreen extends Screen
{
    /** @return iterable<string, mixed> */
    public function query(): iterable
    {
        return [
            'integrations' => ActiveCollabIntegration::orderBy('created_at', 'desc')->paginate(),
        ];
    }

    #[\Override]
    public function name(): ?string
    {
        return 'Интеграции ActiveCollab';
    }

    #[\Override]
    public function description(): ?string
    {
        return 'Связки Google Sheets ↔ ActiveCollab проект';
    }

    #[\Override]
    public function commandBar(): iterable
    {
        return [
            Link::make('Добавить интеграцию')
                ->icon('bs.plus-circle')
                ->route('platform.integrations.create'),
        ];
    }

    public function layout(): iterable
    {
        return [
            IntegrationListLayout::class,
        ];
    }

    public function remove(Request $request): void
    {
        ActiveCollabIntegration::findOrFail($request->get('id'))->delete();
        Toast::info('Интеграция удалена.');
    }
}
