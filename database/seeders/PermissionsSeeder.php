<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;

/**
 * One permission per resource (manage-* for the volunteer-tier CRUD that
 * used to be role:4, admin-* for the destructive/structural ops that used
 * to be role:32768), plus general-access for the handful of placeholder/
 * non-resource pages that don't touch any specific resource yet.
 *
 * Default role grants preserve today's effective access exactly: Volunteer
 * and Team Leader get every manage-* key (matching role:4 today — they're
 * identical for now since nothing currently distinguishes them beyond
 * role_bitpack magnitude; a real difference can be layered in later via
 * person_permissions or additional role grants once there's a concrete
 * need). Administrator gets everything (matching role:32768 + role:4
 * today, since an admin always passed both). Partner/Donor get nothing
 * (they never corresponded to a route gate).
 *
 * view-dashboard is the first actual Volunteer/Team Leader split: the
 * internal Warehouse Dashboard is meant for management, not general
 * volunteer access, so it's granted to Team Leader (+ Administrator) on
 * top of the shared volunteer tier rather than folded into it.
 * view-sitrep (the external Situation Report) is intentionally granted to
 * nobody but Administrator by default — it's meant for lightweight
 * external stakeholder accounts (FEMA/state liaisons) granted individually
 * via the existing per-person permission override, not a role bundle.
 *
 * manage-trusted-devices follows the same not-in-any-role-bundle pattern
 * as view-sitrep: approving a device for PIN unlock is meant to be
 * delegable to a specific trusted person without handing them full
 * admin-system access, via a per-person override — not something every
 * Administrator-tier bundle should carry by default just because it's
 * security-adjacent. The PIN-login feature's global on/off/trust-mode
 * settings stay gated on the existing admin-system key instead — that's
 * genuinely system-wide configuration, not a per-device decision.
 */
class PermissionsSeeder extends Seeder
{
    private const PERMISSIONS = [
        'general-access' => 'Access placeholder/non-resource pages not yet tied to a specific permission',
        'manage-people' => 'View, create, and update people (partners/donors)',
        // Deleting a person destroys source/donor traceability — a records
        // issue, not an access-control one — so this stays a standalone,
        // Administrator-only-by-default key rather than folding into
        // manage-people. Expected to be replaced by deactivate/hide as part
        // of the Partner/Donor rework (PROJECT_ANALYSIS.md Part 12), not
        // widened.
        'admin-people' => 'Delete people',
        'manage-orders' => 'View, update, and delete orders/donations',
        'manage-items' => 'Manage the item catalog',
        'manage-units' => 'Manage units of measure',
        'manage-categories' => 'View, create, update, and delete item categories',
        'manage-locations' => 'View, create, update, and delete warehouse locations',
        'manage-warehouses' => 'View, create, update, and delete warehouses',
        'manage-uses' => 'View, create, update, and delete location uses',
        'manage-itemtypes' => 'View, create, update, and delete item types',
        'manage-packagetypes' => 'View, create, update, and delete package types',
        'manage-sorting' => 'Run donation sorting sessions',
        'manage-receiving' => 'Record dock-side intake and manage donation close-out',
        // Recording a phoned-in offer stays on manage-receiving above —
        // anyone answering the phone can log a call. This key is only the
        // decision (approve/refuse/divert/cancel) and matching an arrival
        // to a pending offer, deliberately not bundled with manage-receiving
        // and not granted to Volunteer/Team Leader/Sorting and Inventory by
        // default — the office team is who actually fields these calls.
        'manage-donation-offers' => 'Approve, refuse, divert, or cancel donation offers, and match arrivals to pending offers',
        'manage-pallets' => 'Manage pallets and pallet status history',
        'manage-trucks' => 'Manage trucks',
        'manage-containers' => 'View, create, update, and delete generic containers and container types',
        'manage-streams' => 'View, create, update, and delete pickup streams',
        'manage-roles' => 'View, create, update, and delete roles',
        'manage-counties' => 'Create, update, and delete counties',
        'admin-system' => 'System administration: software updates, backups, and the backup schedule',
        'view-reports' => 'View inventory and operational reports',
        'view-dashboard' => 'View the internal warehouse activity dashboard (full detail)',
        'view-sitrep' => 'View and export the external Situation Report (restricted, no names/PII)',
        'manage-trusted-devices' => 'Approve, label, and revoke devices allowed to use PIN unlock',
        'manage-feedback' => 'View and manage in-app bug/feature reports, and configure the site banner',
        'manage-users' => 'Create, promote, and deactivate login-capable accounts (User Administration)',
        // Administrator-only by default (not in any bundle below), same as
        // manage-users/admin-system — a bad import can write a lot of
        // wrong data quickly, given the blast radius.
        'manage-import' => 'Upload, preview, and commit external data imports',
        'admin-import' => 'View and delete import batch history',
        // Configuring the Sign-in Kiosk feature itself (locations, guest
        // types, idle-reset behavior) — deliberately separate from
        // operate-kiosk below (see permissions-tied-to-function-not-identity
        // memory): this is system-wide setup, operate-kiosk is day-to-day
        // use of it. Administrator-only by default, moved off admin-system
        // so it's individually delegable.
        'manage-kiosk' => 'Configure Sign-in Kiosk settings: locations, guest types, and behavior',
        // Renamed from operate-volunteer-kiosk (2026-09-02) — "volunteer" is
        // a person status (people.is_volunteer), not a role, and permissions
        // are tied to what someone does, not who they are; the kiosk this
        // gates was itself renamed from "Volunteer Kiosk" to "Sign-in
        // Kiosk" for the same reason. Narrowed from an earlier "broad
        // volunteer tier" scoping (see the 2026_08_23_000007 rename
        // migration) — this now covers declaring official building-safety
        // state (closeout, starting/closing a roll call) on top of
        // ordinary kiosk enable/disable, so it's Office/Administrator by
        // default rather than every front-line role, with per-person
        // grants (e.g. a night security officer with no other role) for
        // anyone else who needs it. Certifying a batch of hours is the
        // separate FEMA-compliance-critical step (see PROJECT_ANALYSIS.md
        // ~line 258).
        'operate-kiosk' => 'Put a device into or out of Sign-in Kiosk mode, and confirm building-safety state (closeout, roll call)',
        'certify-volunteer-hours' => 'Certify volunteer hours for FEMA reporting',
        // Deliberately broad (base volunteer tier, not just Office/Admin)
        // — this only grants viewing the occupancy roster and marking
        // people safe during an active roll call, not declaring building
        // state, so it's meant to reach whoever might actually be
        // fleeing a building and checking a roster from their phone, not
        // just management.
        'view-building-occupancy' => 'View who is currently in the building, and mark people safe during an active roll call',
        // Schema-changing (question types/options, approval wiring) —
        // Administrator-only by default, same blast-radius reasoning as
        // manage-users/manage-import.
        'manage-forms' => 'Build and edit survey/questionnaire forms',
        // Deliberately separate from manage-forms, same split as
        // manage-donation-offers/manage-receiving: reviewing/deciding on
        // submitted answers (e.g. partner-agency applications) is a
        // different job than building the form itself. Office by default —
        // the team that already triages Donation Offer calls.
        'review-form-submissions' => 'View, approve, and deny form submissions',
    ];

    private const VOLUNTEER_TIER_KEYS = [
        'general-access', 'manage-people', 'manage-orders', 'manage-items', 'manage-units',
        'manage-categories', 'manage-locations', 'manage-warehouses', 'manage-uses',
        'manage-itemtypes', 'manage-packagetypes', 'manage-sorting', 'manage-receiving',
        'manage-pallets', 'manage-trucks', 'manage-containers', 'manage-streams',
        'manage-roles', 'manage-counties', 'view-reports', 'view-building-occupancy',
    ];

    private const TEAM_LEADER_EXTRA_KEYS = [
        'view-dashboard',
    ];

    /**
     * Office (TODO.md item 1 — "everything except admin/setup roles"):
     * same effective bundle as today's Volunteer + Team Leader tiers,
     * just under a name that reflects what it actually grants rather than
     * the legacy role_bitpack-era naming. Deliberately not called "Office
     * Staff" — see the 2026-08-18 rename migration's doc comment: "staff"
     * implies paid employment, out of scope for this app. Whether someone
     * is a volunteer is tracked separately on the person record
     * (people.is_volunteer), independent of which permission role they
     * hold — a volunteer can be the office manager or an administrator.
     */
    private const OFFICE_KEYS = [
        ...self::VOLUNTEER_TIER_KEYS,
        ...self::TEAM_LEADER_EXTRA_KEYS,
        'manage-donation-offers',
        'certify-volunteer-hours',
        'operate-kiosk',
        'review-form-submissions',
    ];

    /**
     * Sorting and Inventory (TODO.md item 1): warehouse-side work only —
     * receiving, sorting, pallets, the item/location catalog, and donor
     * lookup (manage-people) for intake. No manage-orders (ordering isn't
     * this role's job) and no manage-roles.
     */
    private const SORTING_INVENTORY_KEYS = [
        'general-access', 'manage-people', 'manage-items', 'manage-units',
        'manage-categories', 'manage-locations', 'manage-warehouses', 'manage-uses',
        'manage-itemtypes', 'manage-packagetypes', 'manage-sorting', 'manage-receiving',
        'manage-pallets', 'manage-trucks', 'manage-containers', 'manage-streams',
        'manage-counties', 'view-reports', 'view-building-occupancy',
    ];

    public function run(): void
    {
        $permissions = collect(self::PERMISSIONS)->map(
            fn ($description, $key) => Permission::firstOrCreate(['key' => $key], ['name' => $key, 'description' => $description])
        );

        $volunteer = Role::where('name', 'Volunteer')->first();
        $teamLeader = Role::where('name', 'Team Leader')->first();
        $administrator = Role::where('name', 'Administrator')->first();
        $office = Role::where('name', 'Office')->first();
        $sortingInventory = Role::where('name', 'Sorting and Inventory')->first();

        $volunteerTier = $permissions->only(self::VOLUNTEER_TIER_KEYS)->pluck('id');
        $teamLeaderTier = $volunteerTier->merge($permissions->only(self::TEAM_LEADER_EXTRA_KEYS)->pluck('id'));

        if ($volunteer) {
            $volunteer->permissions()->syncWithoutDetaching($volunteerTier);
        }
        if ($teamLeader) {
            $teamLeader->permissions()->syncWithoutDetaching($teamLeaderTier);
        }

        if ($office) {
            $office->permissions()->syncWithoutDetaching($permissions->only(self::OFFICE_KEYS)->pluck('id'));
        }
        if ($sortingInventory) {
            $sortingInventory->permissions()->syncWithoutDetaching($permissions->only(self::SORTING_INVENTORY_KEYS)->pluck('id'));
        }

        if ($administrator) {
            $administrator->permissions()->syncWithoutDetaching($permissions->pluck('id'));
        }
    }
}
