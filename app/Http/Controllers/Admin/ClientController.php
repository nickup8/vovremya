<?php

namespace App\Http\Controllers\Admin;

use App\Enums\AppointmentStatus;
use App\Enums\UserRole;
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

    public function index(Request $request)
    {
        $user = auth()->user();

        if (! $user->role->canManageTeam() && ! $user->is_master) {
            return redirect()->route('client.bookings')
                ->with('error', 'У вас нет доступа к базе клиентов.');
        }

        if ($user->role->canManageTeam()) {
            $masterIds = $user->workspace
                ? $user->workspace->users()->where('is_master', true)->pluck('id')->toArray()
                : [$user->id];
        } else {
            $masterIds = [$user->id];
        }

        $perPage = (int) $request->query('per_page', 20);

        $clients = Client::whereIn('user_id', $masterIds)
            ->with(['appointments.service'])
            ->withCount(['appointments as total_bookings' => function ($q) {
                $q->where('status', '!=', AppointmentStatus::Cancelled);
            }])
            ->withCount(['appointments as completed_bookings' => function ($q) {
                $q->where('status', AppointmentStatus::Paid);
            }])
            ->withMax('appointments as last_visit', 'start_time')
            ->get()
            ->map(function (Client $client) {
                $ltv = $client->appointments
                    ->where('status', AppointmentStatus::Paid)
                    ->sum(fn ($a) => $a->service?->price ?? 0);

                return [
                    'id' => $client->id,
                    'name' => $client->name,
                    'phone' => $client->phone,
                    'user_id' => $client->user_id,
                    'total_bookings' => $client->total_bookings,
                    'completed_bookings' => $client->completed_bookings,
                    'ltv' => (float) $ltv,
                    'last_visit' => $client->last_visit,
                ];
            });

        $total = $clients->count();
        $clients = $clients->slice(($request->query('page', 1) - 1) * $perPage, $perPage)->values();

        return Inertia::render('admin/clients', [
            'clients' => [
                'data' => $clients,
                'total' => $total,
                'per_page' => $perPage,
                'current_page' => (int) $request->query('page', 1),
            ],
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
