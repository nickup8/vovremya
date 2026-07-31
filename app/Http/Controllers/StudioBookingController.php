<?php

namespace App\Http\Controllers;

use App\Models\MasterService;
use App\Models\ServiceCatalog;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Booking\BookingService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
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

        if (! $workspace->activeSubscription()) {
            $owner = $workspace->owner;

            if ($owner && $owner->master_slug) {
                return redirect()->route('booking.widget', ['master' => $owner->master_slug]);
            }

            Log::warning('Studio owner has no master_slug for redirect', [
                'workspace_id' => $workspace->id,
                'owner_id' => $owner?->id,
            ]);

            abort(404, 'Студия недоступна для бронирования.');
        }

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

        $catalogs = ServiceCatalog::where('workspace_id', $workspace->id)
            ->whereHas('masterServices', fn ($q) => $q
                ->whereIn('master_id', $masterIds)->where('is_active', true))
            ->with(['masterServices' => fn ($q) => $q
                ->whereIn('master_id', $masterIds)->where('is_active', true)])
            ->get();

        $grouped = $catalogs->map(function (ServiceCatalog $cat) {
            $activeMs = $cat->masterServices;
            $prices = $activeMs->map->effective_price->filter(fn ($p) => $p !== null);
            $durations = $activeMs->map->effective_duration->filter(fn ($d) => $d !== null);

            return [
                'title' => $cat->title,
                'masters_count' => $activeMs->count(),
                'price_from' => (float) ($prices->min() ?? 0),
                'duration_min' => (int) ($durations->min() ?? 0),
                'duration_max' => (int) ($durations->max() ?? 0),
            ];
        })->filter(fn ($s) => $s['masters_count'] > 0)->values();

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

        $masterServices = MasterService::whereIn('master_id', $masterIds)
            ->where('is_active', true)
            ->whereHas('catalog', fn ($q) => $q->where('title', $serviceTitle))
            ->with('catalog')
            ->get()
            ->keyBy('master_id');

        $mastersWithService = $masters
            ->filter(fn ($master) => $masterServices->has($master->id))
            ->map(fn ($master) => [
                'id' => $master->id,
                'name' => $master->name,
                'master_slug' => $master->master_slug,
                'avatar_url' => $master->avatar_url,
                'specialty' => $master->specialty,
                'price' => (float) $masterServices[$master->id]->effective_price,
                'duration_minutes' => (int) $masterServices[$master->id]->effective_duration,
                'service_id' => $masterServices[$master->id]->id,
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

        $master->load(['masterServices' => fn ($q) => $q->with('catalog')->where('is_active', true)]);

        $serviceTitle = $request->query('service');
        $selectedServiceId = $request->query('service_id');
        $selectedDate = $request->query('date') ?? Carbon::today()->toDateString();

        $preselectedServiceId = null;
        if ($serviceTitle && ! $selectedServiceId) {
            $preselected = $master->masterServices->first(
                fn (MasterService $ms) => $ms->catalog?->title === $serviceTitle
            );
            if ($preselected) {
                $preselectedServiceId = $preselected->id;
                $selectedServiceId = $preselected->id;
            }
        }

        $service = $selectedServiceId ? $master->masterServices()->find($selectedServiceId) : null;

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
            'services' => $master->masterServices->map(fn (MasterService $s) => [
                'id' => $s->id,
                'title' => $s->catalog?->title ?? '',
                'price' => (float) $s->effective_price,
                'duration_minutes' => $s->effective_duration,
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
