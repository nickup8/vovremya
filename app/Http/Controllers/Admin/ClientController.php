<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Models\Client;
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

        $clients = Client::where('user_id', $master->id)
            ->get()
            ->map(function (Client $client) use ($master) {
                $appointments = Appointment::where('client_id', $client->id)
                    ->where('master_id', $master->id)
                    ->with('service')
                    ->get();

                $completed = $appointments->where('status', 'completed');

                return [
                    'id' => $client->id,
                    'name' => $client->name,
                    'phone' => $client->phone,
                    'telegram_id' => $client->telegram_id,
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
