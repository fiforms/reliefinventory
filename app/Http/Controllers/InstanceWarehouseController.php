<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Http\Controllers;

use App\Models\Control\InstanceAuditLog;
use App\Models\Control\Warehouse;
use App\Services\Warehouses\SetupCodes;
use App\Services\Warehouses\WarehouseCreator;
use App\Services\Warehouses\WarehouseDomains;
use App\Services\Warehouses\WarehouseManager;
use App\Services\Warehouses\WarehouseRemoval;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * Warehouse management on Instance Admin (/setup/instance, Warehouses tab): the `warehouse:*` commands as buttons. Instance
 * administrators only (route middleware), and every change re-asks for the signed-in person's password — the same gate as
 * revealing the warehouse key — because these actions take whole warehouses out of service or delete them. Reading the list needs
 * no password. Removal from here waits WarehouseManager::REMOVAL_WAIT_DAYS after archiving; the command doesn't.
 */
class InstanceWarehouseController extends Controller
{
    public function __construct(
        private WarehouseManager $manager,
        private SetupCodes $codes,
        private WarehouseDomains $domains,
    ) {}

    public function index(): JsonResponse
    {
        $rows = Warehouse::with('domains')->orderByDesc('is_default')->orderBy('created_at')->get()->map(function (Warehouse $w) {
            $removableOn = $this->manager->removableOn($w);

            return [
                'id' => $w->id,
                'name' => $w->display_name,
                'status' => $w->status,
                'is_default' => $w->is_default,
                'is_training' => $w->is_training,
                'listed' => $w->listed,
                'admin_email' => $w->admin_email,
                'notes' => $w->notes,
                'created_at' => $w->created_at?->toIso8601String(),
                'archived_at' => $w->archived_at?->toIso8601String(),
                'removable_on' => $removableOn?->toIso8601String(),
                'can_remove_now' => $w->status === 'archived' && ! $w->is_default && ! ($removableOn?->isFuture()),
                'setup_url' => $w->isAwaitingSetup() ? $w->setupUrl() : null,
                'setup_code_until' => $w->isAwaitingSetup() ? $this->codes->pending($w)?->expires_at?->toIso8601String() : null,
                'domains' => $w->domains->map(fn ($d) => ['hostname' => $d->hostname, 'primary' => (bool) $d->is_primary])->values(),
            ];
        });

        return response()->json(['warehouses' => $rows, 'removal_wait_days' => WarehouseManager::REMOVAL_WAIT_DAYS]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate(['name' => 'required|string|max:255', 'email' => 'nullable|email', 'training' => 'boolean']);

        return $this->guarded($request, 'create', null, function () use ($data) {
            set_time_limit(0); // creating a schema and migrating it takes a while
            [$warehouse, $code] = app(WarehouseCreator::class)->create($data['name'], $data['email'] ?? null, Auth::user()->email, (bool) ($data['training'] ?? false));

            return ['warehouse_id' => $warehouse->id, 'setup_code' => $code, 'setup_url' => $warehouse->setupUrl(), 'message' => "{$warehouse->display_name} is waiting to be set up. Copy the setup code now: it is not shown again."];
        });
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $data = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'email' => 'sometimes|nullable|email',
            'listed' => 'sometimes|boolean',
            'notes' => 'sometimes|nullable|string|max:2000',
        ]);

        return $this->guarded($request, 'update', $id, function (Warehouse $w) use ($data) {
            $changes = array_filter([
                'display_name' => $data['name'] ?? null,
                'listed' => $data['listed'] ?? null,
            ], fn ($v) => $v !== null);
            foreach (['email' => 'admin_email', 'notes' => 'notes'] as $field => $column) {
                if (array_key_exists($field, $data)) {
                    $changes[$column] = ($data[$field] ?? '') !== '' ? $data[$field] : null;
                }
            }
            $this->manager->update($w, $changes, Auth::user()->email);

            return ['message' => 'Saved.'];
        });
    }

    public function suspend(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['maintenance' => 'boolean', 'reason' => 'nullable|string|max:500']);

        return $this->guarded($request, 'suspend', $id, function (Warehouse $w) use ($data) {
            $this->manager->suspend($w, (bool) ($data['maintenance'] ?? false), $data['reason'] ?? null, Auth::user()->email);

            return ['message' => "{$w->display_name} is now {$w->status}: it answers nobody until it is activated."];
        });
    }

    public function activate(Request $request, string $id): JsonResponse
    {
        return $this->guarded($request, 'activate', $id, function (Warehouse $w) {
            $this->manager->activate($w, Auth::user()->email);

            return ['message' => "{$w->display_name} is active again."];
        });
    }

    public function resume(Request $request, string $id): JsonResponse
    {
        return $this->guarded($request, 'resume', $id, function (Warehouse $w) {
            $this->manager->resume($w, Auth::user()->email);

            return ['message' => "{$w->display_name} is back in service."];
        });
    }

    public function archive(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['undo' => 'boolean']);

        return $this->guarded($request, empty($data['undo']) ? 'archive' : 'unarchive', $id, function (Warehouse $w) use ($data) {
            $removal = app(WarehouseRemoval::class);
            if (empty($data['undo'])) {
                $removal->archive($w, Auth::user()->email);
                $days = WarehouseManager::REMOVAL_WAIT_DAYS;

                return ['message' => "{$w->display_name} is archived: it no longer serves anyone and nothing was deleted. It can be removed for good from {$days} days from now."];
            }
            $removal->unarchive($w, Auth::user()->email);

            return ['message' => "{$w->display_name} is {$w->status} again."];
        });
    }

    /** Remove an archived warehouse for good (final backup first). Needs the warehouse's name typed, and the wait to be over. */
    public function destroy(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['confirm' => 'required|string']);

        return $this->guarded($request, 'remove', $id, function (Warehouse $w) use ($data) {
            $this->manager->assertRemovalWaitOver($w);
            set_time_limit(0); // the final backup and the drop can take minutes
            $result = app(WarehouseRemoval::class)->remove($w, $data['confirm'], Auth::user()->email, true);

            return [
                'message' => "{$w->display_name} was removed.",
                'backup' => $result['backup'],
                'accounts_removed' => $result['accounts_removed'],
                'accounts_kept' => $result['accounts_kept'],
                'notes' => $result['notes'],
            ];
        });
    }

    public function setupCode(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['revoke' => 'boolean']);

        return $this->guarded($request, empty($data['revoke']) ? 'setup_code' : 'setup_code_revoke', $id, function (Warehouse $w) use ($data) {
            if (! empty($data['revoke'])) {
                $count = $this->manager->revokeSetupCode($w, Auth::user()->email);

                return ['message' => $count ? 'The setup code was revoked.' : 'No setup code was waiting.'];
            }

            return [
                'setup_code' => $this->manager->issueSetupCode($w, Auth::user()->email),
                'setup_url' => $w->setupUrl(),
                'message' => 'A new setup code (any earlier one no longer works). Copy it now: it is not shown again.',
            ];
        });
    }

    public function addDomain(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['hostname' => 'required|string|max:253', 'primary' => 'boolean']);

        return $this->guarded($request, 'domain_add', $id, function (Warehouse $w) use ($data) {
            $domain = $this->domains->add($w, $data['hostname'], (bool) ($data['primary'] ?? false));

            return ['message' => "{$domain->hostname} now reaches {$w->display_name}. Point DNS and TLS for it at this instance; the app can't do that for you.", 'note' => $this->domains->turnstileNote($domain)];
        });
    }

    public function removeDomain(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['hostname' => 'required|string|max:253']);

        return $this->guarded($request, 'domain_remove', $id, function (Warehouse $w) use ($data) {
            $this->domains->remove($w, $data['hostname']);

            return ['message' => "{$data['hostname']} no longer reaches {$w->display_name}."];
        });
    }

    /**
     * The one place a change is made: the signed-in person's password first (so a left-open screen can't be used), then the
     * warehouse, then the action. The action returns the JSON body; its RuntimeException / InvalidArgumentException is shown to
     * the person as a 422, anything else is logged and reported as a failure.
     *
     * @param  callable(?Warehouse): array  $action
     */
    private function guarded(Request $request, string $what, ?string $warehouseId, callable $action): JsonResponse
    {
        $request->validate(['password' => 'required|string']);
        $user = Auth::user();
        if (! $user->password || ! Hash::check($request->input('password'), $user->password)) {
            Log::warning('Warehouse management refused: wrong or missing password', ['person' => $user->id, 'action' => $what]);
            InstanceAuditLog::record('warehouse.password_refused', null, ['action' => $what, 'warehouse' => $warehouseId], $user->email);
            $message = $user->password ? 'That password is not correct.' : 'Your account has no password set, so this cannot be done.';

            return response()->json(['message' => $message, 'errors' => ['password' => [$message]]], 422);
        }

        $warehouse = null;
        if ($warehouseId !== null) {
            $warehouse = Warehouse::find(strtoupper($warehouseId));
            if (! $warehouse) {
                return response()->json(['message' => "There is no warehouse {$warehouseId}."], 404);
            }
        }

        try {
            return response()->json($action($warehouse))->header('Cache-Control', 'no-store, private');
        } catch (RuntimeException|InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (Throwable $e) {
            report($e);

            return response()->json(['message' => 'That did not work: '.$e->getMessage()], 500);
        }
    }
}
