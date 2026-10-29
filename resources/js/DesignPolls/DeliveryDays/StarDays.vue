<!-- This file is part of the Relief Inventory Project (https://reliefinventory.app)
     Licensed under the GNU GPL v. 3. See LICENSE.md for details -->

<!-- Delivery-days option "star": tap a day to accept it; an accepted day shows a ☆ that makes it preferred. -->

<script setup>
import DemoFrame from './DemoFrame.vue';
import { WEEK_DAYS, blankDays, resetDays, summarize } from './days.js';

const days = blankDays();

function toggle(day) {
	days[day] = days[day] === 'no' ? 'ok' : 'no';
}
function toggleStar(day) {
	days[day] = days[day] === 'pref' ? 'ok' : 'pref';
}
</script>

<template>
	<DemoFrame :summary="summarize(days)" @reset="resetDays(days)">
		<div class="dd_chips">
			<span v-for="day in WEEK_DAYS" :key="day" class="dd_starpill" :class="{ dd_ok: days[day] === 'ok', dd_pref: days[day] === 'pref' }">
				<button type="button" class="dd_starday" @click="toggle(day)">{{ day }}</button>
				<button
					v-if="days[day] !== 'no'"
					type="button"
					class="dd_star"
					:aria-label="days[day] === 'pref' ? `Make ${day} not preferred` : `Make ${day} preferred`"
					@click="toggleStar(day)"
				>{{ days[day] === 'pref' ? '★' : '☆' }}</button>
			</span>
		</div>
		<div class="dd_legend">
			<span><span class="dd_swatch dd_ok"></span>Can accept</span>
			<span><span class="dd_swatch dd_pref"></span>★ Preferred</span>
		</div>
	</DemoFrame>
</template>

<style>
.dd_starpill {
	display: inline-flex;
	align-items: stretch;
	border: 1px solid #ccc;
	border-radius: 999px;
	background: white;
	font-size: 0.9rem;
	overflow: hidden;
}
.dd_starpill.dd_ok {
	background: #007bff;
	border-color: #007bff;
	color: white;
}
.dd_starpill.dd_pref {
	background: #16a34a;
	border-color: #16a34a;
	color: white;
}
.dd_starday {
	padding: 6px 12px;
}
.dd_star {
	padding: 6px 10px 6px 4px;
	font-size: 1rem;
	line-height: 1;
}
</style>
