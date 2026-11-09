<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * @property string $id
 * @property string $name
 * @property string $type
 * @property string $country
 * @property string $city
 * @property string $address
 * @property float $latitude
 * @property float $longitude
 * @property string $contact_name
 * @property string $contact_phone
 * @property string|null $contact_email
 * @property int $total_capacity
 * @property int $current_occupancy
 * @property string $status
 * @property array<string>|null $facilities
 * @property string|null $notes
 * @property int $available_capacity
 * @property float $occupancy_rate
 */
class Shelter extends Model
{
    use HasFactory;

    public const TYPE_SHELTER = 'shelter';
    public const TYPE_HOSPITAL = 'hospital';
    public const TYPE_RELIEF_CENTER = 'relief_center';
    public const TYPE_EVACUATION_CENTER = 'evacuation_center';

    public const STATUS_OPEN = 'open';
    public const STATUS_FULL = 'full';
    public const STATUS_CLOSED = 'closed';
    public const STATUS_EVACUATING = 'evacuating';

    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'name',
        'type',
        'country',
        'city',
        'address',
        'latitude',
        'longitude',
        'contact_name',
        'contact_phone',
        'contact_email',
        'total_capacity',
        'current_occupancy',
        'status',
        'facilities',
        'notes',
    ];

    protected $appends = [
        'available_capacity',
        'occupancy_rate',
    ];

    protected function casts(): array
    {
        return [
            'latitude' => 'float',
            'longitude' => 'float',
            'total_capacity' => 'integer',
            'current_occupancy' => 'integer',
            'facilities' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Shelter $shelter) {
            if (empty($shelter->id)) {
                $shelter->id = 'shl_' . strtolower((string) Str::ulid());
            }
        });
    }

    public function resources(): HasMany
    {
        return $this->hasMany(EmergencyResource::class);
    }

    public function distributions(): HasMany
    {
        return $this->hasMany(ResourceDistribution::class);
    }

    public function getAvailableCapacityAttribute(): int
    {
        return max(0, $this->total_capacity - $this->current_occupancy);
    }

    public function getOccupancyRateAttribute(): float
    {
        if ($this->total_capacity <= 0) {
            return 0.0;
        }

        return round(($this->current_occupancy / $this->total_capacity) * 100, 1);
    }
}
