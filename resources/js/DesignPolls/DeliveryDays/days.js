// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

// Shared by the delivery-days demos (App\Support\DesignPolls 'delivery-days'). Every demo keeps the same state —
// day => 'no' | 'ok' | 'pref' — so the summary line reads the same whichever design is being tried.

import { reactive } from 'vue';

export const WEEK_DAYS = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];

export function blankDays() {
	return reactive(Object.fromEntries(WEEK_DAYS.map((day) => [day, 'no'])));
}

export function resetDays(days) {
	for (const day of WEEK_DAYS) days[day] = 'no';
}

/** "Can accept: Tue, Wed, Thu · Preferred: Tue" — what the request would record. */
export function summarize(days) {
	const accepted = WEEK_DAYS.filter((day) => days[day] !== 'no');
	if (!accepted.length) return 'No days chosen yet';
	const canAccept = accepted.length === 7 ? 'Any day' : accepted.join(', ');
	const pref = WEEK_DAYS.filter((day) => days[day] === 'pref');
	return 'Can accept: ' + canAccept + (pref.length ? ' · Preferred: ' + pref.join(', ') : '');
}
