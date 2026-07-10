<?php

namespace App\Http\Controllers\Client;

use App\Enums\AppointmentStatus;
use App\Exceptions\InvalidStatusTransitionException;
use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Services\Booking\BookingService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class BookingsController extends Controller
{
    public function __construct(
        private BookingService $bookingService,
    ) {}

    public function index(): Response
    {
        $client = Auth::guard('client')->user();

        if (! $client) {
            return Inertia::render('client/bookings', [
                'appointments' => collect(),
            ]);
        }

        $appointments = Appointment::where('client_id', $client->id)
            ->with(['master', 'service'])
            ->orderByDesc('start_time')
            ->get()
            ->map(fn (Appointment $a) => [
                'id' => $a->id,
                'master_name' => $a->master->name,
                'master_specialty' => $a->master->specialty,
                'service' => $a->service->title,
                'date' => $a->start_time->format('Y-m-d'),
                'time' => $a->start_time->format('H:i'),
                'price' => (float) $a->service->price,
                'status' => $a->status,
            ]);

        return Inertia::render('client/bookings', [
            'appointments' => $appointments,
        ]);
    }

    public function cancel(Appointment $appointment): RedirectResponse
    {
        $client = Auth::guard('client')->user();

        if (! $client || $appointment->client_id !== $client->id) {
            return back();
        }

        if ($appointment->status === AppointmentStatus::Cancelled) {
            return back();
        }

        try {
            $this->bookingService->cancel($appointment);
        } catch (InvalidStatusTransitionException) {
            return back()->withErrors(['error' => 'Невозможно отменить данную запись']);
        }

        return back();
    }
}
