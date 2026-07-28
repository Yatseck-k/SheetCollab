<?php

declare(strict_types=1);

namespace App\Orchid\Screens\Export;

use App\Models\ActiveCollabIntegration;
use App\Models\ActiveCollabTaskExport;
use App\Orchid\Layouts\Export\ExportListLayout;
use Illuminate\Http\Request;
use Orchid\Screen\Fields\Select;
use Orchid\Screen\Screen;
use Orchid\Support\Facades\Layout;

class ExportListScreen extends Screen
{
    /** @return iterable<string, mixed> */
    public function query(Request $request): iterable
    {
        $query = ActiveCollabTaskExport::with('integration')
            ->orderBy('updated_at', 'desc');

        if ($integrationId = $request->get('integration_id')) {
            $query->where('integration_id', $integrationId);
        }

        if ($status = $request->get('status')) {
            $query->where('status', $status);
        }

        return [
            'exports' => $query->paginate(50),
        ];
    }

    #[\Override]
    public function name(): ?string
    {
        return 'История экспортов';
    }

    #[\Override]
    public function description(): ?string
    {
        return 'Все задачи, отправленные из Google Sheets в ActiveCollab';
    }

    #[\Override]
    public function commandBar(): iterable
    {
        return [];
    }

    public function layout(): iterable
    {
        $integrations = ActiveCollabIntegration::pluck('name', 'id')->toArray();

        return [
            Layout::rows([
                Select::make('integration_id')
                    ->title('Фильтр по интеграции')
                    ->options(['' => 'Все'] + $integrations)
                    ->empty('Все', ''),

                Select::make('status')
                    ->title('Фильтр по статусу')
                    ->options([
                        '' => 'Все',
                        'pending' => 'Ожидание',
                        'created' => 'Создана',
                        'updated' => 'Обновлена',
                        'failed' => 'Ошибка',
                    ])
                    ->empty('Все', ''),
            ]),

            ExportListLayout::class,
        ];
    }
}
