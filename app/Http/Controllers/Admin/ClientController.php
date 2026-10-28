<?php

namespace App\Http\Controllers\Admin;

use App\Enums\AppointmentStatus;
use App\Exceptions\AmbiguousClientMatchException;
use App\Exceptions\DuplicateClientPhoneException;
use App\Exceptions\PastAppointmentException;
use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Models\Client;
use App\Services\Booking\BookingService;
use App\Services\Client\ClientMergeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;

class ClientController extends Controller
{
    public function __construct(
        private BookingService $bookingService,
        private ClientMergeService $clientMergeService,
    ) {}

    public function index(Request $request)
    {
        $user = auth()->user();

        if (! $user->role->canManageTeam() && ! $user->is_master) {
            return redirect()->route('client.bookings')
                ->with('error', 'У вас нет доступа к базе клиентов.');
        }

        $perPage = (int) $request->query('per_page', 12);
        $sort = $request->query('sort', 'last_visit_desc');
        $search = $request->query('search', '');
        $filter = $request->query('filter', 'all');

        $allowedSorts = ['last_visit_desc', 'name_asc'];
        if (! in_array($sort, $allowedSorts)) {
            $sort = 'last_visit_desc';
        }
        $allowedFilters = ['all', 'active', 'blocked'];
        if (! in_array($filter, $allowedFilters)) {
            $filter = 'all';
        }

        // 1. Base query with aggregates
        $query = Client::forWorkspaceOrMaster($user)
            ->with(['appointments' => fn ($q) => $q->where('status', AppointmentStatus::Paid)])
            ->withCount(['appointments as total_bookings' => function ($q) {
                $q->where('status', '!=', AppointmentStatus::Cancelled);
            }])
            ->withCount(['appointments as completed_bookings' => function ($q) {
                $q->where('status', AppointmentStatus::Paid);
            }])
            // Последний визит — MAX(start_time) только по прошедшим записям Paid:
            // будущие записи и неоплаченные статусы визитом не считаются.
            ->withAggregate(['appointments as last_visit' => function ($q) {
                $q->where('status', AppointmentStatus::Paid)
                    ->where('start_time', '<=', now());
            }], 'start_time', 'max');

        // 2. Search (ILIKE for case-insensitive)
        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'ilike', "%{$search}%")
                    ->orWhere('phone', 'ilike', "%{$search}%");
            });
        }

        // 3. Filter
        if ($filter === 'active') {
            $query->where('is_blocked', false);
        } elseif ($filter === 'blocked') {
            $query->where('is_blocked', true);
        }

        // 4. Sort at DB level
        if ($sort === 'name_asc') {
            $query->orderBy('name', 'asc');
        } else {
            $query->orderByRaw('last_visit DESC NULLS LAST');
        }

        // 5. Paginate at DB level
        $paginator = $query->paginate($perPage, ['*'], 'page', (int) $request->query('page', 1));

        // 6. Map to DTO (LTV computed from eager-loaded paid appointments)
        $timezone = $user->getTimezone();
        $data = $paginator->getCollection()->map(function (Client $client) use ($timezone) {
            $ltv = $client->appointments
                ->where('status', AppointmentStatus::Paid)
                ->sum(fn ($a) => $a->display_price);

            return [
                'id' => $client->id,
                'name' => $client->name,
                'phone' => $client->phone,
                'user_id' => $client->user_id,
                'is_blocked' => $client->is_blocked,
                'avatar_url' => $client->avatar_url,
                'notes' => $client->notes,
                'total_bookings' => $client->total_bookings,
                'completed_bookings' => $client->completed_bookings,
                'ltv' => (float) $ltv,
                'last_visit' => $this->formatLastVisit($client->last_visit, $timezone),
            ];
        });

        return Inertia::render('admin/clients', [
            'clients' => [
                'data' => $data,
                'total' => $paginator->total(),
                'per_page' => $paginator->perPage(),
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'from' => $paginator->firstItem(),
                'to' => $paginator->lastItem(),
            ],
        ]);
    }

    /**
     * start_time хранится в UTC: один сдвиг в пояс мастера, дальше строка
     * уходит как есть, чтобы фронтенд (парсит её как локальное время) не
     * сдвинул день повторно через UTC.
     */
    private function formatLastVisit(?string $lastVisit, string $timezone): ?string
    {
        if ($lastVisit === null || $lastVisit === '') {
            return null;
        }

        return Carbon::parse($lastVisit, 'UTC')
            ->setTimezone($timezone)
            ->format('Y-m-d H:i:s');
    }

    public function store(Request $request)
    {
        $this->authorize('create', Client::class);

        $master = auth()->user();

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'phone' => 'required|string|max:20',
            'notes' => 'nullable|string|max:1000',
        ]);

        try {
            $client = $this->clientMergeService->createManualClient(
                $master->id,
                $validated['phone'],
                $validated['name'],
                $validated['notes'] ?? null,
                $master->workspace_id,
            );
        } catch (DuplicateClientPhoneException) {
            return $this->phoneError($request, 'Клиент с таким номером телефона уже существует.');
        } catch (AmbiguousClientMatchException) {
            return $this->phoneError($request, 'Найдено несколько карточек с этим номером. Уточните данные клиента.');
        } catch (\InvalidArgumentException) {
            return $this->phoneError($request, 'Укажите корректный номер телефона.');
        }

        if (! $request->header('X-Inertia')) {
            return response()->json([
                'id' => $client->id,
                'name' => $client->name,
                'phone' => $client->phone,
            ]);
        }

        return back()->with('success', 'Клиент добавлен');
    }

    public function update(Request $request, Client $client)
    {
        $this->authorize('update', $client);

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'phone' => 'required|string|max:20',
            'notes' => 'nullable|string|max:1000',
        ]);

        try {
            $this->clientMergeService->updateManualClient(
                $client,
                $validated['phone'],
                $validated['name'],
                $validated['notes'] ?? null,
            );
        } catch (DuplicateClientPhoneException) {
            return $this->phoneError($request, 'Клиент с таким номером телефона уже существует');
        } catch (AmbiguousClientMatchException) {
            return $this->phoneError($request, 'Найдено несколько карточек с этим номером. Уточните данные клиента.');
        } catch (\InvalidArgumentException) {
            return $this->phoneError($request, 'Укажите корректный номер телефона.');
        }

        return back()->with('success', 'Данные клиента обновлены');
    }

    private function phoneError(Request $request, string $message): JsonResponse|RedirectResponse
    {
        if ($request->expectsJson()) {
            return response()->json([
                'message' => $message,
                'errors' => ['phone' => $message],
            ], 422);
        }

        return back()->withErrors([
            'phone' => $message,
        ])->withInput();
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
                ->each(function (Appointment $appointment) {
                    try {
                        $this->bookingService->cancel($appointment, auth()->user());
                    } catch (PastAppointmentException) {
                        // прошедшая запись — пропускаем, не обрываем блокировку
                    }
                });
        }

        return back()->with('success', $client->is_blocked
            ? "Клиент {$client->name} заблокирован."
            : "Клиент {$client->name} разблокирован."
        );
    }
}
