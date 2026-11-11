<?php

namespace App\Models;

use Database\Factories\DisasterEventFactory;
use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A disaster event stored from GDACS or USGS. Identity is (source, external_id).
 *
 * @property int $id
 * @property string $source
 * @property string $external_id
 * @property string $event_type
 * @property string $country_status
 * @property string $alert_status
 */
#[Guarded(['id'])]
class DisasterEvent extends Model
{
    /** @use HasFactory<DisasterEventFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'alert_level_derived' => 'boolean',
            'magnitude' => 'decimal:2',
            'severity_value' => 'decimal:3',
            'depth_km' => 'decimal:2',
            'latitude' => 'decimal:6',
            'longitude' => 'decimal:6',
            'occurred_at' => 'datetime',
            'ended_at' => 'datetime',
            'source_updated_at' => 'datetime',
            'source_meta' => 'array',
            'threshold_met_at' => 'datetime',
            'alerts_completed_at' => 'datetime',
            'first_seen_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'last_synced_at' => 'datetime',
            'raw_payload' => 'array',
        ];
    }

    /**
     * @return BelongsTo<Country, $this>
     */
    public function country(): BelongsTo
    {
        return $this->belongsTo(Country::class);
    }

    /**
     * @return BelongsTo<DisasterEvent, $this>
     */
    public function canonicalEvent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'canonical_event_id');
    }

    /**
     * @return HasMany<DisasterEvent, $this>
     */
    public function duplicates(): HasMany
    {
        return $this->hasMany(self::class, 'canonical_event_id');
    }

    /**
     * @return HasMany<DisasterAlertLog, $this>
     */
    public function alertLogs(): HasMany
    {
        return $this->hasMany(DisasterAlertLog::class);
    }
}
