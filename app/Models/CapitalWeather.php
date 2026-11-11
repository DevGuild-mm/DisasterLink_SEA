<?php

namespace App\Models;

use Database\Factories\CapitalWeatherFactory;
use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Latest weather snapshot for a country's capital (one row per country).
 *
 * @property int $country_id
 */
#[Guarded([])]
class CapitalWeather extends Model
{
    /** @use HasFactory<CapitalWeatherFactory> */
    use HasFactory;

    protected $table = 'capital_weather';

    protected $primaryKey = 'country_id';

    public $incrementing = false;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'temperature_c' => 'decimal:2',
            'precipitation_mm' => 'decimal:2',
            'wind_speed_kmh' => 'decimal:2',
            'forecast_min_c' => 'decimal:2',
            'forecast_max_c' => 'decimal:2',
            'observed_at' => 'datetime',
            'fetched_at' => 'datetime',
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
