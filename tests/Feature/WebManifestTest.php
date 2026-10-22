<?php

use App\Models\SetupState;

test('the web app manifest names this instance and lists icons that exist', function () {
    config(['app.name' => 'Relief Inventory Test Site']);

    $response = $this->get('/manifest.webmanifest');

    $response->assertOk()
        ->assertHeader('Content-Type', 'application/manifest+json')
        ->assertJsonPath('name', 'Relief Inventory Test Site')
        ->assertJsonPath('display', 'standalone');
    expect($response->headers->getCookies())->toBeEmpty();

    foreach ($response->json('icons') as $icon) {
        expect(public_path(ltrim($icon['src'], '/')))->toBeFile();
    }
    expect(public_path('icons/apple-touch-icon.png'))->toBeFile();
});

test('the manifest is served before first-run setup too', function () {
    SetupState::current()->update(['completed_at' => null]);

    $this->get('/manifest.webmanifest')->assertOk();
});

test('every page links the manifest and the home-screen icon', function () {
    $this->get('/')
        ->assertSee('rel="manifest" href="/manifest.webmanifest"', false)
        ->assertSee('rel="apple-touch-icon"', false);
});
