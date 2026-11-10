<?php

namespace App\Models;

use Database\Factories\SafetyGuideFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Admin-managed safety guide. A null country_id means the guide applies to all countries.
 *
 * @property int $id
 * @property string $slug
 * @property bool $is_published
 */
#[Fillable(['title', 'slug', 'hazard_type', 'summary', 'content', 'country_id', 'is_published', 'sort_order', 'created_by', 'updated_by'])]
class SafetyGuide extends Model
{
    /** Hazard groups shown on the public page (HLD §8.3). */
    public const HAZARD_TYPES = ['earthquake', 'cyclone', 'flood', 'volcano', 'wildfire', 'drought', 'general'];

    /** @use HasFactory<SafetyGuideFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_published' => 'boolean',
        ];
    }

    /**
     * The guide body as safe HTML. Raw HTML in the Markdown is dropped and unsafe links (javascript:, data:) are
     * not allowed, so the result can be shown with v-html.
     */
    public function contentHtml(): string
    {
        return (string) Str::markdown($this->content, ['html_input' => 'strip', 'allow_unsafe_links' => false]);
    }

    /**
     * @param  Builder<SafetyGuide>  $query
     * @return Builder<SafetyGuide>
     */
    public function scopePublished(Builder $query): Builder
    {
        return $query->where('is_published', true);
    }

    /**
     * @return BelongsTo<Country, $this>
     */
    public function country(): BelongsTo
    {
        return $this->belongsTo(Country::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
