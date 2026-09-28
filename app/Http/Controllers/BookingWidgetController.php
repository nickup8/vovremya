<?php

namespace App\Http\Controllers;

use App\Models\MasterService;
use App\Models\TrackingLink;
use App\Models\User;
use App\Services\Booking\AttributionService;
use App\Services\Booking\AvailabilityService;
use App\Services\Booking\BookingService;
use App\Services\Booking\FreeWindowPublicationService;
use App\Services\VkLinkTokenService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;

class BookingWidgetController extends Controller
{
    public function __construct(
        private BookingService $bookingService,
        private AvailabilityService $availabilityService,
        private AttributionService $attributionService,
        private FreeWindowPublicationService $publicationService,
    ) {}

    public function show(string $slug, Request $request)
    {
        $master = User::where('master_slug', $slug)
            ->visibleInWidget()
            ->firstOrFail();

        $master->load(['masterServices' => fn ($q) => $q->with('catalog')
            ->where('is_active', true)
            ->whereHas('catalog', fn ($c) => $c->where('is_active', true))]);

        $selectedServiceId = $request->query('service_id');
        $selectedDate = $request->query('date') ?? Carbon::today()->toDateString();

        $service = $selectedServiceId
            ? $master->masterServices->firstWhere('id', $selectedServiceId)
            : null;

        // Resolve publication context
        $publicationContext = null;
        $fwToken = $request->query('fw');

        if ($fwToken) {
            $pub = $this->publicationService->resolveForMaster($fwToken, $master);

            if (! $pub) {
                // Token not found or expired — show expired state, not fallback
                $publicationContext = [
                    'active' => true,
                    'expired' => true,
                    'token' => $fwToken,
                ];
            } else {
                $publicationContext = [
                    'active' => true,
                    'expired' => false,
                    'token' => $pub->token,
                    'mode' => $pub->mode,
                ];

                // In service mode, force service selection to the published service
                if ($pub->mode === 'service' && $pub->master_service_id) {
                    $selectedServiceId = $pub->master_service_id;
                    $service = $master->masterServices->firstWhere('id', $selectedServiceId);
                }
            }
        }

        // Filter available slots through publication
        $rawSlots = ($service && $selectedDate)
            ? $this->bookingService->getAvailableSlots($master, $service, $selectedDate)
            : [];

        $availableSlots = $rawSlots;

        if ($publicationContext && ! $publicationContext['expired'] && isset($pub) && $rawSlots) {
            $availableSlots = $this->publicationService->filterSlots($pub, $selectedDate, $rawSlots);
        }

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
            'availableSlots' => Inertia::optional(fn () => $availableSlots),
            'selectedDate' => $selectedDate,
            'selectedServiceId' => $service ? $selectedServiceId : null,
            'maxBotName' => config('services.max.bot_name'),
            'publicationContext' => $publicationContext,
        ]);
    }

    /**
     * Tracking link redirect: /r/{token}.
     *
     * Active  → set attribution, 302 to widget.
     * Disabled → 302 to widget without touching attribution.
     * Missing  → 404.
     */
    public function redirect(string $token, Request $request): RedirectResponse
    {
        $link = TrackingLink::where('token', $token)->firstOrFail();

        $master = User::where('id', $link->master_id)
            ->visibleInWidget()
            ->firstOrFail();

        if ($link->is_active) {
            $this->attributionService->captureByToken($master, $link, $request);
        }

        return redirect()->route('booking.widget', $master->master_slug);
    }

    public function availableDates(Request $request, string $slug): JsonResponse
    {
        $master = User::where('master_slug', $slug)
            ->visibleInWidget()
            ->firstOrFail();

        $validated = $request->validate([
            'service_id' => 'required|string',
            'year' => 'required|integer|min:2020|max:2030',
            'month' => 'required|integer|min:1|max:12',
            'fw' => 'nullable|string',
        ]);

        $service = $master->masterServices()
            ->where('is_active', true)
            ->whereHas('catalog', fn ($c) => $c->where('is_active', true))
            ->find($validated['service_id']);

        if (! $service) {
            return response()->json(['dates' => [], 'publicationExpired' => false]);
        }

        // Check publication expiration early
        $fwToken = $validated['fw'] ?? null;
        if ($fwToken) {
            $pub = $this->publicationService->resolveForMaster($fwToken, $master);
            if (! $pub) {
                return response()->json(['dates' => [], 'publicationExpired' => true]);
            }
        }

        $realDates = $this->availabilityService->getAvailableDates(
            $master,
            $validated['year'],
            $validated['month'],
            $service->effective_duration,
        );

        // Apply publication filter if present
        if ($fwToken && isset($pub)) {
            $realDates = $this->publicationService->filterAvailableDates($pub, $realDates);
        }

        return response()->json([
            'dates' => $realDates,
            'publicationExpired' => false,
        ]);
    }

    public function store(Request $request, string $slug): JsonResponse
    {
        $master = User::where('master_slug', $slug)
            ->visibleInWidget()
            ->firstOrFail();

        $validated = $request->validate([
            'service_id' => 'required|exists:master_service,id',
            'date' => 'required|date_format:Y-m-d',
            'time' => 'required|date_format:H:i',
            'provider' => 'required|in:telegram,max,admin,vk',
            'fw' => 'nullable|string',
        ]);

        $service = $master->masterServices()
            ->where('is_active', true)
            ->whereHas('catalog', fn ($c) => $c->where('is_active', true))
            ->find($validated['service_id']);

        if (! $service) {
            return response()->json([
                'message' => 'Эта услуга больше недоступна для записи.',
                'errors' => ['service_id' => 'Эта услуга больше недоступна для записи.'],
            ], 422);
        }

        // Publication enforcement
        $fwToken = $validated['fw'] ?? null;
        if ($fwToken) {
            $pub = $this->publicationService->resolveForMaster($fwToken, $master);

            if (! $pub) {
                return response()->json([
                    'message' => 'Срок публикации истёк.',
                    'errors' => ['time' => 'Срок публикации истёк.'],
                ], 422);
            }

            // Service mode: selected service must match publication service
            if ($pub->mode === 'service' && $pub->master_service_id !== $validated['service_id']) {
                return response()->json([
                    'message' => 'Услуга не соответствует публикации.',
                    'errors' => ['service_id' => 'Услуга не соответствует публикации.'],
                ], 422);
            }

            // Verify date is in publication
            if ($validated['date'] < $pub->date_from->format('Y-m-d') || $validated['date'] > $pub->date_to->format('Y-m-d')) {
                return response()->json([
                    'message' => 'Дата не входит в публикацию.',
                    'errors' => ['date' => 'Дата не входит в публикацию.'],
                ], 422);
            }

            // Verify time is in publication
            if (! $this->publicationService->assertBookable($pub, $validated['date'], $validated['time'], $service->effective_duration)) {
                return response()->json([
                    'message' => 'Время не входит в опубликованное расписание.',
                    'errors' => ['time' => 'Время не входит в опубликованное расписание.'],
                ], 422);
            }
        }

        // Standard real availability check
        $isAvailable = $this->bookingService->validateSlot(
            $master,
            $service,
            $validated['date'],
            $validated['time'],
        );

        if (! $isAvailable) {
            return response()->json([
                'errors' => ['time' => 'Этот слот недоступен.'],
            ], 422);
        }

        // Резолвим источник в момент создания записи: ссылка заново валидируется
        // (принадлежность мастеру + активность). Если между click и booking её отключили —
        // источник не фиксируется, запись классифицируется как Direct.
        $trackingLinkId = $this->attributionService->resolveLinkId($master, $request);

        $appointment = $this->bookingService->createAppointment(
            $master,
            $service,
            $validated['date'],
            $validated['time'],
            $validated['provider'],
            clientId: null,
            trackingLinkId: $trackingLinkId,
        );

        $telegramBotName = config('services.telegram.bot_name', 'vovremia_bot');
        $maxBotName = config('services.max.bot_name');

        $vkLinkToken = null;
        $vkUrl = null;
        if ($validated['provider'] === 'vk') {
            $vkAppId = config('services.vk.app_id');
            if (empty($vkAppId)) {
                abort(500, 'VK app_id not configured');
            }
            $vkLinkToken = app(VkLinkTokenService::class)->create($appointment->id);
            $vkUrl = 'https://vk.com/app' . $vkAppId . '#' . $vkLinkToken;
        }

        return response()->json([
            'success' => true,
            'appointment_id' => $appointment->id,
            'telegram_url' => "https://t.me/{$telegramBotName}?start=book_{$appointment->id}",
            'max_url' => $maxBotName ? "https://max.ru/{$maxBotName}?start=book_{$appointment->id}" : null,
            'vk_link_token' => $vkLinkToken,
            'vk_url' => $vkUrl,
        ]);
    }
}
