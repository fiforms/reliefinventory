<?php

use App\Models\PeopleRoles;
use App\Models\SetupState;
use App\Models\User;
use App\Services\FirstRunService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

// tests/Pest.php marks setup complete for every feature test; these tests are
// about the wizard itself, so put the instance back into its fresh state.
beforeEach(function () {
    SetupState::current()->update(['completed_at' => null, 'demo_data_seeded_at' => null]);
});

function wizardPayload(array $overrides = []): array
{
    return array_merge([
        'first_name' => 'Pat',
        'last_name' => 'Admin',
        'email' => 'pat@example.com',
        'password' => 'a-good-password-123',
        'password_confirmation' => 'a-good-password-123',
    ], $overrides);
}

test('a fresh instance sends every page to the wizard', function () {
    $this->get('/')->assertRedirect(route('setup.show'));
    $this->get('/login')->assertRedirect(route('setup.show'));
    $this->get('/register')->assertRedirect(route('setup.show'));
    $this->get('/dashboard')->assertRedirect(route('setup.show'));
    $this->get('/install')->assertOk();
});

test('nothing but the wizard can write while setup is pending', function () {
    $this->post('/register', ['email' => 'sneaky@example.com'])->assertForbidden();
    $this->getJson('/json/people')->assertForbidden();

    expect(User::where('email', 'sneaky@example.com')->exists())->toBeFalse();
});

test('completing the wizard creates a working administrator and seeds the essentials', function () {
    $response = $this->post('/install', wizardPayload());

    $response->assertRedirect(route('dashboard'));
    $user = User::where('email', 'pat@example.com')->firstOrFail();

    expect(Hash::check('a-good-password-123', $user->password))->toBeTrue()
        ->and($user->email_verified_at)->not->toBeNull()
        ->and($user->approved_at)->not->toBeNull()
        ->and(PeopleRoles::where('person_id', $user->id)->where('role_id', FirstRunService::ADMINISTRATOR_ROLE_ID)->exists())->toBeTrue()
        ->and(DB::table('statuses')->exists())->toBeTrue()
        ->and(DB::table('categories')->exists())->toBeTrue()
        ->and(DB::table('itemtypes')->exists())->toBeTrue()
        ->and(SetupState::current()->completed_at)->not->toBeNull()
        ->and(SetupState::current()->demo_data_seeded_at)->toBeNull();

    $this->assertAuthenticatedAs($user);
    // the administrator can actually use the app
    $this->get('/dashboard')->assertOk();
    expect($user->fresh()->hasPermission('manage-people'))->toBeTrue();
});

test('the wizard closes for good once used', function () {
    $this->post('/install', wizardPayload())->assertRedirect(route('dashboard'));
    auth()->logout();

    $this->get('/install')->assertRedirect(route('login'));
    $this->post('/install', wizardPayload(['email' => 'second@example.com']))->assertRedirect(route('login'));
    expect(User::where('email', 'second@example.com')->exists())->toBeFalse();
    $this->get('/login')->assertOk();
});

test('deleting every administrator later does not reopen the wizard', function () {
    $this->post('/install', wizardPayload())->assertRedirect(route('dashboard'));
    auth()->logout();

    PeopleRoles::query()->delete();

    expect(app(FirstRunService::class)->needsSetup())->toBeFalse();
    $this->get('/install')->assertRedirect(route('login'));
});

test('an administrator created any other way also closes the wizard', function () {
    $this->artisan('admin:bootstrap', ['--email' => 'cli@example.com', '--password' => 'x-secret-12345'])->assertSuccessful();

    expect(app(FirstRunService::class)->needsSetup())->toBeFalse();
    $this->get('/install')->assertRedirect(route('login'));
});

test('the migration latches an instance that already has an administrator', function () {
    $migration = require database_path('migrations/2026_10_19_100000_create_setup_state_table.php');
    $migration->down();
    PeopleRoles::create(['person_id' => User::factory()->create()->id, 'role_id' => FirstRunService::ADMINISTRATOR_ROLE_ID]);

    $migration->up();

    expect(SetupState::current()->completed_at)->not->toBeNull();
});

test('validation errors keep the wizard open and create nothing', function () {
    $this->post('/install', wizardPayload(['password_confirmation' => 'different']))->assertSessionHasErrors('password');
    $this->post('/install', wizardPayload(['email' => 'not-an-email']))->assertSessionHasErrors('email');

    expect(User::where('email', 'pat@example.com')->exists())->toBeFalse()
        ->and(app(FirstRunService::class)->needsSetup())->toBeTrue();
});

test('a configured setup code is required', function () {
    config(['app.setup_code' => 'open-sesame']);

    $this->get('/install')->assertInertia(fn ($page) => $page->where('codeRequired', true));
    $this->post('/install', wizardPayload())->assertSessionHasErrors('setup_code');
    $this->post('/install', wizardPayload(['setup_code' => 'wrong']))->assertSessionHasErrors('setup_code');
    expect(User::where('email', 'pat@example.com')->exists())->toBeFalse();

    $this->post('/install', wizardPayload(['setup_code' => 'open-sesame']))->assertRedirect(route('dashboard'));
    expect(User::where('email', 'pat@example.com')->exists())->toBeTrue();
});

test('example data is loaded only when asked for', function () {
    $this->post('/install', wizardPayload(['example_data' => true]))->assertRedirect(route('dashboard'));

    expect(DB::table('orderdonations')->where('type', 'order')->exists())->toBeTrue()
        ->and(DB::table('orderdonations')->where('type', 'donation')->exists())->toBeTrue()
        ->and(SetupState::current()->demo_data_seeded_at)->not->toBeNull();
});

test('setup:seed-essentials is a no-op on an already seeded database', function () {
    $this->artisan('setup:seed-essentials')->expectsOutputToContain('seeded')->assertSuccessful();
    $statuses = DB::table('statuses')->count();

    $this->artisan('setup:seed-essentials')->expectsOutputToContain('already present')->assertSuccessful();
    expect(DB::table('statuses')->count())->toBe($statuses);
});

test('setup:status reports pending and complete', function () {
    $this->artisan('setup:status')->expectsOutput('needs-setup')->assertSuccessful();

    app(FirstRunService::class)->markComplete();
    $this->artisan('setup:status')->expectsOutput('complete')->assertSuccessful();
});
