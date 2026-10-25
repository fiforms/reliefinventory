<!-- This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
     Licensed under the GNU GPL v. 3. See LICENSE.md for details -->

<!-- Delivery-days option "cycle": one row of chips; each tap moves a day cannot → can accept → preferred → cannot. -->

<script setup>
import DemoFrame from './DemoFrame.vue';
import { WEEK_DAYS, blankDays, resetDays, summarize } from './days.js';

const NEXT = { no: 'ok', ok: 'pref', pref: 'no' };
const days = blankDays();
</script>

<template>
	<DemoFrame :summary="summarize(days)" @reset="resetDays(days)">
		<div class="dd_chips">
			<button
				v-for="day in WEEK_DAYS" :key="day"
				type="button"
				class="dd_chip"
				:class="{ dd_ok: days[day] === 'ok', dd_pref: days[day] === 'pref' }"
				@click="days[day] = NEXT[days[day]]"
			>
				<span v-if="days[day] === 'pref'">★ </span>{{ day }}
			</button>
		</div>
		<div class="dd_legend">
			<span><span class="dd_swatch"></span>Cannot</span>
			<span><span class="dd_swatch dd_ok"></span>Can accept</span>
			<span><span class="dd_swatch dd_pref"></span>★ Preferred</span>
			<span>Tap again to change</span>
		</div>
	</DemoFrame>
</template>
