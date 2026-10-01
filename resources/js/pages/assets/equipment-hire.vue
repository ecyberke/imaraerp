<script setup>
/* eslint-disable camelcase -- these object keys are literal Laravel API request/response field names (snake_case is the real contract), not JS identifiers to rename. */
definePage({
  meta: { action: 'read', subject: 'Assets' },
})

const contracts = ref([])
const parties = ref([])
const projects = ref([])
const loading = ref(true)
const dialog = ref(false)
const saving = ref(false)
const error = ref('')
const notice = ref('')
const invoicedDays = ref({})

function flash(message) {
  notice.value = message
  setTimeout(() => { notice.value = '' }, 6000)
}

const form = ref({ party_id: null, project_id: null, description: '', hire_rate: null, hire_start_date: '', hire_end_date: '' })

async function loadAll() {
  loading.value = true
  try {
    const [c, p, pr] = await Promise.all([$api('/equipment-hire-contracts'), $api('/parties'), $api('/projects')])

    contracts.value = c
    parties.value = p
    projects.value = pr
  } finally {
    loading.value = false
  }
}

function openCreate() {
  error.value = ''
  form.value = { party_id: null, project_id: null, description: '', hire_rate: null, hire_start_date: '', hire_end_date: '' }
  dialog.value = true
}

async function save() {
  error.value = ''
  saving.value = true
  try {
    await $api('/equipment-hire-contracts', { method: 'POST', body: form.value })
    dialog.value = false
    await loadAll()
  } catch (err) {
    error.value = extractApiErrorMessage(err)
  } finally {
    saving.value = false
  }
}

async function recordInvoicedDays(contract) {
  try {
    await $api(`/equipment-hire-contracts/${contract.id}/invoiced-days`, {
      method: 'POST',
      body: { invoiced_days: invoicedDays.value[contract.id] },
    })
    flash('Invoiced days recorded.')
    await loadAll()
  } catch (err) {
    flash(extractApiErrorMessage(err))
  }
}

onMounted(loadAll)
</script>

<template>
  <VCard title="Equipment Hire Contracts">
    <template #append>
      <VBtn @click="openCreate">
        New Hire Contract
      </VBtn>
    </template>

    <VCardText v-if="notice">
      <VAlert type="success">
        {{ notice }}
      </VAlert>
    </VCardText>

    <VCardText>
      <VCard
        v-for="contract in contracts"
        :key="contract.id"
        variant="outlined"
        class="mb-2"
      >
        <VCardText>
          <p class="mb-2">
            <strong>{{ contract.description }}</strong> - {{ contract.hire_rate }}/day, {{ contract.hire_start_date?.slice(0, 10) }} to {{ contract.hire_end_date?.slice(0, 10) || 'open' }}
            <VChip
              v-if="contract.hire_invoice_mismatch"
              class="ms-2"
              color="error"
            >
              Invoice day-count mismatch
            </VChip>
          </p>
          <p class="mb-2 text-medium-emphasis">
            Expected days: {{ contract.expected_days ?? 'n/a' }} · Invoiced days: {{ contract.invoiced_days ?? 'not yet recorded' }}
          </p>

          <VRow align="center">
            <VCol
              cols="6"
              md="3"
            >
              <VTextField
                v-model.number="invoicedDays[contract.id]"
                type="number"
                label="Invoiced Days"
                density="compact"
              />
            </VCol>
            <VCol
              cols="6"
              md="3"
            >
              <VBtn
                size="small"
                :disabled="!invoicedDays[contract.id]"
                @click="recordInvoicedDays(contract)"
              >
                Record
              </VBtn>
            </VCol>
          </VRow>
        </VCardText>
      </VCard>
      <p
        v-if="!loading && !contracts.length"
        class="text-medium-emphasis"
      >
        No equipment hire contracts yet.
      </p>
    </VCardText>

    <VDialog
      v-model="dialog"
      max-width="500"
    >
      <VCard title="New Equipment Hire Contract">
        <VCardText>
          <VAlert
            v-if="error"
            type="error"
            class="mb-4"
          >
            {{ error }}
          </VAlert>

          <VSelect
            v-model="form.party_id"
            label="Hire Company"
            class="mb-4"
            item-title="name"
            item-value="id"
            :items="parties"
          />
          <VSelect
            v-model="form.project_id"
            label="Project"
            class="mb-4"
            item-title="name"
            item-value="id"
            :items="projects"
          />
          <VTextField
            v-model="form.description"
            label="Description"
            class="mb-4"
          />
          <VTextField
            v-model.number="form.hire_rate"
            type="number"
            label="Hire Rate (per day)"
            class="mb-4"
          />
          <VTextField
            v-model="form.hire_start_date"
            type="date"
            label="Start Date"
            class="mb-4"
          />
          <VTextField
            v-model="form.hire_end_date"
            type="date"
            label="End Date (optional)"
          />
        </VCardText>
        <VCardActions>
          <VSpacer />
          <VBtn
            variant="text"
            @click="dialog = false"
          >
            Cancel
          </VBtn>
          <VBtn
            :loading="saving"
            @click="save"
          >
            Save
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>
  </VCard>
</template>
