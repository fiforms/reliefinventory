<!-- This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
     Licensed under the GNU GPL v. 3. See LICENSE.md for details -->

<script setup>
import { Link } from '@inertiajs/vue3';
</script>

<template>
    <div
        class="guest-splash relative flex min-h-screen flex-col items-center px-4 sm:px-0"
    >
        <Link
            href="/"
            class="absolute left-4 top-4 text-sm font-semibold text-indigo-600 hover:underline"
        >
            &larr; Home
        </Link>

        <!-- The splash image carries the logo and name; the box starts below them. -->
        <div
            class="guest-box mb-8 w-full overflow-hidden bg-white px-6 py-4 shadow-md sm:max-w-md sm:rounded-lg"
        >
            <slot />
        </div>
    </div>
</template>

<style scoped>
/* Image is 16:9 with the logo and name in its top ~62%, fading to white at the bottom.
   Never rendered narrower than 640px so the logo stays legible on phones (centred crop). */
.guest-splash {
    --splash-width: max(100vw, 640px);
    background-color: #fefefe;
    background-image: url('/img/splash_screen_gradient.webp');
    background-repeat: no-repeat;
    background-position: top center;
    background-size: var(--splash-width) auto;
    padding-top: calc(var(--splash-width) * 0.37);
}
</style>
