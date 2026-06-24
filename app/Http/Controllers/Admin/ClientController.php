<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Inertia\Inertia;

class ClientController extends Controller
{
    public function index()
    {
        $master = auth()->user();

        if (! $master->is_master) {
            return redirect()->route('client.bookings')
                ->with('error', 'У вас нет профиля мастера.');
        }

        $clients = User::whereHas('clientAppointments', function ($q) use ($master) {
            $q->where('master_id', $master->id);
        })->get()->map(function ($client) use ($master) {
            $appointments = $client->clientAppointments()
                ->where('master_id', $master->id)
                ->with('service')
                ->get();

            $completed = $appointments->where('status', 'completed');

            return [
                'id' => $client->id,
                'name' => $client->name,
                'phone' => $client->phone,
                'avatar_url' => $client->avatar_url,
                'total_bookings' => $appointments->count(),
                'completed_bookings' => $completed->count(),
                'ltv' => (float) $completed->sum(fn ($app) => $app->service ? $app->service->price : 0),
                'last_visit' => $appointments->max('start_time'),
            ];
        });

        return Inertia::render('admin/clients', [
            'clients' => $clients,
        ]);
    }
}
