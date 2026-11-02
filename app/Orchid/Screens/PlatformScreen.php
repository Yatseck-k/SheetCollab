<?php

declare(strict_types=1);

namespace App\Orchid\Screens;

use App\Models\ActiveCollabIntegration;
use App\Repositories\AdminDashboardRepository;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Orchid\Screen\Action;
use Orchid\Screen\Screen;
use Orchid\Support\Facades\Layout;

class PlatformScreen extends Screen
{
    /**
     * Fetch data to be displayed on the screen.
     *
     * @return iterable<string, mixed>
     */
    public function query(Request $request, AdminDashboardRepository $dashboard): iterable
    {
        Gate::authorize('viewAny', ActiveCollabIntegration::class);

        return $dashboard->forUser(
            $request->user()->getAuthIdentifier(),
            Gate::allows('manageConnection', ActiveCollabIntegration::class),
        );
    }

    /**
     * The name of the screen displayed in the header.
     */
    #[\Override]
    public function name(): ?string
    {
        return 'SheetCollab';
    }

    /**
     * Display header description.
     */
    #[\Override]
    public function description(): ?string
    {
        return 'Состояние синхронизации и доступных интеграций';
    }

    /**
     * The screen's action buttons.
     *
     * @return Action[]
     */
    #[\Override]
    public function commandBar(): iterable
    {
        return [];
    }

    /**
     * The screen's layout elements.
     *
     * @return \Orchid\Screen\Layout[]
     */
    public function layout(): iterable
    {
        return [
            Layout::view('admin.dashboard'),
        ];
    }
}
