<!-- This file is part of the Relief Inventory Project (https://reliefinventory.app)
     Licensed under the GNU GPL v. 3. See LICENSE.md for details -->

<!--
	WarehouseRestore.vue — an administrator restoring a backup into this warehouse (/setup/restore, admin-system).
	Reuses the first-run wizard's uploader (RestoreBackup.vue) against /json/warehouse-restore/*. The administrator's password is
	asked for again because the restore signs everyone out; an instance administrator may also restore another warehouse's backup
	here. Server half: RestoreController (owning()) and RestoreService::stageOwnRestore().
-->

<script setup>
import InputLabel from '@/Components/InputLabel.vue';
import TextInput from '@/Components/TextInput.vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import RestoreBackup from '@/Pages/Setup/RestoreBackup.vue';
import { currentWarehouseId } from '@/bootstrap';
import { Head } from '@inertiajs/vue3';
import { computed, reactive } from 'vue';

defineProps({
	breadcrumb: { type: Array },
	warehouseName: { type: String, default: '' },
	instanceAdmin: { type: Boolean, default: false },
});

const back = () => (window.location.href = '/setup/system');
const identity = reactive({ password: '', any_lineage: false });
const statusUrl = computed(() => `/w/${currentWarehouseId() ?? ''}/restore-status`);
</script>

<template>
	<Head title="Restore this warehouse" />
	<AuthenticatedLayout :breadcrumb="breadcrumb">
		<div class="mx-auto max-w-3xl space-y-4 p-4">
			<section class="rounded-lg bg-white p-6 shadow">
				<h1 class="text-lg font-semibold">Restore {{ warehouseName || 'this warehouse' }} from a backup</h1>

				<div class="mt-3">
					<InputLabel for="restore_password" value="Your password (to confirm)" />
					<TextInput id="restore_password" type="password" class="mt-1 block w-full" v-model="identity.password" autocomplete="current-password" />
				</div>
				<label v-if="instanceAdmin" class="mt-3 flex items-start gap-2 text-sm text-gray-700">
					<input type="checkbox" v-model="identity.any_lineage" class="mt-1" />
					<span>This backup is of a different warehouse (clone it into this one). Only instance administrators can do this.</span>
				</label>

				<RestoreBackup base="/json/warehouse-restore" :status-url="statusUrl" :identity="identity" own @back="back" />
			</section>
		</div>
	</AuthenticatedLayout>
</template>
