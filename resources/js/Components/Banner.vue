<!-- This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
     Licensed under the GNU GPL v. 3. See LICENSE.md for details -->

<!-- Banner.vue

	The single site-wide banner slot. Mounted once in AuthenticatedLayout.vue
	and configured entirely server-side via BannerSetting (edited from
	/setup/feedback) — there is only ever one active banner at a time because
	there is only one settings row. Reads its config from the shared Inertia
	prop `$page.props.banner` (see BannerService/HandleInertiaRequests), so it
	needs no props and no data fetch of its own.

	Dismissing posts to /json/banner-dismiss and hides itself immediately
	(optimistic) — it stays dismissed for that person until the banner's
	content next changes (BannerSetting bumps `version` on edit, which
	invalidates old dismissals).
-->

<script setup>
import { computed, ref } from 'vue';
import { usePage } from '@inertiajs/vue3';
import axios from 'axios';

const emit = defineEmits(['open-feedback']);

const page = usePage();
const dismissedLocally = ref(false);

const banner = computed(() => page.props.banner || { active: false });
const visible = computed(() => banner.value.active && !dismissedLocally.value);

const styles = {
	feedback: 'bg-blue-50 border-blue-300 text-blue-900',
	maintenance: 'bg-amber-50 border-amber-400 text-amber-900',
	message: 'bg-blue-50 border-blue-300 text-blue-900',
};

const bannerClass = computed(() => styles[banner.value.type] || styles.message);

function dismiss() {
	dismissedLocally.value = true;
	axios.post('/json/banner-dismiss', { version: banner.value.version }).catch(() => {
		// Non-critical — worst case it reappears on the next page load.
	});
}
</script>

<template>
	<div v-if="visible" class="border-b text-sm" :class="bannerClass">
		<div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8 py-2 flex items-center gap-4">
			<div class="flex-1 text-center">
				<template v-if="banner.type === 'feedback'">
					Found a bug or have a feature idea?
					<button type="button" class="underline font-medium" @click="emit('open-feedback')">
						Report it here
					</button>
					— or anytime from your profile menu, even without this banner.
				</template>
				<template v-else>
					{{ banner.message }}
				</template>
			</div>
			<button type="button" class="shrink-0 text-lg leading-none opacity-60 hover:opacity-100" title="Dismiss" @click="dismiss">
				&times;
			</button>
		</div>
	</div>
</template>
