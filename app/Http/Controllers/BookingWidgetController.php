<?php

namespace App\Http\Controllers;

use App\Models\Service;
use App\Models\User;
use App\Services\Booking\AvailabilityService;
use App\Services\Booking\BookingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;

class BookingWidgetController extends Controller
{
    public function __construct(
        private BookingService $bookingService,
        private AvailabilityService $availabilityService,
    ) {}

    public function show(string $slug, Request $request)
    {
        $master = User::where('master_slug', $slug)
            ->where('is_master', true)
            ->firstOrFail()
            ->load('services');

        $selectedServiceId = $request->query('service_id');
        $selectedDate = $request->query('date') ?? Carbon::today()->toDateString();

        $availableSlots = $this->bookingService->getAvailableSlots(
            $master,
            $selectedServiceId ? Service::find($selectedServiceId) : null,
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
            'selectedServiceId' => $selectedServiceId ?: null,
        ]);
    }

    public function availableDates(Request $request, string $slug): JsonResponse
    {
        $master = User::where('master_slug', $slug)
            ->where('is_master', true)
            ->firstOrFail();

        $validated = $request->validate([
            'service_id' => 'required|string',
            'year' => 'required|integer|min:2020|max:2030',
            'month' => 'required|integer|min:1|max:12',
        ]);

        $service = Service::find($validated['service_id']);

        if (! $service || $service->user_id !== $master->id) {
            return response()->json(['dates' => []]);
        }

        $dates = $this->availabilityService->getAvailableDates(
            $master,
            $validated['year'],
            $validated['month'],
            $service->duration_minutes,
        );

        return response()->json(['dates' => $dates]);
    }

    public function store(Request $request, string $slug): JsonResponse
    {
        $master = User::where('master_slug', $slug)
            ->where('is_master', true)
            ->firstOrFail();

        $validated = $request->validate([
            'service_id' => 'required|exists:services,id',
            'date' => 'required|date_format:Y-m-d',
            'time' => 'required|date_format:H:i',
            'provider' => 'required|in:telegram',
        ]);

        $service = Service::findOrFail($validated['service_id']);

        if ($service->user_id !== $master->id) {
            return response()->json(['message' => 'Услуга не найдена.'], 404);
        }

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

        // Создаём запись без client_id (черновик) — клиент подтвердит номер в боте
        $appointment = $this->bookingService->createAppointment(
            $master,
            $service,
            $validated['date'],
            $validated['time'],
            $validated['provider'],
            null, // client_id будет заполнен после подтверждения в боте
        );

        $botName = config('services.telegram.bot_name', 'vovremia_bot');
        $telegramUrl = "https://t.me/{$botName}?start=book_{$appointment->id}";

        return response()->json([
            'success' => true,
            'telegram_url' => $telegramUrl,
            'appointment_id' => $appointment->id,
        ]);
    }
}
