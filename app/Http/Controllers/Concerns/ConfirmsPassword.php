<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Http\Controllers\Concerns;

use App\Models\Control\InstanceAuditLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * Instance Admin's rule for a change: the signed-in person's own password first (so a left-open screen can't be used), then the
 * action. Same behaviour as InstanceWarehouseController::guarded(), without a warehouse. The action returns the JSON body; its
 * RuntimeException / InvalidArgumentException is shown to the person as a 422, anything else is logged and reported as a failure.
 */
trait ConfirmsPassword
{
    /** @param  callable(): array  $action */
    protected function confirmed(Request $request, string $what, callable $action): JsonResponse
    {
        $request->validate(['password' => 'required|string']);
        $user = Auth::user();
        if (! $user->password || ! Hash::check($request->input('password'), $user->password)) {
            Log::warning('Instance change refused: wrong or missing password', ['person' => $user->id, 'action' => $what]);
            InstanceAuditLog::record('instance.password_refused', null, ['action' => $what], $user->email);
            $message = $user->password ? 'That password is not correct.' : 'Your account has no password set, so this cannot be done.';

            return response()->json(['message' => $message, 'errors' => ['password' => [$message]]], 422);
        }

        try {
            return response()->json($action())->header('Cache-Control', 'no-store, private');
        } catch (RuntimeException|InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (Throwable $e) {
            report($e);

            return response()->json(['message' => 'That did not work: '.$e->getMessage()], 500);
        }
    }
}
