<?php

namespace App\Http\Controllers\Api\MiniApp;

use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Services\Auth\VkPhoneNumberVerifier;
use App\Services\Client\ClientMergeService;
use App\Services\VkLinkTokenService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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

        DB::transaction(function () use ($client, $vkUserId, $appointment) {
            $client->update(['vk_id' => $vkUserId]);
            $appointment->update(['client_id' => $client->id]);
        });

        return response()->json(['ok' => true]);
    }
}
