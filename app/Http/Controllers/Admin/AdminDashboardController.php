<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Country;
use App\Models\EmergencyContact;
use App\Models\SafetyGuide;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AdminDashboardController extends Controller
{
    /**
     * The admin dashboard (HLD §8.5). Data Freshness is loaded by the page from `GET /admin/data-sources`.
     */
    public function index(Request $request): Response
    {
        $hazard = $request->string('hazard')->toString();
        $guideCountry = $request->string('guide_country')->toString();
        $contactCountry = $request->string('contact_country')->toString();

        $guides = SafetyGuide::query()
            ->with('country:id,iso2,name')
            ->when(in_array($hazard, SafetyGuide::HAZARD_TYPES, true), fn ($q) => $q->where('hazard_type', $hazard))
            ->when($guideCountry === 'all', fn ($q) => $q->whereNull('country_id'))
            ->when(ctype_digit($guideCountry), fn ($q) => $q->where('country_id', (int) $guideCountry))
            ->orderBy('hazard_type')->orderBy('sort_order')->orderBy('title')
            ->get()
            ->map(fn (SafetyGuide $g): array => [
                'id' => $g->id,
                'title' => $g->title,
                'slug' => $g->slug,
                'hazard_type' => $g->hazard_type,
                'summary' => $g->summary,
                'content' => $g->content,
                'country_id' => $g->country_id,
                'country' => $g->country?->name,
                'is_published' => $g->is_published,
                'sort_order' => $g->sort_order,
                'updated_at' => $g->updated_at?->toIso8601String(),
            ]);

        $contacts = EmergencyContact::query()
            ->with('country:id,iso2,name')
            ->when(ctype_digit($contactCountry), fn ($q) => $q->where('country_id', (int) $contactCountry))
            ->orderBy('country_id')->orderBy('sort_order')->orderBy('agency_name')
            ->get()
            ->map(fn (EmergencyContact $c): array => [
                'id' => $c->id,
                'country_id' => $c->country_id,
                'country' => $c->country?->name,
                'agency_name' => $c->agency_name,
                'category' => $c->category,
                'phone' => $c->phone,
                'alt_phone' => $c->alt_phone,
                'website' => $c->website,
                'notes' => $c->notes,
                'is_published' => $c->is_published,
                'sort_order' => $c->sort_order,
                'updated_at' => $c->updated_at?->toIso8601String(),
            ]);

        return Inertia::render('admin/Dashboard', [
            'overview' => [
                'users_total' => User::count(),
                'users_verified' => User::whereNotNull('email_verified_at')->count(),
                'users_admin' => User::where('role', 'admin')->count(),
                'guides_total' => SafetyGuide::count(),
                'guides_published' => SafetyGuide::published()->count(),
                'contacts_total' => EmergencyContact::count(),
                'contacts_published' => EmergencyContact::published()->count(),
            ],
            'guides' => $guides,
            'contacts' => $contacts,
            'countries' => Country::query()->where('is_active', true)->orderBy('sort_order')->get(['id', 'iso2', 'name']),
            'hazardTypes' => SafetyGuide::HAZARD_TYPES,
            'categories' => EmergencyContact::CATEGORIES,
            'filters' => ['hazard' => $hazard, 'guide_country' => $guideCountry, 'contact_country' => $contactCountry],
        ]);
    }
}
