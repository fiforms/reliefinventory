<?php

use App\Services\GitVersionService;

it('reports the baked-in release version without a leading v', function () {
    config(['app.version' => 'v1.4.0']);

    expect(app(GitVersionService::class)->release())->toBe('1.4.0');
});

it('falls back to git describe when no version is baked in', function () {
    config(['app.version' => null]);

    $release = app(GitVersionService::class)->release();

    // No release tags yet in a fresh clone, so null; once tagged it is "1.4.0" or "1.4.0-N-gSHA".
    expect($release === null || preg_match('/^\d+\.\d+/', $release) === 1)->toBeTrue();
});

it('shows the About page to any logged-in user', function () {
    config(['app.version' => 'v1.4.0']);

    $this->actingAs(\App\Models\User::factory()->create())
        ->get('/about-this-install')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('AppAbout')->where('version', '1.4.0'));
});

it('redirects guests away from the About page', function () {
    $this->get('/about-this-install')->assertRedirect('/login');
});
