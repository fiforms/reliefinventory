---
name: admin-page-style
description: Visual format conventions for admin/settings-style pages, as established by SystemAdmin.vue (/setup/system). Use when building any new admin, settings, dashboard, or status page so it matches that look — card sections, notice banners, action buttons, form grids, footnotes.
---

# Admin page style (SystemAdmin.vue conventions)

The reference implementation is `resources/js/Pages/SystemAdmin.vue`. When
building a new admin/settings-style page, reuse its structure and Tailwind
patterns rather than inventing new ones. This style is for **panel-style pages**
(settings, status, actions); CRUD list/detail pages should keep using
`RIForm.vue` — don't mix the two.

## Page skeleton

```vue
<template>
	<Head title="Page Name" />
	<AuthenticatedLayout :breadcrumb="breadcrumb">
		<div class="max-w-4xl mx-auto p-4 space-y-6">
			<h1 class="text-2xl font-bold">Page Name</h1>

			<!-- global feedback banners, then one <section> card per topic -->
		</div>
	</AuthenticatedLayout>
</template>
```

- `max-w-4xl mx-auto p-4 space-y-6` — centered column, consistent vertical rhythm.
- One `<h1 class="text-2xl font-bold">` per page.
- Script setup + axios against `/json/...` endpoints; `defineProps({ breadcrumb: { type: Array } })`.

## Section cards

Each functional area is a card:

```html
<section class="bg-white shadow rounded-lg p-6 space-y-4">
	<h2 class="text-lg font-semibold">Section Title</h2>
	...
</section>
```

## Feedback banners

Page-level `error`/`notice` refs rendered above the cards; in-progress states
get a blue box inside the relevant card:

```html
<div v-if="error" class="rounded bg-red-100 border border-red-400 text-red-800 px-4 py-3">{{ error }}</div>
<div v-if="notice" class="rounded bg-green-100 border border-green-400 text-green-800 px-4 py-3">{{ notice }}</div>
<div class="rounded bg-blue-50 border border-blue-300 text-blue-800 px-4 py-3 text-sm">in-progress…</div>
```

Clear both refs at the start of every action handler.

## Buttons

- Primary actions: the shared `PrimaryButton` component, label swapped while busy
  (`{{ busy ? 'Saving…' : 'Save' }}`) and `:disabled="busy"`.
- Dangerous actions use a **two-step inline confirm** — never `window.confirm()`:
  first click turns the button into a red confirm with an explicit consequence
  (`Confirm — site will briefly go offline`) plus an underlined text "Cancel";
  second click acts.

```html
<button class="inline-flex items-center px-4 py-2 rounded-md font-semibold text-xs uppercase tracking-widest text-white"
	:class="confirming ? 'bg-red-600 hover:bg-red-500' : 'bg-amber-600 hover:bg-amber-500'">
```

## Forms

Settings forms are a responsive grid of labeled controls, not tables:

```html
<div class="grid grid-cols-1 sm:grid-cols-2 gap-4 text-sm">
	<label class="block">
		<span class="text-gray-700">Field name</span>
		<select v-model="form.x" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm">…</select>
	</label>
</div>
```

- Same `mt-1 block w-full border-gray-300 rounded-md shadow-sm` classes on
  `select` and `input`.
- Save button + a transient confirmation (`Saved 3:41:07 PM`) beside it, not a banner.
- Conditional fields appear/disappear with `v-if` on sibling grid cells.

## Data display

- Small stat/list panels: `border rounded p-3` cells inside a
  `grid grid-cols-1 sm:grid-cols-3 gap-4`.
- Machine values (versions, timestamps, commit lines): `font-mono`, dimmed with
  `text-gray-600`/`text-xs` as appropriate; empty states as
  `text-gray-400` "none yet".
- Every card that needs context ends with a `text-xs text-gray-500` footnote
  explaining consequences ("Changes take effect at the next hourly check…").

## Behavior conventions

- Load all page data from one `GET /json/<area>/status`-style endpoint into a
  single `status` ref; show `Loading system status…` in `text-gray-500` until it arrives.
- Long-running server work: trigger, then poll the status endpoint on a
  `setInterval` (~4s); treat request failures during expected downtime as
  progress, not errors; always `clearInterval` in `onUnmounted`.
- Error messages come from `e.response?.data?.message` with a human fallback.
