<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * @property string $id
 * @property string $title
 * @property string $country
 * @property string $origin
 * @property string $destination
 * @property float $distance_km
 * @property string $status
 * @property string|null $hazard_warning
 * @property string $instructions
 * @property array<array<string, mixed>>|null $waypoints
 * @property bool $is_verified
 * @property string|null $verified_by
 */
class EvacuationRoute extends Model
{
    use HasFactory;

    public const STATUS_CLEAR = 'clear';
    public const STATUS_CAUTION = 'caution';
    public const STATUS_IMPASSABLE = 'impassable';

    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'title',
        'country',
        'origin',
        'destination',
        'distance_km',
        'status',
        'hazard_warning',
        'instructions',
        'waypoints',
        'is_verified',
        'verified_by',
    ];

    protected function casts(): array
    {
        return [
            'distance_km' => 'float',
            'is_verified' => 'boolean',
            'waypoints' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (EvacuationRoute $route) {
            if (empty($route->id)) {
                $route->id = 'rte_' . strtolower((string) Str::ulid());
            }
        });
    }
}
