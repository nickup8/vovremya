<?php

namespace App\Http\Controllers\Api\MiniApp;

use App\Constants\CacheKeys;
use App\Enums\AppointmentStatus;
use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Models\Client;
use App\Services\Booking\BookingService;
use App\Services\VkLinkTokenService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class VkCancelController extends Controller
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

        $appointment = Appointment::find($appointmentId);
        if ($appointment === null) {
            return response()->json(['error' => 'appointment_not_found'], 422);
        }

        $client = Client::byVkId($vkUserId)
            ->where('user_id', $appointment->master_id)
            ->first();

        if ($client === null) {
            return response()->json(['error' => 'client_not_found'], 422);
        }

        if ($appointment->status === AppointmentStatus::Cancelled) {
            $tokenService->consume($token);
            Cache::forget(CacheKeys::VK_CONSENT_PENDING . $vkUserId);

            return response()->json(['ok' => true]);
        }

        if ($appointment->client_id !== null
            || ! in_array($appointment->status, [AppointmentStatus::Booked, AppointmentStatus::PendingPayment])
        ) {
            return response()->json(['error' => 'appointment_unavailable'], 422);
        }

        try {
            app(BookingService::class)->cancel($appointment, $client);
        } catch (\Throwable) {
            $fresh = Appointment::find($appointmentId);
            if ($fresh && $fresh->status === AppointmentStatus::Cancelled) {
                $tokenService->consume($token);
                Cache::forget(CacheKeys::VK_CONSENT_PENDING . $vkUserId);

                return response()->json(['ok' => true]);
            }

            return response()->json(['error' => 'appointment_unavailable'], 422);
        }

        $tokenService->consume($token);
        Cache::forget(CacheKeys::VK_CONSENT_PENDING . $vkUserId);

        Log::info('[VK] booking cancelled', [
            'user_id' => $vkUserId,
            'appointment_id' => $appointmentId,
        ]);

        return response()->json(['ok' => true]);
    }
}
