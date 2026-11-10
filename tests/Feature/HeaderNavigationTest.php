<?php

function headerSource(): string
{
    return file_get_contents(resource_path('js/components/disaster/DisasterHeader.vue'));
}

test('guest header offers Login and Register, with Register as the primary button', function () {
    $source = headerSource();

    expect($source)
        ->toContain('data-test="header-login"')
        ->toContain('data-test="header-register"')
        ->toContain('data-test="mobile-login"')
        ->toContain('data-test="mobile-register"')
        ->toMatch('/data-test="header-register"[^>]*bg-primary/s')
        ->toMatch('/data-test="header-login"[^>]*border-border/s');
});

test('the Admin link is in the account menu, not the main navigation', function () {
    $source = headerSource();

    expect($source)
        ->not->toContain("name: 'Admin'")
        ->toContain('data-test="account-admin"')
        ->toContain('v-if="isAdmin"')
        ->toContain('data-test="account-settings"')
        ->toContain('data-test="logout-button"');
});

test('the Reload button and sync chip only show on pages that pass data-as-of', function () {
    $source = headerSource();

    expect($source)
        ->toContain('const hasLiveData = computed(() => props.dataAsOf !== undefined)')
        ->toMatch('/v-if="hasLiveData"\s+data-test="sync-chip"/')
        ->toMatch('/v-if="hasLiveData"\s+variant="outline"\s+size="sm"\s+data-test="reload-data"/')
        ->toContain('<span class="hidden 2xl:inline">Reload data</span>');
});

test('the sync chip and countdown are hidden below 1280px and the chip shows only the time', function () {
    $source = headerSource();

    expect($source)
        ->toContain('text-[11px] text-muted-foreground xl:flex')
        ->toContain('hidden font-mono whitespace-nowrap xl:inline')
        ->not->toContain('Data as of {{ formatTime(dataAsOf ?? null) }}</span\n                        >')
        ->not->toContain("second: '2-digit'");
});

test('static pages do not pass data-as-of to the header', function (string $page) {
    $source = file_get_contents(resource_path("js/pages/{$page}"));

    expect($source)->not->toContain(':data-as-of');
})->with(['PreparednessPage.vue', 'admin/Dashboard.vue', 'reports/Create.vue', 'reports/MyReports.vue']);
