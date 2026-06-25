<?php

namespace App\Http\Controllers\Admin;

use App\Enums\AppointmentStatus;
use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Models\Client;
use Illuminate\Support\Facades\DB;
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

        $appointmentsByClient = Appointment::where('master_id', $master->id)
            ->with('service')
            ->get()
            ->groupBy('client_id');

        $clients = Client::where('user_id', $master->id)
            ->get()
            ->map(function (Client $client) use ($appointmentsByClient) {
                $appointments = $appointmentsByClient->get($client->id, collect());
                $completed = $appointments->where('status', AppointmentStatus::Completed);

                return [
                    'id' => $client->id,
                    'name' => $client->name,
                    'phone' => $client->phone,
                    'telegram_id' => $client->telegram_id,
                    'max_id' => $client->max_id,
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
