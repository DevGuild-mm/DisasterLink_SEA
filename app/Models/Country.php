<?php

namespace App\Models;

use Database\Factories\CountryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * One of the 11 ASEAN countries (reference data).
 *
 * @property int $id
 * @property string $iso2
 * @property string $iso3
 * @property string $name
 * @property string $timezone
 * @property string $capital_name
 * @property string $capital_lat
 * @property string $capital_lng
 * @property int $sort_order
 * @property bool $is_active
 */
#[Fillable(['iso2', 'iso3', 'name', 'timezone', 'capital_name', 'capital_lat', 'capital_lng', 'sort_order', 'is_active'])]
class Country extends Model
{
    /** @use HasFactory<CountryFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'capital_lat' => 'decimal:6',
            'capital_lng' => 'decimal:6',
            'is_active' => 'boolean',
        ];
    }

    /**
     * Countries a person may choose: the active ones.
     *
     * @param  Builder<Country>  $query
     * @return Builder<Country>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * @return HasOne<CountryBoundary, $this>
     */
    public function boundary(): HasOne
    {
        return $this->hasOne(CountryBoundary::class);
    }

    /**
     * @return HasOne<CapitalWeather, $this>
     */
    public function capitalWeather(): HasOne
    {
        return $this->hasOne(CapitalWeather::class);
    }

    /**
     * @return HasMany<User, $this>
     */
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    /**
     * @return HasMany<DisasterEvent, $this>
     */
    public function disasterEvents(): HasMany
    {
        return $this->hasMany(DisasterEvent::class);
    }

    /**
     * @return HasMany<EmergencyContact, $this>
     */
    public function emergencyContacts(): HasMany
    {
        return $this->hasMany(EmergencyContact::class);
    }

    /**
     * @return HasMany<SafetyGuide, $this>
     */
    public function safetyGuides(): HasMany
    {
        return $this->hasMany(SafetyGuide::class);
    }
}
