<!-- This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
     Licensed under the GNU GPL v. 3. See LICENSE.md for details -->

<!-- Delivery-days option "rows": a "Can accept" row and a "Preferred" row. Preferring a day accepts it too;
     un-accepting a day also drops it from Preferred. -->

<script setup>
import DemoFrame from './DemoFrame.vue';
import { WEEK_DAYS, blankDays, resetDays, summarize } from './days.js';

const days = blankDays();

function toggleAccept(day) {
	days[day] = days[day] === 'no' ? 'ok' : 'no';
}
function togglePreferred(day) {
	days[day] = days[day] === 'pref' ? 'ok' : 'pref';
}
</script>

<template>
	<DemoFrame :summary="summarize(days)" @reset="resetDays(days)">
		<div class="dd_rows">
			<span class="dd_rowlabel">Can accept</span>
			<div class="dd_chips">
				<button v-for="day in WEEK_DAYS" :key="day" type="button" class="dd_chip" :class="{ dd_ok: days[day] !== 'no' }" @click="toggleAccept(day)">
					{{ day }}
				</button>
			</div>
			<span class="dd_rowlabel">Preferred</span>
			<div class="dd_chips">
				<button v-for="day in WEEK_DAYS" :key="day" type="button" class="dd_chip" :class="{ dd_pref: days[day] === 'pref' }" @click="togglePreferred(day)">
					{{ day }}
				</button>
			</div>
		</div>
	</DemoFrame>
</template>

<style>
.dd_rows {
	display: grid;
	grid-template-columns: auto 1fr;
	gap: 8px 10px;
	align-items: center;
}
.dd_rowlabel {
	font-size: 0.85rem;
	color: #374151;
}
@media (max-width: 480px) {
	.dd_rows {
		grid-template-columns: 1fr;
		gap: 4px;
	}
}
</style>
