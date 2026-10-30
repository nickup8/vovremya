<?php

namespace App\Services\Notification;

use App\Enums\AppointmentNotificationEventType;
use App\Enums\AppointmentSource;
use App\Models\Appointment;
use App\Models\AppointmentNotificationEvent;
use App\Models\Client;

/**
 * Единый выбор канала доставки клиентского уведомления/напоминания.
 *
 * Источник записи — аналитика, а не разрешение на отправку: source=admin
 * не блокирует доставку, но и не переименовывается в max/vk.
 *
 * Правило:
 * 1. Запись из MAX/ВК/Telegram — рабочий канал при доступности клиента;
 * 2. Ручная (admin) или недоступный рабочий канал — предсказуемый основной
 *    канал: MAX, затем разрешённый ВК;
 * 3. Иного канала нет — явный «нет доступного канала» (null).
 *
 * Исключение на уровне события (resolveForEvent): подтверждение брони,
 * созданное через ВК-вход, пиннится на ВК и не подпадает под fallback на
 * MAX. Общий resolve() для остальных уведомлений не меняется.
 *
 * Telegram не развивается: канал используется только когда запись уже
 * telegram-источника и у клиента есть telegram_id.
 */
class AppointmentChannelResolver
{
    public const CHANNEL_MAX = 'max';

    public const CHANNEL_VK = 'vk';

    public const CHANNEL_TELEGRAM = 'telegram';

    /**
     * Маркер события, созданного через ВК-подтверждение (VkLink/VkConfirm):
     * доставка разрешена только в ВК, подмена на MAX запрещена во всех
     * точках выбора канала (handle, deliver-route, retry, recovery).
     */
    public const PAYLOAD_VK_ONLY = 'vk_only';

    /**
     * @return string|null 'max'|'vk'|'telegram' либо null — нет доступного канала
     */
    public function resolve(Appointment $appointment): ?string
    {
        $client = $appointment->client;

        if ($client === null) {
            return null;
        }

        $source = $appointment->source instanceof AppointmentSource
            ? $appointment->source->value
            : $appointment->source;

        // 1. Рабочий канал записи, если клиент по-прежнему доступен.
        if ($source === self::CHANNEL_TELEGRAM && ! empty($client->telegram_id)) {
            return self::CHANNEL_TELEGRAM;
        }

        if ($source === self::CHANNEL_MAX && $this->maxReachable($client)) {
            return self::CHANNEL_MAX;
        }

        if ($source === self::CHANNEL_VK && $this->vkReachable($client)) {
            return self::CHANNEL_VK;
        }

        // 2. Ручная запись или недоступный рабочий канал: MAX, затем ВК.
        if ($this->maxReachable($client)) {
            return self::CHANNEL_MAX;
        }

        if ($this->vkReachable($client)) {
            return self::CHANNEL_VK;
        }

        return null;
    }

    /**
     * Канал для конкретного outbox-события.
     *
     * Подтверждение брони, созданное через ВК-вход (VkLink/VkConfirm,
     * payload vk_only), доставляется ТОЛЬКО в ВК: при запрете сообщений
     * (false) — «нет канала» без подмены на MAX; null (ещё не проверялся)
     * допускает ВК-попытку. Прочие события — общий fallback resolve().
     */
    public function resolveForEvent(
        AppointmentNotificationEvent $event,
        Appointment $appointment,
    ): ?string {
        if ($event->event_type === AppointmentNotificationEventType::BookingConfirmed
            && ($event->payload[self::PAYLOAD_VK_ONLY] ?? false) === true) {
            $client = $appointment->client;

            if ($client !== null && $this->vkReachable($client)) {
                return self::CHANNEL_VK;
            }

            return null;
        }

        return $this->resolve($appointment);
    }

    /**
     * Доступность канала без выбора: нужна для команды напоминаний,
     * которая не должна claim'ить запись вовсе, если каналов нет.
     */
    public function hasAnyChannel(Appointment $appointment): bool
    {
        return $this->resolve($appointment) !== null;
    }

    private function maxReachable(Client $client): bool
    {
        return ! empty($client->max_id);
    }

    private function vkReachable(Client $client): bool
    {
        // Наличие телефона или vk_id само по себе не разрешает отправку:
        // нужен явно не-запрещённый доступ (null = ещё не проверялся).
        return ! empty($client->vk_id) && $client->vk_messages_allowed !== false;
    }
}
