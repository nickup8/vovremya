<?php

namespace App\Models;

use Database\Factories\TrackingLinkFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * @property string $id
 * @property string $master_id
 * @property string $name
 * @property string $token
 * @property bool $is_active
 */
class TrackingLink extends Model
{
    /** @use HasFactory<TrackingLinkFactory> */
    use HasFactory, HasUuids;

    protected $fillable = [
        'master_id',
        'name',
        'token',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function master(): BelongsTo
    {
        return $this->belongsTo(User::class, 'master_id');
    }

    public function appointments(): HasMany
    {
        return $this->hasMany(Appointment::class, 'tracking_link_id');
    }

    /**
     * Генерирует случайный, непоследовательный, не раскрывающий master_id токен.
     * Уникальность гарантируется unique-индексом; на коллизии повторяем.
     */
    public static function generateToken(): string
    {
        do {
            $token = Str::lower(Str::random(16));
        } while (self::where('token', $token)->exists());

        return $token;
    }
}
