<?php

namespace App\Models;

use Database\Factories\DisasterAlertLogFactory;
use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One alert email per (user, event). Status: pending, sending, sent, deferred, failed, suppressed.
 *
 * @property int $id
 * @property int $user_id
 * @property int $disaster_event_id
 * @property string $status
 */
#[Guarded(['id'])]
class DisasterAlertLog extends Model
{
    /** @use HasFactory<DisasterAlertLogFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'last_attempt_at' => 'datetime',
            'next_attempt_at' => 'datetime',
            'sent_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<DisasterEvent, $this>
     */
    public function disasterEvent(): BelongsTo
    {
        return $this->belongsTo(DisasterEvent::class);
    }
}
