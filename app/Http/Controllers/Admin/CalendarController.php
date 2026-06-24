<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Appointment;
use Illuminate\Http\Request;
use Inertia\Inertia;

class CalendarController extends Controller
{
    public function index()
    {
        $master = auth()->user();

        if (! $master->is_master) {
            return redirect()->route('client.bookings')
                ->with('error', 'У вас нет профиля мастера.');
        }

        $appointments = $master->masterAppointments()
            ->with(['client', 'service'])
            ->get()
            ->map(fn (Appointment $a) => [
                'id' => $a->id,
                'client_name' => $a->client->name,
                'client_phone' => $a->client->phone,
                'service' => $a->service->title,
                'duration' => $a->service->duration_minutes,
                'price' => (float) $a->service->price,
                'time' => $a->start_time->format('H:i'),
                'date' => $a->start_time->format('Y-m-d'),
                'status' => $a->status,
            ]);

        return Inertia::render('admin/calendar', [
            'appointments' => $appointments,
        ]);
    }

    public function updateStatus(Request $request, Appointment $appointment)
    {
        $validated = $request->validate([
            'status' => 'required|in:pending_client,confirmed,completed,no_show,cancelled',
            'start_time' => 'sometimes|date',
        ]);

        $appointment->update(
            array_filter($validated, fn ($v) => $v !== null)
        );

        return back();
    }
}
