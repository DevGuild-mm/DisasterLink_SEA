<?php

namespace App\Models;

use Database\Factories\CountryBoundaryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ADM0 country outline, used only to resolve an event's country from coordinates.
 *
 * @property int $country_id
 * @property string $geojson
 */
#[Fillable(['country_id', 'geojson', 'min_lat', 'max_lat', 'min_lng', 'max_lng', 'source', 'source_version', 'downloaded_at', 'license', 'attribution'])]
class CountryBoundary extends Model
{
    /** @use HasFactory<CountryBoundaryFactory> */
    use HasFactory;

    protected $primaryKey = 'country_id';

    public $incrementing = false;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'min_lat' => 'decimal:6',
            'max_lat' => 'decimal:6',
            'min_lng' => 'decimal:6',
            'max_lng' => 'decimal:6',
            'downloaded_at' => 'date',
        ];
    }

    /**
     * @return BelongsTo<Country, $this>
     */
    public function country(): BelongsTo
    {
        return $this->belongsTo(Country::class);
    }
}
