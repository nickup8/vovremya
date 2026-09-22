<?php

namespace App\Services\Notification;

use App\Models\Appointment;
use App\Models\User;
use App\Services\MaxApiClient;
use App\Services\VkApiClient;
use Illuminate\Support\Facades\Log;

class MasterNotificationService
{
    public function sendNewAppointmentAlert(Appointment $appointment): void
    {
        $master = $appointment->master;
        $client = $appointment->client;
        $clientName = $client?->name ?? __('bot.fallback.client_name');
        $serviceName = $appointment->display_name;

        $text = __('bot.master.new_booking', [
            'client' => $clientName,
            'phone' => $client->phone ?? __('bot.fallback.phone'),
            'service' => $serviceName,
            'date' => $appointment->start_time->format('d.m.Y'),
            'time' => $appointment->start_time->format('H:i'),
        ]);

        $this->sendToMaster($master, $text);
    }

    public function sendSubscriptionExpired(User $master): void
    {
        $text = __('bot.master.subscription_expired');

        $this->sendToMaster($master, $text);
    }

    public function sendToMaster(User $master, string $text): void
    {
        if (! empty($master->max_id) && $master->max_notifications === true) {
            try {
                app(MaxApiClient::class)->sendMessage($master->max_id, $text);
            } catch (\Throwable $e) {
                Log::warning('MAX master notification failed', [
                    'master_id' => $master->id,
                    'channel' => 'max',
                    'error' => $e->getMessage(),
                ]);
            }
        }

        if (! empty($master->vk_id) && $master->vk_notifications === true) {
            try {
                app(VkApiClient::class)->sendMessage($master->vk_id, $text);
            } catch (\Throwable $e) {
                Log::warning('VK master notification failed', [
                    'master_id' => $master->id,
                    'channel' => 'vk',
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }
}
