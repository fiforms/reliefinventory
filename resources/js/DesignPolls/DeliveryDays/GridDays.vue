<!-- This file is part of the Relief Inventory Project (https://reliefinventory.app)
     Licensed under the GNU GPL v. 3. See LICENSE.md for details -->

<!-- Delivery-days option "grid": a row per day with a three-way Can't / OK / Preferred control. -->

<script setup>
import DemoFrame from './DemoFrame.vue';
import { WEEK_DAYS, blankDays, resetDays, summarize } from './days.js';

const CHOICES = [['no', 'Can\'t'], ['ok', 'OK'], ['pref', '★ Preferred']];
const days = blankDays();
</script>

<template>
	<DemoFrame :summary="summarize(days)" @reset="resetDays(days)">
		<div class="dd_grid">
			<template v-for="day in WEEK_DAYS" :key="day">
				<span class="dd_gridday">{{ day }}</span>
				<span class="dd_segments">
					<button
						v-for="[value, text] in CHOICES" :key="value"
						type="button"
						class="dd_segment"
						:class="{ dd_on: days[day] === value, ['dd_on_' + value]: days[day] === value }"
						@click="days[day] = value"
					>{{ text }}</button>
				</span>
			</template>
		</div>
	</DemoFrame>
</template>

<style>
.dd_grid {
	display: grid;
	grid-template-columns: auto 1fr;
	gap: 4px 10px;
	align-items: center;
	font-size: 0.9rem;
}
.dd_gridday {
	font-weight: 600;
}
.dd_segments {
	display: inline-flex;
	border: 1px solid #ccc;
	border-radius: 6px;
	overflow: hidden;
	background: white;
	justify-self: start;
}
.dd_segment {
	padding: 4px 10px;
	border-left: 1px solid #ccc;
}
.dd_segment:first-child {
	border-left: none;
}
.dd_segment.dd_on_no {
	background: #6b7280;
	color: white;
}
.dd_segment.dd_on_ok {
	background: #007bff;
	color: white;
}
.dd_segment.dd_on_pref {
	background: #16a34a;
	color: white;
}
</style>
