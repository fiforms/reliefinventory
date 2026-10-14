<script setup>
import { onMounted, ref } from 'vue'
import { api } from '@core/api.js'

const portal = ref(null)

onMounted(async () => {
  portal.value = (await api.localStatus()).portal
})
</script>

<template>
  <div class="settings-page">
    <section class="tile panel">
      <div class="panel-title"><h2>Portal</h2></div>
      <p>Mode: {{ portal?.mode ?? '…' }}</p>
    </section>
  </div>
</template>
