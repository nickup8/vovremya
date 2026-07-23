<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\Workspace;
use App\Services\Booking\BookingService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

class StudioBookingController extends Controller
{
    public function __construct(
        private BookingService $bookingService,
    ) {}

    public function show(string $slug, Request $request): InertiaResponse|RedirectResponse
    {
        $workspace = Workspace::where('slug', $slug)->firstOrFail();

        $masterSlug = $request->query('master');

        if ($masterSlug) {
            return $this->showMasterBooking($workspace, $masterSlug, $request);
        }

        return $this->showMastersList($workspace);
    }

    private function showMastersList(Workspace $workspace): InertiaResponse
    {
        $masters = $workspace->users()
            ->where('is_master', true)
            ->select('id', 'name', 'master_slug', 'avatar_url', 'specialty')
            ->get();

        return Inertia::render('booking/studio', [
            'workspace' => [
                'id' => $workspace->id,
                'name' => $workspace->name,
                'slug' => $workspace->slug,
            ],
            'masters' => $masters,
        ]);
    }

    private function showMasterBooking(Workspace $workspace, string $masterSlug, Request $request): InertiaResponse|RedirectResponse
    {
        $master = User::where('workspace_id', $workspace->id)
            ->where('master_slug', $masterSlug)
            ->where('is_master', true)
            ->first();

        if (! $master) {
            return redirect()->route('studio.booking', ['slug' => $workspace->slug]);
        }

        $master->load('services');

        $selectedServiceId = $request->query('service_id');
        $selectedDate = $request->query('date') ?? Carbon::today()->toDateString();

        $service = $selectedServiceId ? $master->services()->find($selectedServiceId) : null;

        $availableSlots = $this->bookingService->getAvailableSlots(
            $master,
            $service,
            $selectedDate
        );

        return Inertia::render('booking/widget', [
            'master' => [
                'name' => $master->name,
                'specialty' => $master->specialty,
                'address' => $master->address,
                'avatar_url' => $master->avatar_url,
                'master_slug' => $master->master_slug,
            ],
            'services' => $master->services->map(fn ($s) => [
                'id' => $s->id,
                'title' => $s->title,
                'price' => (float) $s->price,
                'duration_minutes' => $s->duration_minutes,
            ]),
            'availableSlots' => $availableSlots,
            'selectedDate' => $selectedDate,
            'selectedServiceId' => $service ? $selectedServiceId : null,
            'maxBotName' => config('services.max.bot_name'),
            'studioSlug' => $workspace->slug,
        ]);
    }
}
