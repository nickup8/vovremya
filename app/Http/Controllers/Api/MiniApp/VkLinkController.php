<?php

namespace App\Http\Controllers\Api\MiniApp;

use App\Constants\CacheKeys;
use App\Enums\AppointmentSource;
use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Services\Auth\VkPhoneNumberVerifier;
use App\Services\Client\ClientMergeService;
use App\Services\VkLinkTokenService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class VkLinkController extends Controller
{
    public function __invoke(
        Request $request,
        VkLinkTokenService $tokenService,
        VkPhoneNumberVerifier $phoneVerifier,
        ClientMergeService $clientMerge,
    ): JsonResponse {
        $request->validate([
            'token' => 'required|string',
            'phone_number' => 'required|string',
            'sign' => 'required|string',
        ]);

        $vkUserId = $request->attributes->get('vk_launch')->userId;

        $consentVersion = Cache::get(CacheKeys::VK_CONSENT_PENDING . $vkUserId);
        if ($consentVersion === null) {
            return response()->json(['error' => 'pdn_consent_required'], 403);
        }

        $appointmentId = $tokenService->peek($request->input('token'));
        if ($appointmentId === null) {
            return response()->json(['error' => 'invalid_token'], 422);
        }

        $rawPhone = $request->input('phone_number');
        if ($phoneVerifier->verify($vkUserId, $rawPhone, $request->input('sign')) === null) {
            return response()->json(['error' => 'invalid_phone_sign'], 422);
        }

        $normalizedPhone = preg_replace('/[^0-9]/', '', $rawPhone);

        $appointment = Appointment::find($appointmentId);
        if ($appointment === null) {
            return response()->json(['error' => 'appointment_not_found'], 422);
        }

        $client = $clientMerge->findOrCreateByPhone(
            $appointment->master_id,
            $normalizedPhone,
        );

        if ($client->vk_id !== null && $client->vk_id !== $vkUserId) {
            return response()->json(['error' => 'client_already_linked'], 409);
        }

        $consumedId = $tokenService->consume($request->input('token'));
        if ($consumedId === null || $consumedId !== $appointmentId) {
            return response()->json(['error' => 'token_consumed'], 422);
        }

        DB::transaction(function () use ($client, $vkUserId, $appointment, $consentVersion) {
            $clientUpdates = ['vk_id' => $vkUserId];

            if (empty($client->pdn_consent_at) || $client->pdn_consent_version !== $consentVersion) {
                $clientUpdates['pdn_consent_at'] = now();
                $clientUpdates['pdn_consent_version'] = $consentVersion;
            }

            $client->update($clientUpdates);

            $appointment->update([
                'client_id' => $client->id,
                'source' => AppointmentSource::Vk,
            ]);
        });

        Cache::forget(CacheKeys::VK_CONSENT_PENDING . $vkUserId);

        return response()->json(['ok' => true]);
    }
}
