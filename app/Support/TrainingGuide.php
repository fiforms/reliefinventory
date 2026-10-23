<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Support;

use App\Services\TrainingMode;

/**
 * The Training Mode class plan: six stations, five teams, two 50-minute segments. Team N starts at station N
 * (station 6 is empty in the first slot) and every slot each team moves on one station (6 -> 1), so a segment is
 * one full rotation, about 8 minutes per station. Segment 1 does each station's Round 1 activity, segment 2 its
 * Round 2. This is the one source for every printed team sheet (reports/training/team-sheet.blade.php) — edit the
 * activities here.
 */
class TrainingGuide
{
    public const SEGMENT_MINUTES = 50;

    /** Station number => [page, round 1 steps, round 2 steps]. Each round is about 6 minutes of hands-on work. */
    public const ACTIVITIES = [
        1 => [
            'page' => 'Receiving',
            'round1' => [
                'title' => 'Receive a delivery',
                'steps' => [
                    'Open Receiving and tap Record an Intake.',
                    'Check the offer list: if this delivery matches a phoned-in offer, pick it.',
                    'Choose the donor, the driver and how it arrived, then count the containers (pallets, boxes, bags).',
                    'Next, add a photo of the load (or skip it), then Print Labels.',
                    'Put a label on each pallet or container and move it to the sorting area.',
                ],
            ],
            'round2' => [
                'title' => 'Log a phoned-in offer, then receive it',
                'steps' => [
                    'Open Receiving > Donation Offers and tap Log a Donation Offer.',
                    'A trainer reads you a donor\'s phone call: enter the donor, what they are offering and when it will arrive.',
                    'Approve the offer so it waits for arrival (Pending).',
                    'When the props arrive, Record an Intake and match it to that offer, then print and apply the labels.',
                ],
            ],
        ],
        2 => [
            'page' => 'Donation Sorting',
            'round1' => [
                'title' => 'Sort a pallet',
                'steps' => [
                    'Open Donation Sorting and tap a donation under Ready to Sort.',
                    'Scan the pallet\'s label (or type its number).',
                    'For each item: scan its barcode or search for it, enter the quantity and choose New or Usable.',
                    'Put anything expired or damaged on its own line as Outdated or Trashed.',
                    'When the pallet is empty, tap Pallet Empty, then Pallet looks OK.',
                ],
            ],
            'round2' => [
                'title' => 'Barcodes and cartons',
                'steps' => [
                    'Pick up another pallet from Ready to Sort and scan its label.',
                    'Scan a single item\'s barcode, then a case\'s barcode: see how the case fills in the quantity.',
                    'If a barcode isn\'t known, add it when the New Item / New Carton Barcode box opens.',
                    'Finish the pallet (Pallet Empty), and when the donation is done tap Complete Session.',
                ],
            ],
        ],
        3 => [
            'page' => 'Inventory Movement',
            'round1' => [
                'title' => 'Build a warehouse pallet',
                'steps' => [
                    'Open Inventory Movement (Pallets tab). With no pallet showing, you are building a new one.',
                    'Tap items under In the sorting area and enter how many go on the pallet.',
                    'Scan the table spot label (A-1 to C-4) where it will sit, then Seal & Place.',
                    'Print the item labels it offers and put them on the pallet.',
                ],
            ],
            'round2' => [
                'title' => 'Find and move a pallet',
                'steps' => [
                    'Scan a W pallet\'s label: see where it is, what is on it and its history.',
                    'Scan a different location barcode to move it there.',
                    'Unseal a pallet, add items from the sorting area to it, then Re-seal it.',
                    'Open the Locations tab and find what is at one table spot.',
                ],
            ],
        ],
        4 => [
            'page' => 'Order Filling',
            'round1' => [
                'title' => 'Pick an order',
                'steps' => [
                    'Open Order Filling and tap Start Filling on a Ready to Fill order.',
                    'Follow each line\'s Pick: row — the sorting area first, then the pallet it names.',
                    'Enter what you actually picked (Add Fill). Open a sealed pallet if the plan says to.',
                    'When every line has a fill, tap Complete Filling.',
                ],
            ],
            'round2' => [
                'title' => 'Paper pick sheets',
                'steps' => [
                    'Look at Review Allocation: what happens when more is asked for than is on hand?',
                    'Print Pick Sheets for one order, and pick it from the paper sheet.',
                    'Enter the fills from the sheet in Order Filling, then Complete Filling.',
                ],
            ],
        ],
        5 => [
            'page' => 'Shipping',
            'round1' => [
                'title' => 'Assign a driver and ship',
                'steps' => [
                    'Open Shipping. Under Filled — Ready to Assign a Driver, pick your team\'s driver (Team N Driver) and tap Assign.',
                    'Print the Generated BOL for that order.',
                    'Stage the load at the door, then tap Mark Shipped when it leaves.',
                ],
            ],
            'round2' => [
                'title' => 'Ship a second load',
                'steps' => [
                    'Assign another Filled order to a team driver.',
                    'Print the BOL and a shipping label, and check the order\'s lines against the load.',
                    'Mark it Shipped.',
                ],
            ],
        ],
        6 => [
            'page' => 'Order Entry / Driver Portal',
            'round1' => [
                'title' => 'Take a partner\'s request',
                'steps' => [
                    'Open Order Entry and tap New Order.',
                    'Choose a Training Partner and confirm the contact and delivery details.',
                    'Add three or four items with quantities (mark one as Critical).',
                    'Review & Confirm, then Complete Order — it is now Ready to Fill for Picking.',
                ],
            ],
            'round2' => [
                'title' => 'Deliver, then review the BOL',
                'steps' => [
                    'On the station phone or tablet (not logged in), open the Driver Portal and sign in with your team driver\'s phone and PIN (below).',
                    'Open your Shipped load, tap Upload Signed BOL and take a photo of the signed paper BOL.',
                    'Back in Shipping (logged in), find it under Delivered — Pending Review, tap Review, and Approve it (or Reject with a reason).',
                ],
            ],
        ],
    ];

    /** The station team $team is at in slot $slot (0-based) of a segment. */
    public static function stationFor(int $team, int $slot): int
    {
        return (($team - 1 + $slot) % count(TrainingMode::STATIONS)) + 1;
    }

    /** Minutes from the start of a segment to the start of each of the six slots. */
    public static function slotStart(int $slot): int
    {
        return (int) round($slot * self::SEGMENT_MINUTES / count(TrainingMode::STATIONS));
    }

    /** Everything a team sheet prints, in that team's rotation order. */
    public static function sheetFor(int $team): array
    {
        $slots = [];
        foreach (range(0, count(TrainingMode::STATIONS) - 1) as $slot) {
            $station = self::stationFor($team, $slot);
            $slots[] = [
                'slot' => $slot + 1,
                'start' => self::slotStart($slot),
                'end' => self::slotStart($slot + 1),
                'station' => $station,
                'name' => TrainingMode::STATIONS[$station],
                'activity' => self::ACTIVITIES[$station],
            ];
        }

        return ['team' => $team, 'slots' => $slots];
    }
}
