<?php

namespace App\Http\Controllers\Api\MiniApp;

use App\Enums\AppointmentStatus;
use App\Exceptions\CancellationNotAllowedException;
use App\Http\Controllers\Controller;
use App\Http\Resources\MiniApp\AppointmentResource;
use App\Models\Appointment;
use App\Services\AppointmentStatusService;
use App\Services\Booking\BookingService;
use App\Services\MiniAppClientResolver;
use App\Services\Notification\MasterNotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AppointmentController extends Controller
{
    public function __construct(
        private MiniAppClientResolver $clientResolver,
    ) {}

    /**
     * Активные записи клиента (Booked + будущее).
     */
    public function index(Request $request): JsonResponse
    {
        $clientIds = $this->clientResolver->resolveClientIds($request);

        if ($clientIds->isEmpty()) {
            return response()->json([]);
        }

        $appointments = Appointment::with(['master', 'masterService', 'activeSlotRequest'])
            ->whereIn('client_id', $clientIds)
            ->where('status', AppointmentStatus::Booked)
            ->where('start_time', '>', now())
            ->orderBy('start_time')
            ->get();

        // Eager load workspace -> activeSubscription -> tariffPlan for isAutoFillEnabled()
        $appointments->loadMissing('master.workspace.subscriptions.tariffPlan');

        return response()->json(
            AppointmentResource::collection($appointments)
        );
    }

    /**
     * История записей (прошлые, любые статусы).
     */
    public function history(Request $request): JsonResponse
    {
        $clientIds = $this->clientResolver->resolveClientIds($request);

        if ($clientIds->isEmpty()) {
            return response()->json([]);
        }

        $appointments = Appointment::with(['master', 'masterService'])
            ->whereIn('client_id', $clientIds)
            ->where('start_time', '<', now())
            ->orderByDesc('start_time')
            ->get();

        return response()->json(
            AppointmentResource::collection($appointments)
        );
    }

    /**
     * Отмена записи клиентом.
     */
    public function cancel(Request $request, Appointment $appointment): JsonResponse
    {
        $clientIds = $this->clientResolver->resolveClientIds($request);

        // проверка владельца
        if (! $clientIds->contains($appointment->client_id)) {
            return response()->json(['error' => 'forbidden'], 403);
        }

        // проверка возможности отмены
        try {
            app(AppointmentStatusService::class)->assertCanCancel($appointment);
        } catch (CancellationNotAllowedException $e) {
            return response()->json([
                'error' => $e->getReason(),
                'deadline_hours' => $e->getDeadlineHours(),
            ], 422);
        }

        // отмена (актор — строка клиента этой записи)
        app(BookingService::class)->cancel($appointment, $appointment->client);

        // уведомление мастеру (best effort: сбой не должен заваливать уже совершённую отмену)
        try {
            $when = $appointment->start_time
                ->timezone($appointment->master?->getTimezone() ?? 'UTC')
                ->format('d.m.Y H:i');

            app(MasterNotificationService::class)->sendToMaster(
                $appointment->master,
                __('bot.master.client_cancelled', [
                    'service' => $appointment->display_name,
                    'when' => $when,
                ])
            );
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('MiniApp: не удалось уведомить мастера об отмене', [
                'appointment_id' => $appointment->id,
                'error' => $e->getMessage(),
            ]);
        }

        return response()->json(['ok' => true]);
    }

    /**
     * Профиль клиента (имя/телефон).
     */
    public function profile(Request $request): JsonResponse
    {
        $client = $this->clientResolver->resolveFirstClient($request);

        if ($client === null) {
            return response()->json(['name' => null, 'phone' => null]);
        }

        return response()->json([
            'name' => $client->name,
            'phone' => $client->phone,
        ]);
    }
}
