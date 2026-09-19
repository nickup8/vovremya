<?php

namespace App\Models;

use App\Enums\RecurrenceType;
use App\Enums\RecurringSeriesStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RecurringAppointmentSeries extends Model
{
    use HasFactory, HasUuids;

    protected $table = 'recurring_appointment_series';

    protected $fillable = [
        'workspace_id',
        'master_id',
        'client_id',
        'master_service_id',
        'start_date',
        'start_time',
        'recurrence_type',
        'interval',
        'weekdays',
        'ends_at',
        'occurrences_count',
        'timezone',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'recurrence_type' => RecurrenceType::class,
            'status' => RecurringSeriesStatus::class,
            'weekdays' => 'array',
            'interval' => 'integer',
            'occurrences_count' => 'integer',
            'start_date' => 'date',
            'ends_at' => 'date',
        ];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function master(): BelongsTo
    {
        return $this->belongsTo(User::class, 'master_id');
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function masterService(): BelongsTo
    {
        return $this->belongsTo(MasterService::class);
    }

    public function appointments(): HasMany
    {
        return $this->hasMany(Appointment::class, 'recurring_series_id');
    }
}
