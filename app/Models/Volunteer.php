<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * @property string $id
 * @property int|null $user_id
 * @property string $name
 * @property string $email
 * @property string $phone
 * @property string $country
 * @property string $city
 * @property array<string>|null $skills
 * @property string $availability_status
 * @property string|null $notes
 * @property-read User|null $user
 */
class Volunteer extends Model
{
    use HasFactory;

    public const STATUS_AVAILABLE = 'available';

    public const STATUS_DEPLOYED = 'deployed';

    public const STATUS_STANDBY = 'standby';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'user_id',
        'name',
        'email',
        'phone',
        'country',
        'city',
        'skills',
        'availability_status',
        'notes',
    ];

    /**
     * Contact fields must never be serialized into public payloads.
     *
     * @var list<string>
     */
    protected $hidden = ['email', 'phone'];

    protected function casts(): array
    {
        return [
            'skills' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Volunteer $vol) {
            if (empty($vol->id)) {
                $vol->id = 'vol_'.strtolower((string) Str::ulid());
            }
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function rescueRequests(): HasMany
    {
        return $this->hasMany(RescueRequest::class, 'assigned_volunteer_id');
    }
}
