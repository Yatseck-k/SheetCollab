<?php

declare(strict_types=1);

namespace App\Orchid\Screens\Export;

use App\Models\ActiveCollabTaskExport;
use App\Orchid\Layouts\Export\ExportAttemptsLayout;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Orchid\Screen\Actions\Link;
use Orchid\Screen\Screen;
use Orchid\Support\Facades\Layout;

class ExportAttemptsScreen extends Screen
{
    /** @return iterable<string, mixed> */
    public function query(Request $request, ActiveCollabTaskExport $export): iterable
    {
        Gate::forUser($request->user())->authorize('view', $export);

        return [
            'export' => $export->only(['id', 'external_id', 'task_id', 'last_attempt_status', 'binding_status']) + [
                'integration_name' => $export->integration?->name,
            ],
            'attempts' => $export->attempts()->orderByDesc('started_at')->orderByDesc('id')
                ->paginate(50, ['id', 'action', 'status', 'started_at', 'finished_at', 'http_status', 'error_code']),
        ];
    }

    public function name(): ?string
    {
        return 'История попыток';
    }

    public function description(): ?string
    {
        return 'Журнал одной записи; связь с задачей и результат последней попытки показаны отдельно.';
    }

    public function commandBar(): iterable
    {
        $routeExport = request()->route('export');
        $exportId = $routeExport instanceof ActiveCollabTaskExport ? $routeExport->id : $routeExport;

        return [
            Link::make('К общей истории')->route('platform.exports'),
            Link::make('Проверить связь / восстановить')->route('platform.exports.recovery', ['export' => $exportId])
                ->canSee(Gate::allows('recover', ActiveCollabTaskExport::class)),
        ];
    }

    public function layout(): iterable
    {
        return [Layout::view('admin.export-attempts'), ExportAttemptsLayout::class];
    }
}
