<?php

namespace App\Http\Controllers;

use App\Models\Appointment;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;

class BookingWidgetController extends Controller
{
    public function show(string $slug)
    {
        $master = User::where('master_slug', $slug)
            ->where('is_master', true)
            ->firstOrFail()
            ->load('services');

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
        ]);
    }

    public function store(Request $request, string $slug)
    {
        $master = User::where('master_slug', $slug)
            ->where('is_master', true)
            ->firstOrFail();

        $validated = $request->validate([
            'service_id' => 'required|exists:services,id',
            'start_time' => 'required|date',
            'phone' => 'required|string|min:10',
        ]);

        $phone = $validated['phone'];

        $client = User::firstOrCreate(
            ['phone' => $phone],
            ['name' => 'Клиент '.$phone]
        );

        $appointment = Appointment::create([
            'master_id' => $master->id,
            'client_id' => $client->id,
            'service_id' => $validated['service_id'],
            'start_time' => $validated['start_time'],
            'status' => 'pending_client',
        ]);

        return back()->with('appointment_id', $appointment->id);
    }
}
