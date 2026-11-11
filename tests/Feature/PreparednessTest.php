<?php

use App\Models\Country;
use App\Models\EmergencyContact;
use App\Models\SafetyGuide;
use App\Models\User;
use Database\Seeders\CountrySeeder;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    (new CountrySeeder)->run();
});

function thId(): int
{
    return Country::where('iso2', 'TH')->value('id');
}

function vnId(): int
{
    return Country::where('iso2', 'VN')->value('id');
}

function titles(TestResponse $response, string $prop = 'guides', string $key = 'title'): array
{
    return collect($response->viewData('page')['props'][$prop])->pluck($key)->sort()->values()->all();
}

// ---- the one public page ----

test('the page renders the public component with both sections\' data', function () {
    $this->get('/preparedness')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('PreparednessPage')
        ->has('contacts')->has('guides')->has('countries', 11)
        ->where('selectedCountry', null)->where('defaultCountry', null)->where('selectedHazard', null)
        ->has('hazardTypes', 7));
});

test('the page has no admin controls and shows no draft content', function () {
    SafetyGuide::factory()->draft()->create(['title' => 'Secret draft']);
    EmergencyContact::factory()->draft()->create(['country_id' => thId(), 'agency_name' => 'Secret contact']);

    $response = $this->get('/preparedness?country=TH');

    expect(titles($response))->not->toContain('Secret draft')
        ->and(titles($response, 'contacts', 'agency_name'))->toBe([]);

    $page = file_get_contents(resource_path('js/pages/PreparednessPage.vue'))
        .file_get_contents(resource_path('js/components/preparedness/ContactsSection.vue'))
        .file_get_contents(resource_path('js/components/preparedness/GuidesSection.vue'));
    expect($page)->not->toContain('/admin')->and($page)->not->toContain('useForm');
});

// ---- contacts section ----

test('the contacts country is filtered by ?country and only published contacts show', function () {
    EmergencyContact::factory()->create(['country_id' => thId(), 'agency_name' => 'Thai police']);
    EmergencyContact::factory()->draft()->create(['country_id' => thId(), 'agency_name' => 'Hidden']);
    EmergencyContact::factory()->create(['country_id' => vnId(), 'agency_name' => 'Viet fire']);

    expect(titles($this->get('/preparedness?country=TH'), 'contacts', 'agency_name'))->toBe(['Thai police'])
        ->and(titles($this->get('/preparedness?country=th'), 'contacts', 'agency_name'))->toBe(['Thai police'])
        ->and(titles($this->get('/preparedness?country=VN'), 'contacts', 'agency_name'))->toBe(['Viet fire']);
});

test('a contact carries the fields the page shows, and the tel link holds only dialable characters', function () {
    EmergencyContact::factory()->create([
        'country_id' => thId(), 'agency_name' => 'DDPM', 'category' => 'disaster_agency', 'phone' => '+66 (2) 123-4567',
        'alt_phone' => '*911', 'website' => 'https://www.disaster.go.th', 'notes' => 'Open 24 hours',
    ]);

    $contact = $this->get('/preparedness?country=TH')->viewData('page')['props']['contacts'][0];

    expect($contact)->toMatchArray([
        'agency_name' => 'DDPM', 'category' => 'disaster_agency', 'phone' => '+66 (2) 123-4567', 'phone_link' => 'tel:+6621234567',
        'alt_phone' => '*911', 'alt_phone_link' => 'tel:*911', 'website' => 'https://www.disaster.go.th', 'notes' => 'Open 24 hours',
    ]);
});

test('contacts of an inactive country cannot be reached', function () {
    EmergencyContact::factory()->create(['country_id' => thId(), 'agency_name' => 'Thai police']);
    Country::where('iso2', 'TH')->update(['is_active' => false]);

    $this->get('/preparedness?country=TH')->assertInertia(fn (Assert $page) => $page
        ->where('selectedCountry', null)->has('contacts', 0)->has('countries', 10));
});

test('the default contacts country is the saved users.country_id', function () {
    EmergencyContact::factory()->create(['country_id' => vnId(), 'agency_name' => 'Viet fire']);
    EmergencyContact::factory()->create(['country_id' => thId(), 'agency_name' => 'Thai police']);
    $user = User::factory()->create(['country_id' => vnId()]);

    $this->actingAs($user)->get('/preparedness')->assertInertia(fn (Assert $page) => $page
        ->where('selectedCountry', 'VN')->where('defaultCountry', 'VN')->where('contacts.0.agency_name', 'Viet fire'));

    // An explicit ?country= overrides the saved country.
    $this->actingAs($user)->get('/preparedness?country=TH')->assertInertia(fn (Assert $page) => $page
        ->where('selectedCountry', 'TH')->where('defaultCountry', 'VN')->where('contacts.0.agency_name', 'Thai police'));
});

test('a signed-in user with a saved country can choose "no country" with an empty ?country=', function () {
    EmergencyContact::factory()->create(['country_id' => vnId()]);
    $user = User::factory()->create(['country_id' => vnId()]);

    $this->actingAs($user)->get('/preparedness?country=')->assertInertia(fn (Assert $page) => $page
        ->where('selectedCountry', null)->has('contacts', 0)->where('defaultCountry', 'VN'));
});

test('a user without a saved country, or a guest, gets the "Choose a country" state', function () {
    EmergencyContact::factory()->create(['country_id' => vnId()]);

    $this->get('/preparedness')->assertInertia(fn (Assert $page) => $page->where('selectedCountry', null)->has('contacts', 0));

    $this->actingAs(User::factory()->create(['country_id' => null]))->get('/preparedness')
        ->assertInertia(fn (Assert $page) => $page->where('selectedCountry', null)->where('defaultCountry', null)->has('contacts', 0));
});

test('an unknown country code shows the prompt, not an error', function () {
    $this->get('/preparedness?country=ZZ')->assertOk()->assertInertia(fn (Assert $page) => $page->where('selectedCountry', null)->has('contacts', 0));
});

test('a saved country that is now inactive is not used as the default', function () {
    $user = User::factory()->create(['country_id' => thId()]);
    Country::where('iso2', 'TH')->update(['is_active' => false]);

    $this->actingAs($user)->get('/preparedness')->assertInertia(fn (Assert $page) => $page->where('selectedCountry', null)->where('defaultCountry', null));
});

// ---- guides section ----

test('guides are filtered by disaster type only', function () {
    SafetyGuide::factory()->create(['title' => 'Quake', 'hazard_type' => 'earthquake']);
    SafetyGuide::factory()->create(['title' => 'Flood', 'hazard_type' => 'flood']);
    SafetyGuide::factory()->create(['title' => 'Flood TH', 'hazard_type' => 'flood', 'country_id' => thId()]);
    SafetyGuide::factory()->draft()->create(['title' => 'Draft flood', 'hazard_type' => 'flood']);

    expect(titles($this->get('/preparedness')))->toBe(['Flood', 'Flood TH', 'Quake'])
        ->and(titles($this->get('/preparedness?hazard=flood')))->toBe(['Flood', 'Flood TH'])
        ->and(titles($this->get('/preparedness?hazard=earthquake')))->toBe(['Quake'])
        ->and(titles($this->get('/preparedness?hazard=volcano')))->toBe([]);
});

test('an unknown hazard is treated as all types', function () {
    SafetyGuide::factory()->count(2)->create();

    $this->get('/preparedness?hazard=asteroid')->assertOk()->assertInertia(fn (Assert $page) => $page->where('selectedHazard', null)->has('guides', 2));
});

test('the contacts country never filters or hides guides', function () {
    SafetyGuide::factory()->create(['title' => 'Global', 'country_id' => null]);
    SafetyGuide::factory()->create(['title' => 'Thai', 'country_id' => thId()]);
    SafetyGuide::factory()->create(['title' => 'Viet', 'country_id' => vnId()]);
    $user = User::factory()->create(['country_id' => thId()]);

    $all = ['Global', 'Thai', 'Viet'];
    expect(titles($this->get('/preparedness')))->toBe($all)
        ->and(titles($this->get('/preparedness?country=TH')))->toBe($all)
        ->and(titles($this->get('/preparedness?country=VN')))->toBe($all)
        ->and(titles($this->get('/preparedness?country=')))->toBe($all)
        ->and(titles($this->get('/preparedness?country=ZZ')))->toBe($all)
        ->and(titles($this->actingAs($user)->get('/preparedness')))->toBe($all);
});

test('country-specific guides carry a country label and global guides do not', function () {
    SafetyGuide::factory()->create(['title' => 'Global', 'country_id' => null]);
    SafetyGuide::factory()->create(['title' => 'Thai', 'country_id' => thId()]);

    $guides = collect($this->get('/preparedness')->viewData('page')['props']['guides'])->keyBy('title');

    expect($guides['Global']['country'])->toBeNull()->and($guides['Thai']['country'])->toBe('Thailand');
});

test('a published guide of an inactive country is hidden, a global one is not, and an admin can still manage it', function () {
    SafetyGuide::factory()->create(['title' => 'Global', 'country_id' => null]);
    $guide = SafetyGuide::factory()->create(['title' => 'Thai', 'country_id' => thId()]);
    Country::where('iso2', 'TH')->update(['is_active' => false]);

    expect(titles($this->get('/preparedness')))->toBe(['Global']);

    $admin = User::factory()->create(['role' => 'admin']);
    $this->actingAs($admin)->get('/admin')->assertInertia(fn (Assert $page) => $page->has('guides', 2));
    $this->actingAs($admin)->delete("/admin/safety-guides/{$guide->id}")->assertRedirect();
    expect(SafetyGuide::count())->toBe(1);
});

test('guide Markdown is rendered without raw HTML or unsafe links', function () {
    SafetyGuide::factory()->create(['content' => "## Safe heading\n\n<script>alert(1)</script>\n\n<img src=x onerror=alert(2)>\n\n[click](javascript:alert(3)) and [fine](https://example.org)\n\n[data](data:text/html;base64,AAAA)"]);

    $html = $this->get('/preparedness')->viewData('page')['props']['guides'][0]['content_html'];

    expect($html)->toContain('<h2>Safe heading</h2>')
        ->and($html)->toContain('href="https://example.org"')
        ->and(strtolower($html))->not->toContain('<script')
        ->and(strtolower($html))->not->toContain('onerror')
        ->and(strtolower($html))->not->toContain('<img')
        ->and(strtolower($html))->not->toContain('javascript:')
        ->and(strtolower($html))->not->toContain('data:text');
});

// ---- independence and URL state ----

test('the two filters are independent and both survive in one URL', function () {
    EmergencyContact::factory()->create(['country_id' => thId(), 'agency_name' => 'Thai police']);
    SafetyGuide::factory()->create(['title' => 'Flood', 'hazard_type' => 'flood']);
    SafetyGuide::factory()->create(['title' => 'Quake', 'hazard_type' => 'earthquake']);

    $this->get('/preparedness?country=TH&hazard=flood')->assertInertia(fn (Assert $page) => $page
        ->where('selectedCountry', 'TH')->where('selectedHazard', 'flood')
        ->has('contacts', 1)->has('guides', 1)->where('guides.0.title', 'Flood'));

    // Same guide filter, other country: guides unchanged.
    $this->get('/preparedness?country=VN&hazard=flood')->assertInertia(fn (Assert $page) => $page
        ->where('selectedCountry', 'VN')->where('selectedHazard', 'flood')->has('contacts', 0)->has('guides', 1));

    // Same country, other guide filter: contacts unchanged.
    $this->get('/preparedness?country=TH&hazard=earthquake')->assertInertia(fn (Assert $page) => $page
        ->where('selectedCountry', 'TH')->has('contacts', 1)->where('guides.0.title', 'Quake'));
});

test('the same URL always gives the same state, so refresh and Back/Forward restore it', function () {
    EmergencyContact::factory()->create(['country_id' => vnId()]);
    SafetyGuide::factory()->create(['hazard_type' => 'flood']);

    $state = fn (string $url) => $this->get($url)->viewData('page')['props'];

    expect($state('/preparedness?country=VN&hazard=flood'))->toEqual($state('/preparedness?country=VN&hazard=flood'))
        ->and($state('/preparedness?hazard=flood&country=VN'))->toEqual($state('/preparedness?country=VN&hazard=flood'));
});

// ---- empty states ----

test('every empty state has the data the page needs to show it', function () {
    // 1. No country chosen.
    $this->get('/preparedness')->assertInertia(fn (Assert $page) => $page->where('selectedCountry', null)->has('contacts', 0));

    // 2. A country without published contacts.
    $this->get('/preparedness?country=TH')->assertInertia(fn (Assert $page) => $page->where('selectedCountry', 'TH')->has('contacts', 0));

    // 3. No guides at all.
    $this->get('/preparedness')->assertInertia(fn (Assert $page) => $page->has('guides', 0)->where('selectedHazard', null));

    // 4. A type without guides, while other types have some.
    SafetyGuide::factory()->create(['hazard_type' => 'flood']);
    $this->get('/preparedness?hazard=volcano')->assertInertia(fn (Assert $page) => $page->has('guides', 0)->where('selectedHazard', 'volcano'));
});

test('the empty-state messages exist in the components', function () {
    $contacts = file_get_contents(resource_path('js/components/preparedness/ContactsSection.vue'));
    $guides = file_get_contents(resource_path('js/components/preparedness/GuidesSection.vue'));

    expect($contacts)->toContain('Choose a country to see its emergency contacts.')
        ->and($contacts)->toContain('No emergency contacts have been published for this country yet.')
        ->and($guides)->toContain('No safety guides have been published yet.')
        ->and($guides)->toContain('No safety guides have been published for');
});

// ---- old URLs ----

test('the old public URLs are permanent redirects that keep the country', function (string $from, string $to) {
    $this->get($from)->assertStatus(301)->assertRedirect($to);
})->with([
    'contacts with country' => ['/emergency-contacts?country=ID', '/preparedness?country=ID#contacts'],
    'contacts without country' => ['/emergency-contacts', '/preparedness#contacts'],
    'contacts empty country' => ['/emergency-contacts?country=', '/preparedness?country=#contacts'],
    'guides' => ['/safety-guides', '/preparedness#guides'],
    'guides with country' => ['/safety-guides?country=TH', '/preparedness?country=TH#guides'],
    'other parameters are dropped' => ['/emergency-contacts?country=ID&utm=x&hazard=flood', '/preparedness?country=ID#contacts'],
]);

test('a redirect cannot be turned into an open redirect', function () {
    $this->get('/emergency-contacts?country=//evil.example')->assertStatus(301)
        ->assertRedirect('/preparedness?country=%2F%2Fevil.example#contacts');

    $this->get('/emergency-contacts?country[]=x')->assertStatus(301)->assertRedirect('/preparedness?country=#contacts');
});

test('array query parameters are treated as empty and never cause an error', function () {
    SafetyGuide::factory()->create();

    $this->get('/preparedness?country[]=TH&hazard[]=flood')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('selectedCountry', null)->where('selectedHazard', null)->has('guides', 1));
});

test('following an old email link ends on the page with the right contacts', function () {
    EmergencyContact::factory()->create(['country_id' => Country::where('iso2', 'ID')->value('id'), 'agency_name' => 'BNPB']);

    $this->followingRedirects()->get('/emergency-contacts?country=ID')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('PreparednessPage')->where('selectedCountry', 'ID')->where('contacts.0.agency_name', 'BNPB'));

    $this->followingRedirects()->get('/safety-guides')->assertOk()->assertInertia(fn (Assert $page) => $page->component('PreparednessPage'));
});

// ---- navigation ----

test('the header has one Be Prepared link to /preparedness and no separate old links', function () {
    $header = file_get_contents(resource_path('js/components/disaster/DisasterHeader.vue'));
    $footer = file_get_contents(resource_path('js/components/disaster/DisasterFooter.vue'));
    $welcome = file_get_contents(resource_path('js/pages/Welcome.vue'));

    expect($header)->toContain("href: '/preparedness'")
        ->and(substr_count($header, 'Be Prepared'))->toBe(1)
        ->and($header)->not->toContain('/safety-guides')->and($header)->not->toContain('/emergency-contacts')
        ->and($header)->not->toContain('Emergency Contacts');

    foreach ([$footer, $welcome] as $source) {
        expect($source)->not->toContain('"/safety-guides')->and($source)->not->toContain('/emergency-contacts');
    }
});

test('admin routes and authorization are unchanged', function () {
    $user = User::factory()->create();
    $guide = SafetyGuide::factory()->create();

    $this->post('/admin/safety-guides')->assertRedirect('/login');
    $this->actingAs($user)->post('/admin/safety-guides')->assertForbidden();
    $this->actingAs($user)->put("/admin/safety-guides/{$guide->id}")->assertForbidden();
    $this->actingAs($user)->get('/admin')->assertForbidden();
});

test('the public page and the admin dashboard render without the starter sidebar layout', function () {
    // Pages in this list bring their own DisasterHeader; any other page is wrapped in the sidebar app shell.
    $app = file_get_contents(resource_path('js/app.ts'));
    $list = substr($app, strpos($app, 'publicDisasterPages = new Set(['), 900);

    expect($list)->toContain("'PreparednessPage'")
        ->and($list)->toContain("'admin/Dashboard'")
        ->and($list)->not->toContain("'FreshnessPage'");

    // Every page component the new controllers render must be on that list.
    foreach (['PreparednessPage', 'admin/Dashboard'] as $component) {
        expect(file_exists(resource_path("js/pages/{$component}.vue")))->toBeTrue();
    }
});
