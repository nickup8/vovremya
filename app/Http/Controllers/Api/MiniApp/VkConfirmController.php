<?php

namespace App\Http\Controllers\Api\MiniApp;

use App\Constants\CacheKeys;
use App\Enums\AppointmentSource;
use App\Enums\AppointmentStatus;
use App\Events\AppointmentCreated;
use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Models\Client;
use App\Services\Notification\MasterNotificationService;
use App\Services\VkLinkTokenService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class VkConfirmController extends Controller
{
    public function __invoke(Request $request, VkLinkTokenService $tokenService): JsonResponse
    {
        $request->validate([
            'token' => 'required|string',
        ]);

        $vkUserId = $request->attributes->get('vk_launch')->userId;
        $token = $request->input('token');

        $appointmentId = $tokenService->peek($token);
        if ($appointmentId === null) {
            return response()->json(['error' => 'invalid_token'], 422);
        }

        $appointment = Appointment::with(['master'])->find($appointmentId);
        if ($appointment === null) {
            return response()->json(['error' => 'appointment_not_found'], 422);
        }

        $client = Client::byVkId($vkUserId)
            ->where('user_id', $appointment->master_id)
            ->first();

        if ($client === null) {
            return response()->json(['error' => 'client_not_found'], 422);
        }

        $hasGlobalConsent = Client::where('vk_id', $vkUserId)
            ->whereNotNull('pdn_consent_at')
            ->where('pdn_consent_version', config('legal.version'))
            ->exists();

        if (! $hasGlobalConsent) {
            return response()->json(['error' => 'pdn_consent_required'], 403);
        }

        if ($client->isBlocked()) {
            $appointment->delete();
            $tokenService->consume($token);
            Cache::forget(CacheKeys::VK_CONSENT_PENDING . $vkUserId);

            return response()->json(['error' => 'booking_unavailable'], 403);
        }

        $affected = Appointment::where('id', $appointmentId)
            ->whereNull('client_id')
            ->whereIn('status', [
                AppointmentStatus::Booked,
                AppointmentStatus::PendingPayment,
            ])
            ->update([
                'client_id' => $client->id,
                'source' => AppointmentSource::Vk,
            ]);

        if ($affected === 0) {
            return response()->json(['error' => 'appointment_unavailable'], 422);
        }

        $appointment->refresh();

        broadcast(new AppointmentCreated($appointment->load(['client'])));

        $master = $appointment->master;
        $tz = $master->getTimezone();
        $date = $appointment->start_time->timezone($tz)->format('d.m.Y');
        $time = $appointment->start_time->timezone($tz)->format('H:i');

        $lockKey = 'master_notified_' . $appointment->id;
        if (Cache::add($lockKey, true, now()->addMinutes(10))) {
            $phone = $client->phone ?? '';
            $clientName = $client->name ?? '';

            app(MasterNotificationService::class)
                ->sendToMaster($master, __('bot.master.new_booking', [
                    'client' => $clientName,
                    'phone' => $phone,
                    'service' => $appointment->display_name,
                    'date' => $date,
                    'time' => $time,
                ]));
        }

        $tokenService->consume($token);
        Cache::forget(CacheKeys::VK_CONSENT_PENDING . $vkUserId);

        Log::info('[VK] booking confirmed', [
            'user_id' => $vkUserId,
            'appointment_id' => $appointmentId,
            'client_id' => $client->id,
        ]);

        return response()->json(['ok' => true]);
    }
}
