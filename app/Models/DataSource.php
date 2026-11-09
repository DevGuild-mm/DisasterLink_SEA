<?php

namespace App\Models;

use Database\Factories\DataSourceFactory;
use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One row per external source: gdacs, usgs, open_meteo. Drives the admin Data Freshness tab.
 *
 * @property string $key
 */
#[Guarded([])]
class DataSource extends Model
{
    /** @use HasFactory<DataSourceFactory> */
    use HasFactory;

    protected $primaryKey = 'key';

    public $incrementing = false;

    protected $keyType = 'string';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'last_attempt_at' => 'datetime',
            'last_success_at' => 'datetime',
            'last_empty_success_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<SyncRun, $this>
     */
    public function syncRuns(): HasMany
    {
        return $this->hasMany(SyncRun::class, 'source_key', 'key');
    }
}
