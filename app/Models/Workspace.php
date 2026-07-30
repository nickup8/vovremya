<?php

namespace App\Models;

use App\Support\PlanDefaults;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class Workspace extends Model
{
    use HasUuids;

    protected function casts(): array
    {
        return [
            'settings' => 'array',
        ];
    }

    protected function allowMastersEditPrices(): Attribute
    {
        return Attribute::get(fn () => (bool) ($this->settings['allow_masters_edit_prices'] ?? false));
    }

    public function setAllowMastersEditPrices(bool $value): void
    {
        $settings = $this->settings ?? [];
        $settings['allow_masters_edit_prices'] = $value;
        $this->update(['settings' => $settings]);
    }

    protected $fillable = [
        'name',
        'slug',
        'owner_id',
        'settings',
    ];

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    public function activeSubscription()
    {
        return $this->subscriptions()
            ->where('status', 'active')
            ->where('expires_at', '>', now())
            ->latest('expires_at')
            ->first();
    }

    /**
     * Проверяет, есть ли указанная фича в текущем тарифе.
     * Без подписки — считаем тарифом "Старт".
     */
    public function hasFeature(string $feature): bool
    {
        $activeSubscription = $this->activeSubscription();

        if (! $activeSubscription || ! $activeSubscription->tariffPlan) {
            return in_array($feature, PlanDefaults::START_FEATURES);
        }

        $features = $activeSubscription->tariffPlan->features ?? [];

        return in_array($feature, $features);
    }

    /**
     * Количество мастеров (is_master=true) в workspace.
     */
    public function mastersCount(): int
    {
        return $this->users()->where('is_master', true)->count();
    }

    /**
     * Максимальное количество мастеров по тарифу.
     * Без подписки — 1 (Старт).
     */
    public function maxMasters(): int
    {
        $activeSubscription = $this->activeSubscription();

        if (! $activeSubscription || ! $activeSubscription->tariffPlan) {
            return PlanDefaults::START_MAX_MASTERS;
        }

        return $activeSubscription->tariffPlan->max_masters ?? PHP_INT_MAX;
    }

    /**
     * Можно ли добавить ещё одного мастера.
     */
    public function canAddMaster(): bool
    {
        return $this->mastersCount() < $this->maxMasters();
    }

    /**
     * Гарантирует наличие уникального slug для студии.
     * Если slug пустой — генерирует из name, проверяя коллизии.
     */
    public function ensureSlug(): void
    {
        if ($this->slug !== null && $this->slug !== '') {
            return;
        }

        DB::transaction(function () {
            $base = Str::slug($this->name);
            if ($base === '') {
                $base = 'studio';
            }

            $slug = $base;
            $attempts = 0;
            while (self::where('slug', $slug)->where('id', '!=', $this->id)->lockForUpdate()->exists()) {
                $attempts++;
                if ($attempts > 5) {
                    $slug = Str::lower(Str::random(8));
                } else {
                    $slug = $base.'-'.Str::lower(Str::random(5));
                }
            }

            $this->update(['slug' => $slug]);
        });
    }
}
