<?php

namespace App\Http\Controllers\Admin;

use App\Enums\AppointmentStatus;
use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Models\Client;
use App\Services\Booking\BookingService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class ClientController extends Controller
{
    public function __construct(
        private BookingService $bookingService,
    ) {}
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
                $completed = $appointments->where('status', AppointmentStatus::Paid);

                return [
                    'id' => $client->id,
                    'name' => $client->name,
                    'phone' => $client->phone,
                    'telegram_id' => $client->telegram_id,
                    'max_id' => $client->max_id,
                    'is_blocked' => $client->is_blocked,
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

    public function store(Request $request)
    {
        $master = auth()->user();

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'phone' => 'required|string|max:20',
            'notes' => 'nullable|string|max:1000',
        ]);

        $existing = Client::where('user_id', $master->id)
            ->where('phone', $validated['phone'])
            ->first();

        if ($existing) {
            $existing->update(['name' => $validated['name']]);

            return back()->with('success', 'Клиент обновлён (номер уже был в базе)');
        }

        $master->clients()->create([
            'name' => $validated['name'],
            'phone' => $validated['phone'],
            'notes' => $validated['notes'] ?? null,
        ]);

        return back()->with('success', 'Клиент добавлен');
    }

    public function update(Request $request, Client $client)
    {
        $this->authorize('update', $client);

        $master = auth()->user();

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'phone' => 'required|string|max:20',
        ]);

        $duplicate = Client::where('user_id', $master->id)
            ->where('phone', $validated['phone'])
            ->where('id', '!=', $client->id)
            ->first();

        if ($duplicate) {
            return back()->withErrors([
                'phone' => 'Клиент с таким номером уже существует',
            ])->withInput();
        }

        $client->update($validated);

        return back()->with('success', 'Данные клиента обновлены');
    }

    public function toggleBlock(Client $client)
    {
        $this->authorize('update', $client);

        $wasBlocked = $client->is_blocked;
        $client->update(['is_blocked' => ! $wasBlocked]);

        // При блокировке — отменяем все активные записи клиента
        if (! $wasBlocked && $client->is_blocked) {
            $activeStatuses = [
                AppointmentStatus::Booked,
                AppointmentStatus::PendingPayment,
                AppointmentStatus::Prepaid,
            ];

            $client->appointments()
                ->whereIn('status', $activeStatuses)
                ->each(fn (Appointment $appointment) => $this->bookingService->cancel($appointment));
        }

        return back()->with('success', $client->is_blocked
            ? "Клиент {$client->name} заблокирован."
            : "Клиент {$client->name} разблокирован."
        );
    }
}
