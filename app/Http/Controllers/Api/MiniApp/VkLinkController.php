<?php

namespace App\Http\Controllers\Api\MiniApp;

use App\Constants\CacheKeys;
use App\Enums\AppointmentSource;
use App\Enums\AppointmentStatus;
use App\Events\AppointmentCreated;
use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Models\Client;
use App\Services\Auth\VkPhoneNumberVerifier;
use App\Services\Client\ClientMergeService;
use App\Services\Notification\MasterNotificationService;
use App\Services\VkLinkTokenService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

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

        $consentVersion = (string) config('legal.version');

        $hasGlobalConsent = Client::where('vk_id', $vkUserId)
            ->whereNotNull('pdn_consent_at')
            ->where('pdn_consent_version', $consentVersion)
            ->exists();

        $pendingConsentVersion = $hasGlobalConsent
            ? null
            : Cache::get(CacheKeys::VK_CONSENT_PENDING . $vkUserId);

        if (! $hasGlobalConsent && $pendingConsentVersion === null) {
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

        $vkName = $this->resolveVkName($request, $vkUserId);

        $client = $clientMerge->findOrCreateByPhone(
            $appointment->master_id,
            $normalizedPhone,
            '',
            $vkName,
        );

        if ($client->vk_id !== null && $client->vk_id !== $vkUserId) {
            return response()->json(['error' => 'client_already_linked'], 409);
        }

        if ($client->isBlocked()) {
            $appointment->delete();
            $tokenService->consume($request->input('token'));
            if ($pendingConsentVersion !== null) {
                Cache::forget(CacheKeys::VK_CONSENT_PENDING . $vkUserId);
            }

            return response()->json(['error' => 'booking_unavailable'], 403);
        }

        DB::beginTransaction();

        try {
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
                DB::rollBack();

                return response()->json(['error' => 'appointment_unavailable'], 422);
            }

            $clientUpdates = ['vk_id' => $vkUserId];

            if ($pendingConsentVersion !== null) {
                if (empty($client->pdn_consent_at) || $client->pdn_consent_version !== $pendingConsentVersion) {
                    $clientUpdates['pdn_consent_at'] = now();
                    $clientUpdates['pdn_consent_version'] = $pendingConsentVersion;
                }
            }

            $client->update($clientUpdates);

            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }

        $appointment->refresh();

        broadcast(new AppointmentCreated($appointment->load(['client'])));

        $master = $appointment->master;
        $tz = $master?->getTimezone() ?? 'UTC';
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

        $tokenService->consume($request->input('token'));

        if ($pendingConsentVersion !== null) {
            Cache::forget(CacheKeys::VK_CONSENT_PENDING . $vkUserId);
        }

        return response()->json(['ok' => true]);
    }

    private function resolveVkName(Request $request, string $trustedVkUserId): ?string
    {
        $profileId = $request->input('vk_profile_id');

        if ($profileId === null || (string) $profileId !== $trustedVkUserId) {
            return null;
        }

        $first = $this->normalizeNamePart($request->input('first_name'));
        $last = $this->normalizeNamePart($request->input('last_name'));

        $name = trim("$first $last");

        if ($name === '') {
            return null;
        }

        return mb_substr($name, 0, 255);
    }

    private function normalizeNamePart(mixed $value): string
    {
        if (! is_string($value)) {
            return '';
        }

        return preg_replace('/\s+/u', ' ', trim($value));
    }
}
