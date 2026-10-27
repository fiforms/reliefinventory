<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Support;

/**
 * The ready-made warehouse signs on the Signs page (SignController). They are
 * code, not data: a template only fills in the sign form, which the person can
 * still change before printing, so nothing about a printed sign depends on a
 * template existing. Each one gives a title, optional smaller text, an optional
 * safety header (SignLayout::HEADERS), an orientation and any arrows
 * (SignLayout::ARROWS) it usually needs.
 *
 * These are workplace reminders, not code-compliant safety signage: a real exit
 * sign, fire-extinguisher marker or no-smoking notice the fire marshal requires
 * still has to be the approved kind, which is why there is no EXIT template.
 */
class SignTemplates
{
    /** @return array<string, array<int, array{key: string, title: string, subtitle: string, header: ?string, orientation: string, arrows: array<int, string>}>> */
    public static function groups(): array
    {
        $sign = fn (string $key, string $title, string $subtitle = '', ?string $header = null, string $orientation = 'landscape', array $arrows = []) => [
            'key' => $key, 'title' => $title, 'subtitle' => $subtitle, 'header' => $header,
            'orientation' => $orientation, 'arrows' => $arrows,
        ];

        return [
            'Areas & stations' => [
                $sign('receiving', 'RECEIVING', 'All incoming donations check in here'),
                $sign('donation-drop-off', 'DONATION DROP-OFF', 'Please wait here for a volunteer to help unload'),
                $sign('sorting', 'SORTING'),
                $sign('sorting-area', 'SORTED — WAITING TO BE PUT AWAY', 'Do not take items from this area for orders'),
                $sign('to-be-sorted', 'TO BE SORTED', 'Pallets waiting for the sorting line'),
                $sign('picking', 'ORDER PICKING'),
                $sign('ready-to-ship', 'READY TO SHIP', 'Filled orders waiting for pickup or delivery'),
                $sign('shipping', 'SHIPPING'),
                $sign('partner-pickup', 'PARTNER PICKUP', 'Check in at the office before loading'),
                $sign('loading-dock', 'LOADING DOCK'),
                $sign('office', 'OFFICE'),
                $sign('volunteer-sign-in', 'VOLUNTEER SIGN-IN', 'Everyone signs in and out, every time'),
                $sign('break-room', 'BREAK ROOM', 'No food or drinks on the warehouse floor'),
                $sign('restrooms', 'RESTROOMS'),
                $sign('first-aid', 'FIRST AID', 'Report every injury to a team leader', 'safety'),
            ],
            'Sorting bins' => [
                $sign('bin-new', 'NEW', 'Unopened, in original packaging', null, 'portrait'),
                $sign('bin-usable', 'USABLE', 'Clean, working and complete', null, 'portrait'),
                $sign('bin-outdated', 'OUTDATED', 'Past its expiration or best-by date', null, 'portrait'),
                $sign('bin-trash', 'TRASH', 'Damaged, dirty, opened or unsafe', null, 'portrait'),
                $sign('bin-divert', 'DIVERT', 'Good items we do not distribute — set aside for another agency', null, 'portrait'),
                $sign('bin-hold', 'HOLD — ASK A LEAD', 'Not sure what this is or where it goes? Put it here.', 'notice', 'portrait'),
                $sign('cardboard', 'CARDBOARD RECYCLING', 'Break down all boxes flat'),
                $sign('empty-pallets', 'EMPTY PALLETS', 'Return empty pallets here'),
            ],
            'Goods' => [
                $sign('goods-water', 'WATER'),
                $sign('goods-food', 'FOOD'),
                $sign('goods-hygiene', 'HYGIENE'),
                $sign('goods-cleaning', 'CLEANING SUPPLIES'),
                $sign('goods-baby', 'BABY SUPPLIES'),
                $sign('goods-paper', 'PAPER GOODS'),
                $sign('goods-bedding', 'BEDDING & LINENS'),
                $sign('goods-pet', 'PET SUPPLIES'),
                $sign('goods-tools', 'TOOLS & EQUIPMENT'),
            ],
            'Safety' => [
                $sign('authorized-only', 'AUTHORIZED PERSONNEL ONLY', 'Volunteers and staff beyond this point', 'warning'),
                $sign('forklift-traffic', 'WATCH FOR FORKLIFTS', 'Forklifts have the right of way', 'caution'),
                $sign('no-forklift-ride', 'NO RIDERS ON FORKLIFTS OR PALLET JACKS', '', 'danger'),
                $sign('closed-toe-shoes', 'CLOSED-TOE SHOES REQUIRED', 'On the warehouse floor at all times', 'notice'),
                $sign('team-lift', 'TEAM LIFT', 'Over 50 lb? Get help. Lift with your legs.', 'caution'),
                $sign('keep-clear', 'KEEP CLEAR', 'Do not block this exit, door or panel', 'warning'),
                $sign('wet-floor', 'WET FLOOR', '', 'caution', 'portrait'),
                $sign('no-smoking', 'NO SMOKING', 'Anywhere in or near the building', 'danger'),
                $sign('assembly-point', 'EMERGENCY ASSEMBLY POINT', 'Gather here if the building is evacuated', 'safety'),
                $sign('supervise-children', 'CHILDREN MUST BE SUPERVISED', 'Ask a team leader before bringing children onto the floor', 'notice'),
            ],
            'Donors & visitors' => [
                $sign('no-clothing', 'WE CANNOT ACCEPT CLOTHING', 'Thank you for thinking of us — please take clothing to a local thrift store', 'notice'),
                $sign('no-donations-today', 'NOT ACCEPTING DONATIONS TODAY', 'Please call before bringing donations', 'notice'),
                $sign('thank-you', 'THANK YOU!', 'Your donation helps families recover'),
                $sign('visitors-check-in', 'ALL VISITORS CHECK IN AT THE OFFICE', '', 'notice'),
                $sign('this-way', 'THIS WAY', '', null, 'landscape', ['right']),
                $sign('deliveries', 'DELIVERIES', 'Trucks use the dock entrance', null, 'landscape', ['right']),
                $sign('volunteer-parking', 'VOLUNTEER PARKING', '', null, 'landscape', ['right']),
                $sign('no-parking', 'NO PARKING', 'Truck lane — keep clear', 'warning'),
            ],
        ];
    }

    /** @return array<string, array> every template by key */
    public static function all(): array
    {
        return collect(self::groups())->flatten(1)->keyBy('key')->all();
    }
}
