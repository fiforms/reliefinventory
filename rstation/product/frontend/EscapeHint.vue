<script setup>
import { useI18n } from 'vue-i18n'

// How to get back to Settings from the website (the escape hatch,
// platform/docs/ESCAPE_HATCH.md). Shown on the wizard's Done screen
// (`setupDoneNote` in index.js) and on the Portal settings page. The keys listed
// in the text are the daemon's COMBOS in platform/system/scripts/escape-hatch.py.
const { t } = useI18n()
</script>

<template>
  <div class="escape-hint">
    <strong>{{ t('escapeHint.title') }}</strong>
    <p>{{ t('escapeHint.body') }}</p>
  </div>
</template>

<style scoped>
.escape-hint {
  margin: 1rem 0;
  color: var(--text-dim);
  font-size: 1.1rem;
  line-height: 1.5;
}
.escape-hint p {
  margin: 0.25rem 0 0;
}
</style>
