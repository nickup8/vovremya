<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Models\Appointment;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class BookingsController extends Controller
{
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

        if ($client && $appointment->client_id === $client->id) {
            $appointment->update(['status' => 'cancelled']);
        }

        return back();
    }
}
