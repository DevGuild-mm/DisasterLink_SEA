<?php

use App\Models\Country;
use App\Models\EmergencyContact;
use App\Models\SafetyGuide;
use App\Models\User;
use Database\Seeders\CountrySeeder;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    (new CountrySeeder)->run();
});

function countryId(string $iso2): int
{
    return Country::where('iso2', $iso2)->value('id');
}

function validGuide(array $overrides = []): array
{
    return $overrides + [
        'title' => 'Earthquake safety',
        'hazard_type' => 'earthquake',
        'country_id' => null,
        'summary' => 'Drop, cover and hold on.',
        'content' => "## Before\n\n- Secure heavy furniture",
        'is_published' => true,
        'sort_order' => 0,
    ];
}

function validContact(array $overrides = []): array
{
    return $overrides + [
        'country_id' => countryId('TH'),
        'agency_name' => 'Department of Disaster Prevention',
        'category' => 'disaster_agency',
        'phone' => '1784',
        'alt_phone' => '',
        'website' => 'https://www.disaster.go.th',
        'notes' => 'Open 24 hours',
        'is_published' => true,
        'sort_order' => 1,
    ];
}

// ---- welcome previews ----

test('the welcome page previews up to 3 guides and, for a user with a country, up to 3 contacts', function () {
    SafetyGuide::factory()->count(5)->create();
    SafetyGuide::factory()->draft()->create();
    EmergencyContact::factory()->count(5)->create(['country_id' => countryId('TH')]);
    seedDashboardData();

    $this->get('/')->assertInertia(fn (Assert $page) => $page
        ->component('Welcome')
        ->has('guidesPreview', 3)
        ->has('contactsPreview', 0)
        ->where('contactsCountry', null)
        ->has('countries', 11));

    $user = User::factory()->create(['country_id' => countryId('TH')]);
    $this->actingAs($user)->get('/')->assertInertia(fn (Assert $page) => $page
        ->has('contactsPreview', 3)
        ->where('contactsCountry.iso2', 'TH'));
});

// ---- admin access ----

test('guests are sent to login and non-admins get 403 on every admin content route', function () {
    $user = User::factory()->create();
    $guide = SafetyGuide::factory()->create();
    $contact = EmergencyContact::factory()->create();

    $routes = [
        ['get', '/admin'],
        ['post', '/admin/safety-guides'],
        ['put', "/admin/safety-guides/{$guide->id}"],
        ['delete', "/admin/safety-guides/{$guide->id}"],
        ['post', '/admin/emergency-contacts'],
        ['put', "/admin/emergency-contacts/{$contact->id}"],
        ['delete', "/admin/emergency-contacts/{$contact->id}"],
    ];

    foreach ($routes as [$method, $url]) {
        $this->{$method}($url)->assertRedirect('/login');
    }

    $this->actingAs($user);

    foreach ($routes as [$method, $url]) {
        $this->{$method}($url)->assertForbidden();
    }

    expect(SafetyGuide::count())->toBe(1)->and(EmergencyContact::count())->toBe(1);
});

// ---- admin dashboard ----

test('the admin dashboard shows the counts and filters guides and contacts', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    User::factory()->unverified()->create();
    SafetyGuide::factory()->create(['hazard_type' => 'flood', 'country_id' => countryId('TH')]);
    SafetyGuide::factory()->draft()->create(['hazard_type' => 'earthquake', 'country_id' => null]);
    EmergencyContact::factory()->create(['country_id' => countryId('TH')]);
    EmergencyContact::factory()->draft()->create(['country_id' => countryId('VN')]);

    $this->actingAs($admin)->get('/admin')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('admin/Dashboard')
        ->where('overview.users_total', 2)
        ->where('overview.users_verified', 1)
        ->where('overview.users_admin', 1)
        ->where('overview.guides_total', 2)
        ->where('overview.guides_published', 1)
        ->where('overview.contacts_total', 2)
        ->where('overview.contacts_published', 1)
        ->has('guides', 2)
        ->has('contacts', 2)
        ->has('countries', 11));

    $this->actingAs($admin)->get('/admin?hazard=flood')->assertInertia(fn (Assert $page) => $page->has('guides', 1));
    $this->actingAs($admin)->get('/admin?guide_country=all')->assertInertia(fn (Assert $page) => $page->has('guides', 1)->where('guides.0.hazard_type', 'earthquake'));
    $this->actingAs($admin)->get('/admin?contact_country='.countryId('VN'))->assertInertia(fn (Assert $page) => $page->has('contacts', 1));
});

// ---- admin guides ----

test('an admin creates, edits, unpublishes and deletes a guide', function () {
    $admin = User::factory()->create(['role' => 'admin']);

    $this->actingAs($admin)->post('/admin/safety-guides', validGuide())->assertRedirect();
    $guide = SafetyGuide::sole();
    expect($guide->slug)->toBe('earthquake-safety')
        ->and($guide->created_by)->toBe($admin->id)
        ->and($guide->is_published)->toBeTrue();

    $this->actingAs($admin)->post('/admin/safety-guides', validGuide());
    expect(SafetyGuide::orderBy('id')->pluck('slug')->all())->toBe(['earthquake-safety', 'earthquake-safety-2']);

    $this->actingAs($admin)->put("/admin/safety-guides/{$guide->id}", validGuide(['title' => 'Renamed', 'is_published' => false]))->assertRedirect();
    $guide->refresh();
    expect($guide->title)->toBe('Renamed')->and($guide->slug)->toBe('earthquake-safety')->and($guide->is_published)->toBeFalse();

    $this->get('/preparedness')->assertInertia(fn (Assert $page) => $page->has('guides', 1));

    $this->actingAs($admin)->delete("/admin/safety-guides/{$guide->id}")->assertRedirect();
    expect(SafetyGuide::count())->toBe(1);
});

test('guide validation', function (array $override, string $field) {
    $admin = User::factory()->create(['role' => 'admin']);

    $this->actingAs($admin)->post('/admin/safety-guides', validGuide($override))->assertSessionHasErrors($field);

    expect(SafetyGuide::count())->toBe(0);
})->with([
    'no title' => [['title' => ''], 'title'],
    'unknown hazard' => [['hazard_type' => 'asteroid'], 'hazard_type'],
    'unknown country' => [['country_id' => 9999], 'country_id'],
    'no summary' => [['summary' => ''], 'summary'],
    'no content' => [['content' => ''], 'content'],
    'content too long' => [['content' => str_repeat('a', 20001)], 'content'],
    'negative order' => [['sort_order' => -1], 'sort_order'],
]);

test('a guide cannot point at an inactive country', function () {
    Country::where('iso2', 'TH')->update(['is_active' => false]);

    $this->actingAs(User::factory()->create(['role' => 'admin']))
        ->post('/admin/safety-guides', validGuide(['country_id' => countryId('TH')]))
        ->assertSessionHasErrors('country_id');
});

// ---- admin contacts ----

test('an admin creates, edits and deletes a contact', function () {
    $admin = User::factory()->create(['role' => 'admin']);

    $this->actingAs($admin)->post('/admin/emergency-contacts', validContact())->assertRedirect();
    $contact = EmergencyContact::sole();
    expect($contact->website)->toBe('https://www.disaster.go.th')->and($contact->is_published)->toBeTrue();

    $this->actingAs($admin)->put("/admin/emergency-contacts/{$contact->id}", validContact(['phone' => '1669', 'website' => null, 'is_published' => false]))->assertRedirect();
    expect($contact->refresh()->phone)->toBe('1669')->and($contact->website)->toBeNull()->and($contact->is_published)->toBeFalse();

    $this->actingAs($admin)->delete("/admin/emergency-contacts/{$contact->id}")->assertRedirect();
    expect(EmergencyContact::count())->toBe(0);
});

test('contact validation', function (array $override, string $field) {
    $admin = User::factory()->create(['role' => 'admin']);

    $this->actingAs($admin)->post('/admin/emergency-contacts', validContact($override))->assertSessionHasErrors($field);

    expect(EmergencyContact::count())->toBe(0);
})->with([
    'no country' => [['country_id' => null], 'country_id'],
    'unknown country' => [['country_id' => 9999], 'country_id'],
    'no agency' => [['agency_name' => ''], 'agency_name'],
    'unknown category' => [['category' => 'pizza'], 'category'],
    'no phone' => [['phone' => ''], 'phone'],
    'letters in phone' => [['phone' => 'call me'], 'phone'],
    'script in phone' => [['phone' => '<script>1</script>'], 'phone'],
    'bad alt phone' => [['alt_phone' => 'abc'], 'alt_phone'],
    'http website' => [['website' => 'http://insecure.example'], 'website'],
    'javascript website' => [['website' => 'javascript:alert(1)'], 'website'],
    'not a url' => [['website' => 'www.example.com'], 'website'],
    'notes too long' => [['notes' => str_repeat('n', 1001)], 'notes'],
]);

test('short codes and star numbers are valid phones', function (string $phone) {
    $this->actingAs(User::factory()->create(['role' => 'admin']))
        ->post('/admin/emergency-contacts', validContact(['phone' => $phone]))
        ->assertSessionHasNoErrors();
})->with(['112', '*911', '+66 2 123 4567', '(02) 123-4567']);
