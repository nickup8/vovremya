<?php

namespace App\Http\Controllers;

use App\Models\Service;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Booking\BookingService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
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

        $serviceTitle = $request->query('service');
        $masterSlug = $request->query('master');

        if ($masterSlug) {
            return $this->showMasterBooking($workspace, $masterSlug, $request);
        }

        if ($serviceTitle) {
            return $this->showServiceMasters($workspace, $serviceTitle);
        }

        return $this->showServicesList($workspace);
    }

    private function showServicesList(Workspace $workspace): InertiaResponse
    {
        $masters = $workspace->users()
            ->where('is_master', true)
            ->where('is_bookable', true)
            ->whereNotNull('master_slug')
            ->where('master_slug', '!=', '')
            ->select('id')
            ->get();

        $masterIds = $masters->pluck('id');

        $services = Service::whereIn('user_id', $masterIds)
            ->select('title', 'price', 'duration_minutes')
            ->get();

        $grouped = $services->groupBy('title')->map(function (Collection $group, string $title) {
            return [
                'title' => $title,
                'masters_count' => $group->count(),
                'price_from' => (float) $group->min('price'),
                'duration_min' => (int) $group->min('duration_minutes'),
                'duration_max' => (int) $group->max('duration_minutes'),
            ];
        })->values();

        return Inertia::render('booking/studio-services', [
            'workspace' => [
                'id' => $workspace->id,
                'name' => $workspace->name,
                'slug' => $workspace->slug,
            ],
            'services' => $grouped,
        ]);
    }

    private function showServiceMasters(Workspace $workspace, string $serviceTitle): InertiaResponse|RedirectResponse
    {
        $masters = $workspace->users()
            ->where('is_master', true)
            ->where('is_bookable', true)
            ->whereNotNull('master_slug')
            ->where('master_slug', '!=', '')
            ->select('id', 'name', 'master_slug', 'avatar_url', 'specialty')
            ->get();

        $masterIds = $masters->pluck('id');

        $services = Service::whereIn('user_id', $masterIds)
            ->where('title', $serviceTitle)
            ->get()
            ->keyBy('user_id');

        $mastersWithService = $masters->filter(fn ($master) => $services->has($master->id))
            ->map(fn ($master) => [
                'id' => $master->id,
                'name' => $master->name,
                'master_slug' => $master->master_slug,
                'avatar_url' => $master->avatar_url,
                'specialty' => $master->specialty,
                'price' => (float) $services[$master->id]->price,
                'duration_minutes' => (int) $services[$master->id]->duration_minutes,
                'service_id' => $services[$master->id]->id,
            ])
            ->values();

        if ($mastersWithService->isEmpty()) {
            return redirect()->route('studio.booking', ['slug' => $workspace->slug]);
        }

        return Inertia::render('booking/studio', [
            'workspace' => [
                'id' => $workspace->id,
                'name' => $workspace->name,
                'slug' => $workspace->slug,
            ],
            'service' => $serviceTitle,
            'masters' => $mastersWithService,
        ]);
    }

    private function showMasterBooking(Workspace $workspace, string $masterSlug, Request $request): InertiaResponse|RedirectResponse
    {
        $master = User::where('workspace_id', $workspace->id)
            ->where('master_slug', $masterSlug)
            ->where('is_master', true)
            ->where('is_bookable', true)
            ->whereNotNull('master_slug')
            ->first();

        if (! $master) {
            abort(404, 'Мастер не найден в студии.');
        }

        $master->load('services');

        $serviceTitle = $request->query('service');
        $selectedServiceId = $request->query('service_id');
        $selectedDate = $request->query('date') ?? Carbon::today()->toDateString();

        // Если передан ?service={title}, предвыбираем услугу по названию
        $preselectedServiceId = null;
        if ($serviceTitle && ! $selectedServiceId) {
            $preselected = $master->services()->where('title', $serviceTitle)->first();
            if ($preselected) {
                $preselectedServiceId = $preselected->id;
                $selectedServiceId = $preselected->id;
            }
        }

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
            'preselectedServiceId' => $preselectedServiceId,
        ]);
    }
}
