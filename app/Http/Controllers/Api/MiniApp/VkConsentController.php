<?php

namespace App\Http\Controllers\Api\MiniApp;

use App\Constants\CacheKeys;
use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Models\Client;
use App\Services\VkLinkTokenService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class VkConsentController extends Controller
{
    public function __invoke(Request $request, VkLinkTokenService $tokenService): JsonResponse
    {
        $version = (string) config('legal.version');

        if ($version === '') {
            Log::error('[VK] consent: legal.version is empty');
            abort(500, 'legal_version_not_configured');
        }

        $request->validate([
            'token' => 'required|string',
        ]);

        $vkUserId = $request->attributes->get('vk_launch')->userId;

        $appointmentId = $tokenService->peek($request->input('token'));
        $appointment = $appointmentId ? Appointment::find($appointmentId) : null;

        Cache::put(
            CacheKeys::VK_CONSENT_PENDING . $vkUserId,
            $version,
            config('booking.draft_ttl'),
        );

        if ($appointment) {
            $client = Client::byVkId($vkUserId)
                ->where('user_id', $appointment->master_id)
                ->first();

            if ($client) {
                $client->pdn_consent_at = now();
                $client->pdn_consent_version = $version;
                $client->save();
            }
        }

        return response()->json(['ok' => true]);
    }

    public function status(Request $request, VkLinkTokenService $tokenService): JsonResponse
    {
        $version = (string) config('legal.version');

        if ($version === '') {
            Log::error('[VK] consent status: legal.version is empty');
            abort(500, 'legal_version_not_configured');
        }

        $token = $request->input('token');
        if ($token === null) {
            return response()->json(['error' => 'invalid_token'], 422);
        }

        $appointmentId = $tokenService->peek($token);
        if ($appointmentId === null) {
            return response()->json(['error' => 'invalid_token'], 422);
        }

        $appointment = Appointment::with(['master'])->find($appointmentId);
        if ($appointment === null) {
            return response()->json(['error' => 'appointment_not_found'], 422);
        }

        $vkUserId = $request->attributes->get('vk_launch')->userId;

        $hasGlobalConsent = Client::where('vk_id', $vkUserId)
            ->whereNotNull('pdn_consent_at')
            ->where('pdn_consent_version', $version)
            ->exists();

        $sameMasterClient = Client::byVkId($vkUserId)
            ->where('user_id', $appointment->master_id)
            ->first();

        $master = $appointment->master;
        $tz = $master?->getTimezone() ?? 'UTC';
        $date = $appointment->start_time->timezone($tz)->format('d.m.Y');
        $time = $appointment->start_time->timezone($tz)->format('H:i');

        return response()->json([
            'consent_required' => ! $hasGlobalConsent,
            'phone_required' => $sameMasterClient === null,
            'appointment' => [
                'service' => $appointment->display_name,
                'date' => $date,
                'time' => $time,
                'price' => $appointment->display_price,
                'address' => $master?->address,
            ],
        ]);
    }
}
