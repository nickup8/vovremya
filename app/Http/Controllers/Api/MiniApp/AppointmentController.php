<?php

namespace App\Http\Controllers\Api\MiniApp;

use App\Enums\AppointmentStatus;
use App\Exceptions\CancellationNotAllowedException;
use App\Http\Controllers\Controller;
use App\Http\Resources\MiniApp\AppointmentResource;
use App\Models\Appointment;
use App\Models\Client;
use App\Services\AppointmentStatusService;
use App\Services\Booking\BookingService;
use App\Services\Notification\MasterNotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AppointmentController extends Controller
{
    /**
     * Активные записи клиента (Booked + будущее).
     */
    public function index(Request $request): JsonResponse
    {
        $clientIds = $this->getClientIds($request);

        if ($clientIds->isEmpty()) {
            return response()->json([]);
        }

        $appointments = Appointment::with(['master', 'masterService'])
            ->whereIn('client_id', $clientIds)
            ->where('status', AppointmentStatus::Booked)
            ->where('start_time', '>', now())
            ->orderBy('start_time')
            ->get();

        return response()->json(
            AppointmentResource::collection($appointments)
        );
    }

    /**
     * История записей (прошлые, любые статусы).
     */
    public function history(Request $request): JsonResponse
    {
        $clientIds = $this->getClientIds($request);

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
        $clientIds = $this->getClientIds($request);

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
        $maxId = $request->attributes->get('max_init')->userId;
        $client = Client::byMaxId($maxId)->first();

        if ($client === null) {
            return response()->json(['name' => null, 'phone' => null]);
        }

        return response()->json([
            'name' => $client->name,
            'phone' => $client->phone,
        ]);
    }

    /**
     * Собирает ID всех строк клиента по max_id.
     *
     * @return \Illuminate\Support\Collection<int, string>
     */
    private function getClientIds(Request $request): \Illuminate\Support\Collection
    {
        $maxId = $request->attributes->get('max_init')->userId;

        return Client::byMaxId($maxId)->pluck('id');
    }
}
