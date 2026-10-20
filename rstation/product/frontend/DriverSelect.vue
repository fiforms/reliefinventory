<script setup>
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { request } from '@core/api.js'

// The driver choice on Settings > Printers' add forms: the built-in choices (driverless,
// generic PostScript/PCL) plus a search of every driver installed on this device
// (GET /api/local/printers/drivers), so a printer that needs its own driver — e.g. a Brother
// laser that prints blank pages on generic PostScript — can be set up here. The chosen value
// is either a built-in key or a driver id exactly as the search returned it; the root helper
// re-validates it, so nothing typed here is ever passed to CUPS as-is.
const props = defineProps({ modelValue: { type: String, required: true }, model: { type: String, default: '' } })
const emit = defineEmits(['update:modelValue'])

const { t } = useI18n()
const BUILT_IN = ['everywhere', 'generic-postscript', 'generic-pcl', 'zebra-zpl']
const open = ref(false)
const query = ref('')
const busy = ref(false)
const error = ref(null)
const results = ref([]) // [{ id, name }]
const total = ref(0)

const options = computed(() => {
  const extra = results.value.map((d) => ({ value: d.id, label: d.name }))
  return [...BUILT_IN.map((d) => ({ value: d, label: t(`settings.printers.add.drivers.${d}`) })), ...extra]
})

function toggle() {
  open.value = !open.value
  if (open.value && !query.value) query.value = props.model
}

async function search() {
  if (!query.value.trim()) return
  busy.value = true
  error.value = null
  try {
    const body = await request(`/api/local/printers/drivers?q=${encodeURIComponent(query.value.trim())}`)
    results.value = body.drivers
    total.value = body.total
    if (!body.drivers.length) error.value = t('settings.printers.add.noDrivers')
    else emit('update:modelValue', body.drivers[0].id)
  } catch (err) {
    error.value = err.message
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <div class="driver-select">
    <label>
      <span>{{ t('settings.printers.add.driver') }}</span>
      <select :value="modelValue" @change="emit('update:modelValue', $event.target.value)">
        <option v-for="o in options" :key="o.value" :value="o.value">{{ o.label }}</option>
      </select>
    </label>
    <button type="button" class="link" @click="toggle">{{ t('settings.printers.add.findDriver') }}</button>
    <div v-if="open" class="driver-search">
      <input v-model="query" :placeholder="t('settings.printers.add.driverQuery')" autocomplete="off" @keydown.enter.prevent="search" />
      <button type="button" class="tile action" :disabled="busy || !query.trim()" @click="search">
        {{ busy ? t('settings.printers.add.searching') : t('settings.printers.add.searchDrivers') }}
      </button>
      <p v-if="busy" class="hint">{{ t('settings.printers.add.driverSearchHint') }}</p>
      <p v-if="error" class="hint">{{ error }}</p>
      <p v-else-if="total > results.length" class="hint">{{ t('settings.printers.add.driverTruncated', { shown: results.length, total }) }}</p>
    </div>
  </div>
</template>

<style scoped>
label { display: grid; gap: 0.25rem; }
label span { color: var(--text-dim); font-weight: 600; }
.driver-search { display: flex; flex-wrap: wrap; gap: 0.5rem; margin-top: 0.5rem; }
.driver-search input { flex: 1 1 14rem; }
.driver-search .hint { flex-basis: 100%; margin: 0; }
.link { background: none; border: 0; color: inherit; text-decoration: underline; cursor: pointer; padding: 0.25rem 0; font: inherit; opacity: 0.8; }
</style>
