<?php

use App\Models\Permission;
use App\Models\SetupState;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind a different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    // A fresh test database has no administrator, which would send every request
    // to the first-run wizard (RedirectToSetup). Treat setup as done unless a test
    // is specifically about the wizard (tests/Feature/SetupWizardTest.php).
    ->beforeEach(fn () => SetupState::current()->update(['completed_at' => now()]))
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

/**
 * Create a user granted exactly the given permission keys, via a direct
 * person_permissions override — deliberately not dependent on
 * PermissionsSeeder/role_permissions having been seeded, so each test's
 * access needs are self-contained and explicit rather than inherited from
 * default role grants that could drift.
 */
function userWithPermissions(string ...$keys): User
{
    $user = User::factory()->create();

    foreach ($keys as $key) {
        $permission = Permission::firstOrCreate(['key' => $key], ['name' => $key]);
        $user->person_permissions()->attach($permission->id, ['granted' => true]);
    }

    return $user;
}

// PDF inspection needs poppler-utils (pdfinfo/pdftotext); skip rather than fail where it's absent.
function pdfToolsMissing(): bool
{
    return trim((string) shell_exec('command -v pdfinfo pdftotext | wc -l')) !== '2';
}

function pdfInfo(string $pdf): string
{
    return (string) shell_exec('pdfinfo '.escapeshellarg($pdf));
}

function pdfText(string $pdf): string
{
    return (string) shell_exec('pdftotext -layout '.escapeshellarg($pdf).' -');
}
