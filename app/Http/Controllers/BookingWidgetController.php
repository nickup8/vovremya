<?php

namespace App\Http\Controllers;

use App\Models\Service;
use App\Models\User;
use App\Services\Booking\BookingService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;

class BookingWidgetController extends Controller
{
    public function __construct(
        private BookingService $bookingService,
    ) {}

    public function show(string $slug, Request $request)
    {
        $master = User::where('master_slug', $slug)
            ->where('is_master', true)
            ->firstOrFail()
            ->load('services');

        $selectedServiceId = $request->query('service_id');
        $selectedDate = $request->query('date', Carbon::today()->toDateString());

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
            'selectedServiceId' => $selectedServiceId ? (int) $selectedServiceId : null,
        ]);
    }

    public function store(Request $request, string $slug)
    {
        $master = User::where('master_slug', $slug)
            ->where('is_master', true)
            ->firstOrFail();

        $validated = $request->validate([
            'service_id' => 'required|exists:services,id',
            'date' => 'required|date_format:Y-m-d',
            'time' => 'required|date_format:H:i',
            'provider' => 'required|in:telegram,max',
        ]);

        $service = Service::findOrFail($validated['service_id']);

        $isAvailable = $this->bookingService->validateSlot(
            $master,
            $service,
            $validated['date'],
            $validated['time'],
        );

        if (! $isAvailable) {
            return back()->withErrors([
                'time' => 'Этот слот недоступен. Он может быть занят, попадать на обеденный перерыв или находиться за пределами рабочего времени.',
            ])->withInput();
        }

        $appointment = $this->bookingService->createAppointment(
            $master,
            $service,
            $validated['date'],
            $validated['time'],
            $validated['provider'],
        );

        session(['pending_telegram_appointment_id' => $appointment->id]);

        $botLink = $validated['provider'] === 'telegram'
            ? 'https://t.me/vovremia_bot?start=book_'.$appointment->id
            : 'https://max.ru/bot/vovremia_bot?start=book_'.$appointment->id;

        return redirect()->away($botLink);
    }
}
