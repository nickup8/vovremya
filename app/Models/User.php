<?php

namespace App\Models;

use App\Traits\SearchableByProvider;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
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
 * @property string|null $master_slug
 * @property string|null $specialty
 * @property string|null $address
 * @property string|null $workspace_id
 * @property string $role
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
    'is_master', 'master_slug', 'specialty', 'address',
    'telegram_notifications', 'max_notifications',
    'soft_deposit', 'deposit_timeout', 'deposit_percent',
    'slot_interval', 'workspace_id',
    'settings',
])]
#[Hidden(['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token'])]
class User extends Authenticatable implements PasskeyUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasUuids, Notifiable, PasskeyAuthenticatable, TwoFactorAuthenticatable, SearchableByProvider;

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'two_factor_confirmed_at' => 'datetime',
            'is_master' => 'boolean',
            'soft_deposit' => 'boolean',
            'deposit_timeout' => 'integer',
            'deposit_percent' => 'integer',
            'slot_interval' => 'integer',
            'is_super_admin' => 'boolean',
            'is_blocked' => 'boolean',
            'telegram_notifications' => 'boolean',
            'max_notifications' => 'boolean',
            'settings' => 'array',
        ];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function services(): HasMany
    {
        return $this->hasMany(Service::class);
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
}
