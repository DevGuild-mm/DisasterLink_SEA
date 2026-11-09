<?php

use Illuminate\Support\Facades\Http;

test('returns a successful response', function () {
    Http::fake([
        '*' => Http::response([], 200),
    ]);

    $response = $this->get(route('home'));

    $response->assertOk();
});
