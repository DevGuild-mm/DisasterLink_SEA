<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * @property string $id
 * @property int $user_id
 * @property string $disaster_type
 * @property string $title
 * @property string $description
 * @property string $country
 * @property string $location
 * @property float|null $latitude
 * @property float|null $longitude
 * @property Carbon $incident_time
 * @property array<string>|null $photos
 * @property string $status
 * @property int|null $reviewer_id
 * @property string|null $review_notes
 * @property string|null $rejection_reason
 * @property Carbon|null $verified_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User $user
 * @property-read User|null $reviewer
 */
class DisasterReport extends Model
{
    use HasFactory;

    public const STATUS_PENDING = 'pending';
    public const STATUS_UNDER_REVIEW = 'under_review';
    public const STATUS_VERIFIED = 'verified';
    public const STATUS_REJECTED = 'rejected';

    public const VALID_STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_UNDER_REVIEW,
        self::STATUS_VERIFIED,
        self::STATUS_REJECTED,
    ];

    public const DISASTER_TYPES = [
        'Earthquake',
        'Flood',
        'Storm',
        'Landslide',
        'Wildfire',
        'Volcanic Activity',
        'Other',
    ];

    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'user_id',
        'disaster_type',
        'title',
        'description',
        'country',
        'location',
        'latitude',
        'longitude',
        'incident_time',
        'photos',
        'status',
        'reviewer_id',
        'review_notes',
        'rejection_reason',
        'verified_at',
    ];

    protected function casts(): array
    {
        return [
            'latitude' => 'float',
            'longitude' => 'float',
            'incident_time' => 'datetime',
            'verified_at' => 'datetime',
            'photos' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (DisasterReport $report) {
            if (empty($report->id)) {
                $report->id = 'rep_' . strtolower((string) Str::ulid());
            }
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewer_id');
    }

    /**
     * Get the publicly accessible URLs for photos.
     *
     * @return array<string>
     */
    public function getPhotoUrlsAttribute(): array
    {
        if (empty($this->photos) || !is_array($this->photos)) {
            return [];
        }

        return array_map(function ($path) {
            if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
                return $path;
            }
            return Storage::disk('public')->url($path);
        }, $this->photos);
    }

    /**
     * Map community disaster type to standardized dashboard category.
     */
    public function getCategoryAttribute(): string
    {
        return match (strtolower($this->disaster_type)) {
            'earthquake' => 'earthquake',
            'flood' => 'flood',
            'storm', 'typhoon', 'cyclone' => 'cyclone',
            'wildfire' => 'wildfire',
            'volcanic activity', 'volcano' => 'volcano',
            'landslide' => 'storm',
            default => 'other',
        };
    }

    /**
     * Transform to the standard DisasterEvent array format used across DisasterLinkSEA.
     *
     * @return array<string, mixed>
     */
    public function toDisasterEvent(): array
    {
        $hasCoords = $this->latitude !== null && $this->longitude !== null;

        return [
            'id' => $this->id,
            'source' => 'Community Report',
            'sources' => ['Community Report'],
            'source_id' => $this->id,
            'category' => $this->category,
            'raw_type' => $this->disaster_type,
            'title' => $this->title,
            'description' => $this->description,
            'country' => $this->country,
            'location' => $this->location,
            'iso3' => null,
            'latitude' => $hasCoords ? (float) $this->latitude : null,
            'longitude' => $hasCoords ? (float) $this->longitude : null,
            'coordinates' => $hasCoords ? [(float) $this->latitude, (float) $this->longitude] : null,
            'magnitude' => null,
            'depth_km' => null,
            'severity' => 'Community Verified',
            'severity_level' => null,
            'severity_score' => null,
            'severity_text' => 'Community Verified',
            'event_date' => $this->incident_time->toDateString(),
            'event_time' => $this->incident_time->toIso8601String(),
            'last_updated_time' => ($this->verified_at ?? $this->updated_at ?? $this->created_at)?->toIso8601String(),
            'source_url' => url('/events/' . $this->id),
            'source_urls' => [
                [
                    'source' => 'Community Report',
                    'url' => url('/events/' . $this->id),
                ],
            ],
            'timeline' => [
                [
                    'timestamp' => $this->incident_time->toIso8601String(),
                    'title' => 'Incident Occurred',
                    'description' => 'Observed at ' . $this->location . ', ' . $this->country,
                    'source' => 'Community Report',
                    'type' => 'observation',
                ],
                [
                    'timestamp' => ($this->created_at ?? $this->incident_time)->toIso8601String(),
                    'title' => 'Report Submitted',
                    'description' => 'Submitted by ' . ($this->user?->name ?? 'Community Member'),
                    'source' => 'DisasterLinkSEA',
                    'type' => 'detection',
                ],
                [
                    'timestamp' => ($this->verified_at ?? $this->updated_at)->toIso8601String(),
                    'title' => 'Admin Verified',
                    'description' => $this->review_notes ?: 'Reviewed and verified by DisasterLinkSEA Moderator',
                    'source' => 'Admin Verification',
                    'type' => 'verification',
                ],
            ],
            'is_community_report' => true,
            'verification_status' => $this->status,
            'submitter_name' => $this->user?->name ?? 'Community Member',
            'photos' => $this->photo_urls,
            'review_notes' => $this->review_notes,
        ];
    }
}
