<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Models\Appointment;
use Inertia\Inertia;

class BookingsController extends Controller
{
    public function index()
    {
        $user = auth()->user();

        $appointments = $user->clientAppointments()
            ->with(['master', 'service'])
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

    public function cancel(Appointment $appointment)
    {
        $appointment->update(['status' => 'cancelled']);

        return back();
    }
}
