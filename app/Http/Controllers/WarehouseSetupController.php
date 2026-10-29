<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.app)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Http\Controllers;

use App\Models\Accounts\Account;
use App\Models\Control\Warehouse;
use App\Services\FirstRunService;
use App\Services\PinLoginService;
use App\Services\Warehouses\SetupCodes;
use App\Services\Warehouses\WarehouseContext;
use App\Services\Warehouses\WarehouseSetup;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
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
    public function __construct(private WarehouseContext $context, private SetupCodes $codes) {}

    public function show(Request $request): Response|RedirectResponse
    {
        if (! ($warehouse = $this->awaiting())) {
            return redirect()->route('login');
        }

        return Inertia::render('Setup/Activate', [
            'warehouseName' => $warehouse->display_name,
            'claimed' => $this->codes->claimed($request, $warehouse) !== null,
            'locked' => $this->codes->locked($warehouse),
        ]);
    }

    public function code(Request $request): RedirectResponse
    {
        $warehouse = $this->awaiting() ?? abort(404);
        $data = $request->validate(['code' => ['required', 'string', 'max:40']]);

        $this->codes->guardAttempts($warehouse, 'code');
        $row = $this->codes->find($warehouse, $data['code']);
        if (! $row) {
            $this->codes->fail($warehouse);
            throw ValidationException::withMessages(['code' => 'That code is not correct, or it has expired. Ask whoever set up this warehouse for a new one.']);
        }

        $request->session()->regenerate();
        $request->session()->put(SetupCodes::CLAIM, $row->id);

        return redirect()->route('activate.show');
    }

    public function setup(Request $request, WarehouseSetup $setup, FirstRunService $firstRun, PinLoginService $pinLogin): RedirectResponse
    {
        $warehouse = $this->awaiting() ?? abort(404);
        $claim = $this->codes->claimed($request, $warehouse);
        if (! $claim) {
            return redirect()->route('activate.show')->withErrors(['code' => 'Enter the setup code first.']);
        }

        $existing = $request->input('login') === 'existing';
        $data = $request->validate(WarehouseSetup::personRules($existing) + ['example_data' => ['boolean']]);

        try {
            if ($existing) {
                $this->codes->guardAttempts($warehouse, 'password');
                $account = Account::verified($data['email'], $data['password']);
                if (! $account) {
                    $this->codes->fail($warehouse);
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
            $request->session()->forget(SetupCodes::CLAIM);

            return redirect()->route('activate.show')->withErrors(['code' => $e->getMessage()]);
        }

        $exampleFailed = ($data['example_data'] ?? false) && ! $firstRun->loadExampleData();

        $request->session()->forget(SetupCodes::CLAIM);
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
}
