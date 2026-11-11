<?php

use App\Mail\DisasterAlertMail;
use App\Mail\TestMail;
use App\Models\DisasterEvent;
use App\Models\User;

test('the logo file and the icons made from it exist', function () {
    [$width, $height] = getimagesize(public_path('DisasterLink_SEA_logo.png'));

    expect([$width, $height])->toBe([1024, 1024])
        ->and(file_exists(public_path('DisasterLink_SEA_logo.svg')))->toBeFalse();

    expect(getimagesize(public_path('favicon-32.png'))[0])->toBe(32)
        ->and(getimagesize(public_path('apple-touch-icon.png'))[0])->toBe(180)
        ->and(getimagesize(public_path('mail-logo.png'))[0])->toBe(240)
        ->and(file_exists(public_path('favicon.ico')))->toBeTrue()
        ->and(file_exists(public_path('favicon.svg')))->toBeFalse();
});

test('the logo is a PNG with an alpha channel, so its background is transparent', function () {
    $bytes = file_get_contents(public_path('DisasterLink_SEA_logo.png'), false, null, 0, 33);

    // PNG signature, then the IHDR chunk: colour type 6 is truecolour with alpha.
    expect(substr($bytes, 0, 8))->toBe("\x89PNG\r\n\x1a\n")
        ->and(ord($bytes[25]))->toBe(6);
});

test('every page head uses the DisasterLink icons, not the starter ones', function () {
    $this->get('/')->assertOk()
        ->assertSee('href="/favicon.ico"', false)
        ->assertSee('href="/favicon-32.png"', false)
        ->assertSee('href="/apple-touch-icon.png"', false)
        ->assertDontSee('favicon.svg', false);
});

test('the dark-mode logo variant is a transparent 1024 PNG next to the supplied logo', function () {
    $bytes = file_get_contents(public_path('DisasterLink_SEA_logo-dark.png'), false, null, 0, 33);

    expect(getimagesize(public_path('DisasterLink_SEA_logo-dark.png')))->toMatchArray([1024, 1024])
        ->and(substr($bytes, 0, 8))->toBe("\x89PNG\r\n\x1a\n")
        ->and(ord($bytes[25]))->toBe(6);
});

test('the shared logo component shows the supplied logo, switches to the dark variant, and has no background tile', function () {
    $component = file_get_contents(resource_path('js/components/BrandLogo.vue'));

    expect($component)
        ->toContain('src="/DisasterLink_SEA_logo.png"')
        ->toContain('src="/DisasterLink_SEA_logo-dark.png"')
        ->toContain('dark:hidden')
        ->toContain('hidden dark:block')
        ->toContain('forceDark')
        ->not->toContain('bg-slate-100')
        ->not->toContain('dark:bg-')
        ->not->toContain('p-0.5')
        ->not->toContain('rounded');
});

test('the always-dark login panel uses the dark logo without a tile', function () {
    $layout = file_get_contents(resource_path('js/layouts/auth/AuthDisasterLayout.vue'));

    expect($layout)->toContain('<BrandLogo force-dark class="size-12" />')
        ->and($layout)->not->toContain('bg-slate-100');
});

test('the header, footer, authentication layout and starter logo all use the logo component', function (string $file) {
    $source = file_get_contents(resource_path($file));

    expect($source)->toContain('<BrandLogo')->and($source)->toContain('@/components/BrandLogo.vue');
})->with([
    'js/components/disaster/DisasterHeader.vue',
    'js/components/disaster/DisasterFooter.vue',
    'js/layouts/auth/AuthDisasterLayout.vue',
    'js/components/AppLogoIcon.vue',
]);

test('the old shield tile is gone from the brand spots', function (string $file) {
    $source = file_get_contents(resource_path($file));

    expect($source)->not->toContain('<ShieldAlert class="size-5" />')
        ->and($source)->not->toContain('<ShieldAlert class="size-4" />')
        ->and($source)->not->toContain('<ShieldAlert class="size-6" />')
        ->and($source)->not->toContain('<ShieldAlert class="size-4.5" />');
})->with([
    'js/components/disaster/DisasterHeader.vue',
    'js/components/disaster/DisasterFooter.vue',
    'js/layouts/auth/AuthDisasterLayout.vue',
]);

test('the email header shows the DisasterLink logo and footer, not the Laravel ones', function () {
    config(['app.url' => 'https://alerts.example.org']);
    $mail = new TestMail;
    $html = $mail->render();

    // The HTML <title> still comes from APP_NAME (an .env value); the visible message does not.
    $body = preg_replace('#<head>.*?</head>#s', '', $html);

    expect($html)
        ->toContain('src="https://alerts.example.org/mail-logo.png"')
        ->toContain('alt="DisasterLink SEA"')
        ->toContain('DisasterLink SEA. All rights reserved.')
        ->not->toContain('notification-logo')
        ->and($body)->not->toContain('Laravel');

    $mail->assertSeeInText('DisasterLink SEA');
    $mail->assertDontSeeInText('Laravel');
});

test('a real alert email carries the same header and footer', function () {
    $event = DisasterEvent::factory()->gdacsAlert('Red')->create(['event_type' => 'cyclone']);
    $html = (new DisasterAlertMail($event, User::factory()->create()))->render();

    expect($html)->toContain('/mail-logo.png')->and($html)->toContain('DisasterLink SEA. All rights reserved.');
});
