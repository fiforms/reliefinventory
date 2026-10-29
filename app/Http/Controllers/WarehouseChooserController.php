<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.app)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Http\Controllers;

use App\Models\Control\Warehouse;
use App\Models\Control\WarehouseDomain;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * The warehouse chooser on the login page (MULTI_WAREHOUSE.md §3), for an instance with several warehouses reached on its shared address.
 * It lists only warehouses marked `listed`, so a public page doesn't reveal which organizations share an instance; an unlisted one is
 * reached by its own hostname, a `?w=` link, or by typing its code (its id) here. A mapped hostname never shows a chooser: it is locked
 * to its warehouse.
 */
class WarehouseChooserController extends Controller
{
    /**
     * What the login page offers, or null when there is nothing to choose: one warehouse only, or a host that belongs to one.
     *
     * @return array{options: list<array{id:string,name:string}>}|null
     */
    public static function forLogin(Request $request): ?array
    {
        if (! Warehouse::where('status', '<>', 'archived')->where('id', '<>', Warehouse::current()->id)->exists()
            || WarehouseDomain::where('hostname', strtolower($request->getHost()))->exists()) {
            return null;
        }

        return ['options' => Warehouse::where('status', 'active')->where('listed', true)->orderBy('display_name')->get(['id', 'display_name'])
            ->map(fn (Warehouse $w) => ['id' => $w->id, 'name' => $w->display_name])->all()];
    }

    /**
     * Typed code → the warehouse it names, via the `?w=` landing (which sets the selector cookie). A code that names nothing reachable
     * gets the same answer whether it never existed or the warehouse isn't open (suspended, archived): this must not tell a stranger
     * which warehouses exist, so the page is rate-limited and the answer is plain.
     */
    public function choose(Request $request): RedirectResponse
    {
        $data = $request->validate(['code' => ['required', 'string', 'max:40']]);
        $code = preg_replace('/[^A-Za-z0-9]/', '', $data['code']);

        $warehouse = WarehouseDomain::where('hostname', strtolower($request->getHost()))->exists() ? null : Warehouse::findReachable($code);
        if (! $warehouse) {
            return back()->withErrors(['code' => 'No warehouse matches that code.']);
        }

        return redirect('/login?w='.$warehouse->id);
    }
}
