<?php

use App\Mail\DisasterAlertMail;
use App\Mail\TestMail;
use App\Models\Country;
use App\Models\DisasterAlertLog;
use App\Models\DisasterEvent;
use App\Models\User;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;

test('the command sends the test mail to the given address through the given mailer', function () {
    Mail::fake();

    $this->artisan('mail:test', ['to' => 'inbox@example.com', '--mailer' => 'log'])
        ->expectsOutputToContain("Test email handed to the 'log' mailer for inbox@example.com.")
        ->assertSuccessful();

    Mail::assertSent(TestMail::class, fn (TestMail $mail): bool => $mail->hasTo('inbox@example.com'));
    Mail::assertSentCount(1);
});

test('without an address it uses the configured sender address', function () {
    Mail::fake();
    config(['mail.from.address' => 'sender@example.org']);

    $this->artisan('mail:test')->assertSuccessful();

    Mail::assertSent(TestMail::class, fn (TestMail $mail): bool => $mail->hasTo('sender@example.org'));
});

test('an invalid address or an unknown mailer sends nothing and fails', function () {
    Mail::fake();

    $this->artisan('mail:test', ['to' => 'not-an-email'])->expectsOutputToContain('is not a valid email address')->assertFailed();
    $this->artisan('mail:test', ['to' => 'a@example.com', '--mailer' => 'nope'])->expectsOutputToContain("Mailer 'nope' is not defined")->assertFailed();

    Mail::assertNothingSent();
});

test('the log mailer is explained as not delivering', function () {
    Mail::fake();

    $this->artisan('mail:test', ['to' => 'a@example.com', '--mailer' => 'log'])
        ->expectsOutputToContain('written to storage/logs, not delivered')
        ->assertSuccessful();
});

test('host and port apply to the SMTP mailer for this run only and the password is never printed', function () {
    Mail::fake();
    config(['mail.mailers.smtp.password' => 'super-secret-password']);

    $this->artisan('mail:test', ['to' => 'a@example.com', '--mailer' => 'smtp', '--host' => '127.0.0.1', '--port' => '1025'])
        ->expectsOutputToContain('SMTP: 127.0.0.1:1025')
        ->doesntExpectOutputToContain('super-secret-password')
        ->assertSuccessful();

    expect(config('mail.mailers.smtp.host'))->toBe('127.0.0.1')->and(config('mail.mailers.smtp.port'))->toBe(1025);
});

test('a connection failure is reported with a Mailpit hint instead of a stack trace', function () {
    // Nothing listens on port 1 of this machine, so the connection is refused at once. No external traffic.
    $this->artisan('mail:test', ['to' => 'a@example.com', '--mailer' => 'smtp', '--host' => '127.0.0.1', '--port' => '1'])
        ->expectsOutputToContain('The test email could not be sent')
        ->expectsOutputToContain('Is Mailpit running?')
        ->assertFailed();
});

test('the test mail is the real alert email: same subject format, template and layout', function () {
    $mail = new TestMail;
    $html = $mail->render();

    expect($mail)->toBeInstanceOf(DisasterAlertMail::class)
        ->and($mail->envelope()->subject)->toBe('[DisasterLink SEA] Flood reported in Thailand: Orange')
        ->and($mail->content()->markdown)->toBe('mail.disaster-alert');

    expect($html)
        ->toContain('Flood reported in Thailand')
        ->toContain('Flooding along the Chao Phraya river affecting central Thailand')
        ->toContain('Event type:')
        ->toContain('Chao Phraya river basin, Central Thailand')
        ->toContain('Country:')
        ->toContain('Thailand')
        ->toContain('Coordinates:')
        ->toContain('14.350, 100.570')
        ->toContain('Alert level:')
        ->toContain('Orange')
        ->toContain('Occurred:')
        ->toContain('Asia/Bangkok')
        ->toContain('UTC')
        ->toContain('Source:')
        ->toContain('GDACS')
        ->toContain('Original report:')
        ->toContain('https://www.gdacs.org/')
        ->toContain('It does not mean your city or area is directly affected.')
        ->toContain('View on DisasterLink SEA')
        ->toContain('Emergency contacts')
        ->toContain('Safety guides')
        ->toContain('Manage alert emails in your account settings');
});

test('the email body does not look like a developer test', function () {
    $text = strip_tags((new TestMail)->render());

    expect($text)->not->toMatch('/\b(test|testing|preview|sample|dummy)\b/i')
        ->and($text)->not->toContain('Test Email')
        ->and($text)->not->toContain('This is a test');
});

test('the sample message is marked by a header, not in the body', function () {
    $headers = (new TestMail)->headers();

    expect($headers->text)->toBe(['X-DisasterLink-Sample' => 'alert']);
});

test('the test mail links to the real pages of the application', function () {
    $html = (new TestMail)->render();

    expect($html)
        ->toContain(route('disaster.events'))
        ->toContain(route('preparedness.index', ['country' => 'TH']))
        ->toContain(route('preparedness.index'))
        ->toContain(route('profile.edit'));
});

test('sending the test mail saves nothing, queues no alert and calls no provider', function () {
    Queue::fake();
    Bus::fake();
    Http::preventStrayRequests();
    Http::fake();
    Mail::fake();

    $this->artisan('mail:test', ['to' => 'a@example.com', '--mailer' => 'log'])->assertSuccessful();
    (new TestMail)->render();

    expect(DisasterEvent::count())->toBe(0)
        ->and(User::count())->toBe(0)
        ->and(DisasterAlertLog::count())->toBe(0);

    Queue::assertNothingPushed();
    Bus::assertNothingDispatched();
    Http::assertNothingSent();
    Mail::assertSentCount(1);
});

test('the real alert email is unchanged by the test mail', function () {
    $country = Country::factory()->create(['name' => 'Laos', 'timezone' => 'Asia/Vientiane']);
    $event = DisasterEvent::factory()->gdacsAlert('Red')->create(['country_id' => $country->id, 'country_status' => 'resolved', 'event_type' => 'cyclone', 'title' => 'Cyclone near Vientiane']);

    $html = (new DisasterAlertMail($event, User::factory()->create()))->render();

    expect($html)->toContain('Cyclone reported in Laos')->toContain('Cyclone near Vientiane')->toContain('Red');
});
