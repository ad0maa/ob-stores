<script setup>
import { computed, onMounted, ref, watch } from 'vue'
import { getJson, postJson } from './api.js'

const MAX_POSITIONS = 50

const templates = ref([])
const templateId = ref(null)
const positions = ref(3)
const plan = ref(null)
const loading = ref(false)
const error = ref('')

const template = computed(() => templates.value.find((t) => t.id === templateId.value))
const gear = computed(() => plan.value?.lines.filter((line) => line.kind === 'gear') ?? [])
const consumables = computed(() => plan.value?.lines.filter((line) => line.kind === 'consumable') ?? [])
const jobName = ref('')
const jobDate = ref(new Date().toLocaleDateString('en-CA'))
const packing = ref(false)
const fieldErrors = ref({})
const conflict = ref([])
const canPack = computed(() => plan.value && !plan.value.shortfalls && !loading.value && !packing.value && validPositions.value)

const validPositions = computed(() => Number.isInteger(positions.value) && positions.value >= 1 && positions.value <= MAX_POSITIONS)

onMounted(async () => {
  const params = new URLSearchParams(location.search)
  try {
    templates.value = await getJson('/api/templates')
  } catch (e) {
    error.value = e.message
    return
  }
  const fromUrl = Number(params.get('template'))
  templateId.value = templates.value.some((t) => t.id === fromUrl) ? fromUrl : templates.value[0]?.id ?? null
  positions.value = Number(params.get('positions')) || 3
})

// Re-plan on every change, debounced. A newer request aborts the one in
// flight, so typing "12" quickly never flashes the plan for "1".
let debounce = null
let inFlight = null

watch([templateId, positions], () => {
  clearTimeout(debounce)
  if (templateId.value && validPositions.value) {
    debounce = setTimeout(loadPlan, 150)
  }
})

async function loadPlan() {
  inFlight?.abort()
  const controller = (inFlight = new AbortController())
  loading.value = true
  error.value = ''
  try {
    plan.value = await getJson(`/api/templates/${templateId.value}/plan?positions=${positions.value}`, { signal: controller.signal })
    history.replaceState(null, '', `?template=${templateId.value}&positions=${positions.value}`)
  } catch (e) {
    if (e.name !== 'AbortError') error.value = e.message
  } finally {
    if (inFlight === controller) loading.value = false
  }
}

// The plan is advisory; the server re-checks everything under lock. If
// another crew packed in the meantime we get a 409 with what's now short.
async function pack() {
  packing.value = true
  fieldErrors.value = {}
  conflict.value = []
  try {
    const job = await postJson('/api/jobs', {
      template_id: templateId.value,
      positions: positions.value,
      name: jobName.value,
      job_date: jobDate.value,
    })
    location.assign(job.url)
  } catch (e) {
    if (e.status === 422) fieldErrors.value = e.details
    else if (e.status === 409) {
      conflict.value = e.details.shortfalls
      loadPlan()
    } else error.value = e.message
    packing.value = false
  }
}

// How much of the requirement is available right now, capped at 100%.
const cover = (line) => Math.min(100, (line.available / line.required) * 100)

function step(by) {
  const next = (validPositions.value ? positions.value : 1) + by
  positions.value = Math.min(MAX_POSITIONS, Math.max(1, next))
}
</script>

<template>
  <div class="page-head">
    <h1>Kit planner</h1>
    <span class="muted" aria-live="polite">{{ loading ? 'Updating…' : '' }}</span>
  </div>

  <div class="planner-controls">
    <label>
      Template
      <select v-model="templateId">
        <option v-for="t in templates" :key="t.id" :value="t.id">{{ t.name }}</option>
      </select>
    </label>
    <div class="stepper">
      <label for="positions">Positions / crews</label>
      <button type="button" aria-label="One fewer" @click="step(-1)">−</button>
      <input id="positions" v-model.number="positions" type="number" min="1" :max="MAX_POSITIONS" inputmode="numeric" :aria-invalid="!validPositions">
      <button type="button" aria-label="One more" @click="step(1)">+</button>
    </div>
    <p v-if="template" class="muted template-description">{{ template.description }}</p>
  </div>

  <p v-if="!validPositions" class="form-error">Positions must be a whole number from 1 to {{ MAX_POSITIONS }}.</p>
  <p v-if="error" class="form-error" role="alert">{{ error }}</p>

  <template v-if="plan">
    <p class="plan-summary" :class="plan.shortfalls ? 'is-short' : 'is-ready'" aria-live="polite">
      <template v-if="plan.shortfalls">{{ plan.shortfalls }} {{ plan.shortfalls === 1 ? 'shortfall' : 'shortfalls' }} for {{ plan.positions }} × {{ plan.template.name }}</template>
      <template v-else>Ready to pack {{ plan.positions }} × {{ plan.template.name }}</template>
    </p>

    <table class="grid plan" :class="{ 'is-stale': loading }">
      <thead>
        <tr><th>Code</th><th>Item</th><th class="num">Required</th><th class="num">Available</th><th class="num">Short</th><th class="meter-col">Cover</th></tr>
      </thead>
      <tbody v-for="[label, rows] in [['Gear', gear], ['Consumables', consumables]]" :key="label">
        <tr class="group"><th colspan="6">{{ label }}</th></tr>
        <tr v-for="line in rows" :key="line.kind + line.itemId" :class="{ 'is-short': line.shortfall > 0 }">
          <td class="mono">{{ line.code }}</td>
          <td>
            {{ line.name }}
            <span class="meter meter-inline" aria-hidden="true"><span :style="{ width: cover(line) + '%' }"></span></span>
          </td>
          <td class="num">{{ line.required.toLocaleString('en-GB') }} <span class="unit">{{ line.unit }}</span></td>
          <td class="num">{{ line.available.toLocaleString('en-GB') }}</td>
          <td class="num short">{{ line.shortfall || '' }}</td>
          <td class="meter-col">
            <span class="meter" :aria-label="`${Math.round(cover(line))}% covered`">
              <span :style="{ width: cover(line) + '%' }"></span>
            </span>
          </td>
        </tr>
      </tbody>
    </table>

    <form class="pack-form" novalidate @submit.prevent="pack">
      <h2>Pack for a job</h2>
      <label>
        Job name
        <input v-model.trim="jobName" maxlength="120" placeholder="e.g. Saturday cup tie" :aria-invalid="!!fieldErrors.name">
        <span class="field-error">{{ fieldErrors.name }}</span>
      </label>
      <label>
        Job date
        <input v-model="jobDate" type="date" :aria-invalid="!!fieldErrors.job_date">
        <span class="field-error">{{ fieldErrors.job_date }}</span>
      </label>
      <button type="submit" class="primary" :disabled="!canPack">
        {{ packing ? 'Packing…' : `Pack ${plan.positions} × ${plan.template.name}` }}
      </button>
      <div v-if="conflict.length" class="form-error" role="alert">
        Stock changed before this was packed, so nothing was taken. Now short:
        <ul>
          <li v-for="line in conflict" :key="line.kind + line.itemId">{{ line.name }}: need {{ line.required }}, {{ line.available }} left</li>
        </ul>
      </div>
    </form>
  </template>
</template>
