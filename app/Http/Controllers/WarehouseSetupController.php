<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Http\Controllers;

use App\Models\Accounts\Account;
use App\Models\Control\SetupCode;
use App\Models\Control\Warehouse;
use App\Services\FirstRunService;
use App\Services\PinLoginService;
use App\Services\Warehouses\SetupCodes;
use App\Services\Warehouses\WarehouseContext;
use App\Services\Warehouses\WarehouseSetup;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

/**
 * The screen a warehouse shows while it waits for its setup code (/activate, MULTI_WAREHOUSE.md §7): enter the code, then
 * set the warehouse up (a new login or an existing one becomes its Administrator). ResolveWarehouse lets an
 * awaiting-setup warehouse answer nothing else. Entering the right code puts a claim in the session; every later
 * step checks the code is still usable, so revoking it takes effect at once.
 */
class WarehouseSetupController extends Controller
{
    private const CLAIM = 'setup_claim';

    public function __construct(private WarehouseContext $context, private SetupCodes $codes) {}

    public function show(Request $request): Response|RedirectResponse
    {
        if (! ($warehouse = $this->awaiting())) {
            return redirect()->route('login');
        }

        return Inertia::render('Setup/Activate', [
            'warehouseName' => $warehouse->display_name,
            'claimed' => $this->claim($request, $warehouse) !== null,
            'locked' => $this->locked($warehouse),
        ]);
    }

    public function code(Request $request): RedirectResponse
    {
        $warehouse = $this->awaiting() ?? abort(404);
        $data = $request->validate(['code' => ['required', 'string', 'max:40']]);

        $this->guardAttempts($warehouse, 'code');
        $row = $this->codes->find($warehouse, $data['code']);
        if (! $row) {
            $this->fail($warehouse);
            throw ValidationException::withMessages(['code' => 'That code is not correct, or it has expired. Ask whoever set up this warehouse for a new one.']);
        }

        $request->session()->regenerate();
        $request->session()->put(self::CLAIM, $row->id);

        return redirect()->route('activate.show');
    }

    public function setup(Request $request, WarehouseSetup $setup, FirstRunService $firstRun, PinLoginService $pinLogin): RedirectResponse
    {
        $warehouse = $this->awaiting() ?? abort(404);
        $claim = $this->claim($request, $warehouse);
        if (! $claim) {
            return redirect()->route('activate.show')->withErrors(['code' => 'Enter the setup code first.']);
        }

        $existing = $request->input('login') === 'existing';
        $data = $request->validate([
            'login' => ['required', 'in:new,existing'],
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255'],
            'password' => $existing ? ['required', 'string'] : ['required', 'confirmed', Password::defaults()],
            'example_data' => ['boolean'],
        ]);

        try {
            if ($existing) {
                $this->guardAttempts($warehouse, 'password');
                $account = $setup->verifiedAccount($data['email'], $data['password']);
                if (! $account) {
                    $this->fail($warehouse);
                    throw ValidationException::withMessages(['password' => 'That email and password do not match a login.']);
                }
                $user = $setup->withExistingAccount($warehouse, $claim, $account, $data);
            } else {
                if (Account::findByEmail($data['email'])) {
                    throw ValidationException::withMessages(['email' => 'That email already has a login. Choose "I already have a login" instead.']);
                }
                $user = $setup->withNewAccount($warehouse, $claim, $data);
            }
        } catch (RuntimeException $e) {
            $request->session()->forget(self::CLAIM);

            return redirect()->route('activate.show')->withErrors(['code' => $e->getMessage()]);
        }

        $exampleFailed = ($data['example_data'] ?? false) && ! $firstRun->loadExampleData();

        $request->session()->forget(self::CLAIM);
        Auth::login($user);
        $request->session()->regenerate();
        $pinLogin->grantTrust($pinLogin->currentDevice($request), $user->id);

        return redirect()->route('dashboard')->with(
            'status',
            $exampleFailed
                ? 'This warehouse is ready, but the example data could not be loaded (see the server log).'
                : 'This warehouse is ready. Welcome to Relief Inventory!'
        );
    }

    private function awaiting(): ?Warehouse
    {
        $warehouse = $this->context->current();

        return $warehouse->isAwaitingSetup() ? $warehouse : null;
    }

    /** The code row this session proved it holds, if it is still usable. */
    private function claim(Request $request, Warehouse $warehouse): ?SetupCode
    {
        $id = $request->session()->get(self::CLAIM);

        return $id ? SetupCode::usable()->where('warehouse_id', $warehouse->id)->whereKey($id)->first() : null;
    }

    /** Wrong tries are counted for the whole warehouse, not per visitor: guessing a code from many addresses is no easier. */
    private function key(Warehouse $warehouse): string
    {
        return "warehouse-setup:{$warehouse->id}";
    }

    private function locked(Warehouse $warehouse): bool
    {
        return RateLimiter::tooManyAttempts($this->key($warehouse), (int) config('warehouses.setup_attempts'));
    }

    private function guardAttempts(Warehouse $warehouse, string $field): void
    {
        if ($this->locked($warehouse)) {
            $minutes = (int) ceil(RateLimiter::availableIn($this->key($warehouse)) / 60);
            throw ValidationException::withMessages([$field => "Too many wrong tries. Wait {$minutes} minute(s) and try again."]);
        }
    }

    private function fail(Warehouse $warehouse): void
    {
        RateLimiter::hit($this->key($warehouse), (int) config('warehouses.setup_attempt_minutes') * 60);
    }
}
