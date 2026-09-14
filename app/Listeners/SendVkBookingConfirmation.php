<?php

namespace App\Listeners;

use App\Constants\CacheKeys;
use App\Enums\AppointmentSource;
use App\Events\AppointmentCreated;
use App\Services\VkApiClient;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class SendVkBookingConfirmation implements \Illuminate\Contracts\Queue\ShouldQueue
{
    public int $tries = 3;
    public int $timeout = 15;

    public function backoff(): array
    {
        return [5, 15, 30];
    }

    public function handle(AppointmentCreated $event): void
    {
        $appointment = $event->appointment;

        if ($appointment->source !== AppointmentSource::Vk) {
            return;
        }

        $appointment->loadMissing(['master', 'client', 'masterService']);

        $client = $appointment->client;
        $master = $appointment->master;

        if ($client === null || empty($client->vk_id)) {
            return;
        }

        $lockKey = CacheKeys::VK_BOOKING_CONFIRMED . $appointment->id;

        if (! Cache::add($lockKey, true, now()->addMinutes(10))) {
            return;
        }

        $tz = $master->getTimezone();
        $date = $appointment->start_time->timezone($tz)->format('d.m.Y');
        $time = $appointment->start_time->timezone($tz)->format('H:i');

        $price = $appointment->display_price;
        $formattedPrice = $price > 0
            ? number_format($price, 0, '.', ' ') . ' ₽'
            : '—';

        $address = $master->address ?: '—';

        $text = __('bot.vk_booking_confirmed', [
            'master' => $master->name ?? '—',
            'service' => $appointment->display_name,
            'date' => $date,
            'time' => $time,
            'price' => $formattedPrice,
            'address' => $address,
        ]);

        $keyboard = [
            'one_time' => false,
            'inline' => true,
            'buttons' => [[[
                'action' => [
                    'type' => 'open_app',
                    'app_id' => (int) config('services.vk.app_id'),
                    'owner_id' => -(int) config('services.vk.group_id'),
                    'label' => '📅 Открыть ИРСИ',
                    'hash' => '',
                ],
            ]]],
        ];

        $mid = app(VkApiClient::class)->sendMessageWithKeyboard(
            (string) $client->vk_id,
            $text,
            $keyboard,
        );

        if ($mid === null) {
            Cache::forget($lockKey);
            throw new \RuntimeException('VK API failed to send booking confirmation');
        }

        Log::info('[VK] booking confirmation sent', [
            'appointment_id' => $appointment->id,
            'mid' => $mid,
        ]);
    }

    public function failed(AppointmentCreated $event, \Throwable $exception): void
    {
        Log::warning('[VK] booking confirmation delivery exhausted', [
            'appointment_id' => $event->appointment->id,
            'error' => $exception->getMessage(),
        ]);
    }
}
