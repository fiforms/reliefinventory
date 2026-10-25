<!-- This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
     Licensed under the GNU GPL v. 3. See LICENSE.md for details -->

<!-- Delivery-days option "pick": two states only. Nothing starts selected; tap the days they can accept. -->

<script setup>
import { computed } from 'vue';
import DemoFrame from './DemoFrame.vue';
import { WEEK_DAYS, blankDays, resetDays, summarize } from './days.js';

const days = blankDays();
const allOn = computed(() => WEEK_DAYS.every((day) => days[day] === 'ok'));

function toggle(day) {
	days[day] = days[day] === 'ok' ? 'no' : 'ok';
}
function anyDay() {
	const value = allOn.value ? 'no' : 'ok';
	for (const day of WEEK_DAYS) days[day] = value;
}
</script>

<template>
	<DemoFrame :summary="summarize(days, { preferred: false })" @reset="resetDays(days)">
		<div class="dd_chips">
			<button type="button" class="dd_chip" :class="{ dd_ok: allOn }" @click="anyDay">Any Day</button>
			<button v-for="day in WEEK_DAYS" :key="day" type="button" class="dd_chip" :class="{ dd_ok: days[day] === 'ok' }" @click="toggle(day)">
				{{ day }}
			</button>
		</div>
	</DemoFrame>
</template>
