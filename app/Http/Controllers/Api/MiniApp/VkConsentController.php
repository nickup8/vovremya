<?php

namespace App\Http\Controllers\Api\MiniApp;

use App\Constants\CacheKeys;
use App\Http\Controllers\Controller;
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
}
