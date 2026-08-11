<?php

namespace App\Models;

use App\Enums\UserRole;
use App\Traits\SearchableByProvider;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Laravel\Fortify\Contracts\PasskeyUser;
use Laravel\Fortify\PasskeyAuthenticatable;
use Laravel\Fortify\TwoFactorAuthenticatable;

/**
 * @property string $id
 * @property string $name
 * @property string $email
 * @property string|null $phone
 * @property string|null $telegram_id
 * @property string|null $telegram_chat_id
 * @property string|null $max_id
 * @property string|null $avatar_url
 * @property bool $is_master
 * @property bool $is_service_provider
 * @property bool $admin_can_see_finance
 * @property string|null $master_slug
 * @property string|null $specialty
 * @property string|null $address
 * @property string|null $workspace_id
 * @property UserRole $role
 * @property Carbon|null $email_verified_at
 * @property string $password
 * @property string|null $two_factor_secret
 * @property string|null $two_factor_recovery_codes
 * @property Carbon|null $two_factor_confirmed_at
 * @property string|null $remember_token
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'name', 'email', 'password', 'phone',
    'telegram_id', 'telegram_chat_id', 'telegram_auth_token', 'max_id', 'vk_id', 'vk_chat_id', 'avatar_url',
    'is_master', 'is_service_provider', 'is_blocked', 'admin_can_see_finance',
    'master_slug', 'specialty', 'address',
    'telegram_notifications', 'max_notifications',
    'soft_deposit', 'deposit_timeout', 'deposit_percent',
    'slot_interval', 'workspace_id',
    'settings',
    'pdn_consent_at', 'pdn_consent_version',
])]
#[Hidden(['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token'])]
class User extends Authenticatable implements PasskeyUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasUuids, Notifiable, PasskeyAuthenticatable, SearchableByProvider, TwoFactorAuthenticatable;

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'two_factor_confirmed_at' => 'datetime',
            'is_master' => 'boolean',
            'is_service_provider' => 'boolean',
            'admin_can_see_finance' => 'boolean',
            'role' => UserRole::class,
            'soft_deposit' => 'boolean',
            'deposit_timeout' => 'integer',
            'deposit_percent' => 'integer',
            'slot_interval' => 'integer',
            'is_super_admin' => 'boolean',
            'is_blocked' => 'boolean',
            'pdn_consent_at' => 'datetime',
            'telegram_notifications' => 'boolean',
            'max_notifications' => 'boolean',
            'settings' => 'array',
        ];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function masterServices(): HasMany
    {
        return $this->hasMany(MasterService::class, 'master_id');
    }

    public function masterAppointments(): HasMany
    {
        return $this->hasMany(Appointment::class, 'master_id');
    }

    public function clients(): HasMany
    {
        return $this->hasMany(Client::class, 'user_id');
    }

    public function workingHours(): HasMany
    {
        return $this->hasMany(WorkingHour::class);
    }

    public function createDefaultWorkingHours(): void
    {
        if ($this->workingHours()->exists()) {
            return;
        }

        $defaults = [
            0 => ['is_working' => false, 'start_time' => null, 'end_time' => null, 'break_start_time' => null, 'break_end_time' => null],
            1 => ['is_working' => true, 'start_time' => '09:00', 'end_time' => '18:00', 'break_start_time' => '13:00', 'break_end_time' => '14:00'],
            2 => ['is_working' => true, 'start_time' => '09:00', 'end_time' => '18:00', 'break_start_time' => '13:00', 'break_end_time' => '14:00'],
            3 => ['is_working' => true, 'start_time' => '09:00', 'end_time' => '18:00', 'break_start_time' => '13:00', 'break_end_time' => '14:00'],
            4 => ['is_working' => true, 'start_time' => '09:00', 'end_time' => '18:00', 'break_start_time' => '13:00', 'break_end_time' => '14:00'],
            5 => ['is_working' => true, 'start_time' => '09:00', 'end_time' => '18:00', 'break_start_time' => '13:00', 'break_end_time' => '14:00'],
            6 => ['is_working' => true, 'start_time' => '10:00', 'end_time' => '15:00', 'break_start_time' => null, 'break_end_time' => null],
        ];

        foreach ($defaults as $dayOfWeek => $data) {
            $this->workingHours()->create(array_merge(['day_of_week' => $dayOfWeek], $data));
        }
    }

    public function blockedTimes(): HasMany
    {
        return $this->hasMany(BlockedTime::class);
    }

    public function isSuperAdmin(): bool
    {
        return (bool) ($this->is_super_admin ?? false);
    }

    public function isBlocked(): bool
    {
        return (bool) $this->is_blocked;
    }

    /**
     * Мастер действует как одиночка, если:
     * — у него нет workspace (workspace_id === null), ИЛИ
     * — его workspace не имеет активной подписки (тариф студии истёк → откат на Старт).
     */
    public function isSolo(): bool
    {
        if ($this->workspace_id === null) {
            return true;
        }

        return $this->workspace?->activeSubscription() === null;
    }

    public function getTimezone(): string
    {
        return $this->settings['timezone'] ?? config('booking.default_timezone');
    }

    public function isTimezoneConfirmed(): bool
    {
        return ($this->settings['timezone_confirmed'] ?? false) === true;
    }

    public function setTimezone(string $timezone): void
    {
        $settings = $this->settings ?? [];
        $settings['timezone'] = $timezone;
        $settings['timezone_confirmed'] = true;
        $this->settings = $settings;
        $this->save();
    }

    public function getBookingFlowType(): string
    {
        return $this->settings['booking_flow_type'] ?? 'free_verification';
    }

    public function getCustomPrepaymentMessage(): ?string
    {
        return $this->settings['custom_prepayment_message'] ?? null;
    }

    public function getReminderHoursBeforeFinal(): int
    {
        return (int) ($this->settings['reminder_hours_before_final'] ?? 3);
    }

    /**
     * ADR-1: единое правило видимости мастера в публичном виджете.
     * Мастер видим, если: is_master + master_slug + ≥1 активная услуга + (не owner/admin ИЛИ is_service_provider).
     */
    public function scopeVisibleInWidget(Builder $query): Builder
    {
        return $query
            ->where('is_master', true)
            ->whereNotNull('master_slug')
            ->where('master_slug', '!=', '')
            ->whereHas('masterServices', fn ($q) => $q
                ->where('is_active', true)
                ->whereHas('catalog', fn ($c) => $c->where('is_active', true)))
            ->where(function ($q) {
                $q->whereNotIn('role', [UserRole::Owner, UserRole::Admin])
                  ->orWhere('is_service_provider', true);
            });
    }
}
