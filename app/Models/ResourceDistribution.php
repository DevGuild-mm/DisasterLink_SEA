<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * @property string $id
 * @property string $resource_id
 * @property string|null $shelter_id
 * @property string $recipient
 * @property int $quantity
 * @property string $distributed_by
 * @property string $distributed_at
 * @property string|null $notes
 * @property-read EmergencyResource $resource
 * @property-read Shelter|null $shelter
 */
class ResourceDistribution extends Model
{
    use HasFactory;

    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'resource_id',
        'shelter_id',
        'recipient',
        'quantity',
        'distributed_by',
        'distributed_at',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'distributed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (ResourceDistribution $dist) {
            if (empty($dist->id)) {
                $dist->id = 'dst_' . strtolower((string) Str::ulid());
            }
        });
    }

    public function resource(): BelongsTo
    {
        return $this->belongsTo(EmergencyResource::class, 'resource_id');
    }

    public function shelter(): BelongsTo
    {
        return $this->belongsTo(Shelter::class);
    }
}
