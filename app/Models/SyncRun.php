<?php

namespace App\Models;

use Database\Factories\SyncRunFactory;
use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One attempt to fetch from a data source.
 *
 * @property int $id
 * @property string $source_key
 * @property string $status
 */
#[Guarded(['id'])]
class SyncRun extends Model
{
    /** @use HasFactory<SyncRunFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'queued_at' => 'datetime',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    /**
     * @return BelongsTo<DataSource, $this>
     */
    public function dataSource(): BelongsTo
    {
        return $this->belongsTo(DataSource::class, 'source_key', 'key');
    }
}
