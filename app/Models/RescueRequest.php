<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * @property string $id
 * @property int|null $user_id
 * @property string $reporter_name
 * @property string $contact_phone
 * @property string $country
 * @property string $location
 * @property float|null $latitude
 * @property float|null $longitude
 * @property int $people_count
 * @property bool $has_medical_emergency
 * @property string $urgency
 * @property string $status
 * @property string|null $assigned_volunteer_id
 * @property string $situation_description
 * @property string|null $resolution_notes
 * @property-read User|null $user
 * @property-read Volunteer|null $assignedVolunteer
 */
class RescueRequest extends Model
{
    use HasFactory;

    public const URGENCY_CRITICAL = 'critical';

    public const URGENCY_HIGH = 'high';

    public const URGENCY_MODERATE = 'moderate';

    public const URGENCY_LOW = 'low';

    public const STATUS_PENDING = 'pending';

    public const STATUS_DISPATCHED = 'dispatched';

    public const STATUS_IN_PROGRESS = 'in_progress';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_CANCELLED = 'cancelled';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'user_id',
        'reporter_name',
        'contact_phone',
        'country',
        'location',
        'latitude',
        'longitude',
        'people_count',
        'has_medical_emergency',
        'urgency',
        'status',
        'assigned_volunteer_id',
        'situation_description',
        'resolution_notes',
    ];

    /**
     * Contact fields must never be serialized into public payloads.
     *
     * @var list<string>
     */
    protected $hidden = ['reporter_name', 'contact_phone'];

    protected function casts(): array
    {
        return [
            'latitude' => 'float',
            'longitude' => 'float',
            'people_count' => 'integer',
            'has_medical_emergency' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (RescueRequest $request) {
            if (empty($request->id)) {
                $request->id = 'rsc_'.strtolower((string) Str::ulid());
            }
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function assignedVolunteer(): BelongsTo
    {
        return $this->belongsTo(Volunteer::class, 'assigned_volunteer_id');
    }
}
