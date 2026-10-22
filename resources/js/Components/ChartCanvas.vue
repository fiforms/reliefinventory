<!-- This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
     Licensed under the GNU GPL v. 3. See LICENSE.md for details -->

<!-- ChartCanvas.vue

	Thin Chart.js wrapper for the report pages. Pass a Chart.js `config`
	({type, data, options}); the chart is rebuilt whenever it changes and
	destroyed with the component. Only the bar/line pieces the reports use
	are registered, to keep the bundle small. Reports always show their
	numbers in a table next to the chart, so `label` is just the canvas's
	accessible name.
-->

<script>
import {
	Chart, BarController, LineController, BarElement, LineElement, PointElement,
	CategoryScale, LinearScale, Tooltip, Legend,
} from 'chart.js';

Chart.register(BarController, LineController, BarElement, LineElement, PointElement,
	CategoryScale, LinearScale, Tooltip, Legend);

export default {
	props: {
		config: { type: Object, required: true },
		label: { type: String, default: 'Chart' },
		height: { type: Number, default: 260 },
	},
	data() {
		return { chart: null };
	},
	watch: {
		config: { handler() { this.draw(); }, deep: true },
	},
	mounted() {
		this.draw();
	},
	beforeUnmount() {
		this.chart?.destroy();
	},
	methods: {
		draw() {
			this.chart?.destroy();
			this.chart = new Chart(this.$refs.canvas, {
				...this.config,
				options: { responsive: true, maintainAspectRatio: false, animation: false, ...this.config.options },
			});
		},
	},
};
</script>

<template>
	<div class="chartcanvas" :style="{ height: height + 'px' }">
		<canvas ref="canvas" role="img" :aria-label="label"></canvas>
	</div>
</template>

<style scoped>
.chartcanvas {
	position: relative;
	width: 100%;
}
</style>
