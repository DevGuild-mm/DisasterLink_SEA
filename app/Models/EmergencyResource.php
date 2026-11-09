<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * @property string $id
 * @property string|null $shelter_id
 * @property string $category
 * @property string $item_name
 * @property int $quantity
 * @property string $unit
 * @property int $min_threshold
 * @property string $status
 * @property string|null $notes
 * @property-read Shelter|null $shelter
 * @property-read bool $is_shortage
 */
class EmergencyResource extends Model
{
    use HasFactory;

    public const CATEGORY_WATER_FOOD = 'water_food';
    public const CATEGORY_MEDICAL = 'medical';
    public const CATEGORY_RESCUE_EQUIPMENT = 'rescue_equipment';
    public const CATEGORY_NECESSITIES = 'necessities';

    public const STATUS_ADEQUATE = 'adequate';
    public const STATUS_LOW = 'low';
    public const STATUS_CRITICAL = 'critical';
    public const STATUS_DEPLETED = 'depleted';

    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'shelter_id',
        'category',
        'item_name',
        'quantity',
        'unit',
        'min_threshold',
        'status',
        'notes',
    ];

    protected $appends = [
        'is_shortage',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'min_threshold' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (EmergencyResource $resource) {
            if (empty($resource->id)) {
                $resource->id = 'res_' . strtolower((string) Str::ulid());
            }

            // Automatically compute stock status if not explicitly overridden
            if (empty($resource->status) || $resource->status === self::STATUS_ADEQUATE) {
                $resource->status = self::computeStatus($resource->quantity, $resource->min_threshold);
            }
        });

        static::updating(function (EmergencyResource $resource) {
            $resource->status = self::computeStatus($resource->quantity, $resource->min_threshold);
        });
    }

    public static function computeStatus(int $quantity, int $minThreshold): string
    {
        if ($quantity <= 0) {
            return self::STATUS_DEPLETED;
        }

        if ($quantity < ($minThreshold * 0.5)) {
            return self::STATUS_CRITICAL;
        }

        if ($quantity < $minThreshold) {
            return self::STATUS_LOW;
        }

        return self::STATUS_ADEQUATE;
    }

    public function shelter(): BelongsTo
    {
        return $this->belongsTo(Shelter::class);
    }

    public function distributions(): HasMany
    {
        return $this->hasMany(ResourceDistribution::class, 'resource_id');
    }

    public function getIsShortageAttribute(): bool
    {
        return in_array($this->status, [self::STATUS_LOW, self::STATUS_CRITICAL, self::STATUS_DEPLETED], true);
    }
}
