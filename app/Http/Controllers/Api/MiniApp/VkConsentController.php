<?php

namespace App\Http\Controllers\Api\MiniApp;

use App\Constants\CacheKeys;
use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Services\VkLinkTokenService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class VkConsentController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $version = (string) config('legal.version');

        if ($version === '') {
            Log::error('[VK] consent: legal.version is empty');
            abort(500, 'legal_version_not_configured');
        }

        $vkUserId = $request->attributes->get('vk_launch')->userId;

        Cache::put(
            CacheKeys::VK_CONSENT_PENDING . $vkUserId,
            $version,
            config('booking.draft_ttl'),
        );

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

        if (!\App\Models\Appointment::where('id', $appointmentId)->exists()) {
            return response()->json(['error' => 'appointment_not_found'], 422);
        }

        $vkUserId = $request->attributes->get('vk_launch')->userId;

        $hasConsent = Client::where('vk_id', $vkUserId)
            ->whereNotNull('pdn_consent_at')
            ->where('pdn_consent_version', $version)
            ->exists();

        return response()->json(['consent_required' => ! $hasConsent]);
    }
}
