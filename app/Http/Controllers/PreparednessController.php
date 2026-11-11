<?php

namespace App\Http\Controllers;

use App\Models\Country;
use App\Models\EmergencyContact;
use App\Models\SafetyGuide;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The public "Be Prepared" page: Emergency Contacts and Safety Guides on one page (HLD §8.3).
 *
 * The two filters are independent and live in the URL: `?country=TH` filters the contacts and
 * `?hazard=flood` filters the guides. Neither one reads the other.
 */
class PreparednessController extends Controller
{
    public function index(Request $request): Response
    {
        $countries = Country::query()->where('is_active', true)->orderBy('sort_order')->get(['id', 'iso2', 'name']);

        // A `country` parameter that is present (even empty) is the visitor's choice. Only when it is absent
        // does the signed-in user's saved country (`users.country_id`) become the default.
        $selectedCountry = $request->has('country')
            ? $countries->firstWhere('iso2', strtoupper(trim($this->queryText($request, 'country'))))
            : $countries->firstWhere('id', $request->user()?->country_id);

        $defaultCountry = $countries->firstWhere('id', $request->user()?->country_id);

        $requestedHazard = $this->queryText($request, 'hazard');
        $selectedHazard = in_array($requestedHazard, SafetyGuide::HAZARD_TYPES, true) ? $requestedHazard : null;

        $contacts = $selectedCountry === null ? collect() : EmergencyContact::query()
            ->published()
            ->where('country_id', $selectedCountry->id)
            ->orderBy('sort_order')->orderBy('agency_name')
            ->get()
            ->map(fn (EmergencyContact $c): array => [
                'id' => $c->id,
                'agency_name' => $c->agency_name,
                'category' => $c->category,
                'phone' => $c->phone,
                'phone_link' => EmergencyContact::telLink($c->phone),
                'alt_phone' => $c->alt_phone,
                'alt_phone_link' => $c->alt_phone ? EmergencyContact::telLink($c->alt_phone) : null,
                'website' => $c->website,
                'notes' => $c->notes,
            ]);

        // Published guides of the chosen type, whatever country the contacts show. A guide that belongs to a
        // country that is no longer active is hidden; global guides (no country) are always shown.
        $guides = SafetyGuide::query()
            ->published()
            ->with('country:id,iso2,name')
            ->where(fn ($q) => $q->whereNull('country_id')->orWhereHas('country', fn ($c) => $c->where('is_active', true)))
            ->when($selectedHazard, fn ($q) => $q->where('hazard_type', $selectedHazard))
            ->orderBy('sort_order')->orderBy('title')
            ->get()
            ->map(fn (SafetyGuide $g): array => [
                'id' => $g->id,
                'title' => $g->title,
                'slug' => $g->slug,
                'hazard_type' => $g->hazard_type,
                'summary' => $g->summary,
                'content_html' => $g->contentHtml(),
                'country' => $g->country?->name,
            ]);

        return Inertia::render('PreparednessPage', [
            'contacts' => $contacts,
            'guides' => $guides,
            'countries' => $countries,
            'selectedCountry' => $selectedCountry?->iso2,
            'defaultCountry' => $defaultCountry?->iso2,
            'selectedHazard' => $selectedHazard,
            'hazardTypes' => SafetyGuide::HAZARD_TYPES,
        ]);
    }

    /**
     * The old `/emergency-contacts` URL (it is in alert emails already sent). Keeps the `country` parameter.
     */
    public function legacyContacts(Request $request): RedirectResponse
    {
        return redirect()->to($this->target($request).'#contacts', 301);
    }

    /**
     * The old `/safety-guides` URL. Keeps `country`, which now filters the contacts section.
     */
    public function legacyGuides(Request $request): RedirectResponse
    {
        return redirect()->to($this->target($request).'#guides', 301);
    }

    /**
     * A query parameter as plain text. A repeated or array parameter (`?country[]=x`) counts as empty.
     */
    private function queryText(Request $request, string $key): string
    {
        $value = $request->query($key);

        return is_string($value) ? $value : '';
    }

    private function target(Request $request): string
    {
        $path = route('preparedness.index', [], false);

        return $request->has('country')
            ? $path.'?'.http_build_query(['country' => $this->queryText($request, 'country')])
            : $path;
    }
}
