<script setup>
/* eslint-disable camelcase -- these object keys are literal Laravel API request/response field names (snake_case is the real contract), not JS identifiers to rename. */
definePage({
  meta: { action: 'read', subject: 'HR' },
})

const router = useRouter()

const runs = ref([])
const loading = ref(true)
const dialog = ref(false)
const saving = ref(false)
const error = ref('')

const form = ref({ period_start: '', period_end: '' })

async function loadAll() {
  loading.value = true
  try {
    runs.value = await $api('/payroll-runs')
  } finally {
    loading.value = false
  }
}

function openCreate() {
  error.value = ''
  form.value = { period_start: '', period_end: '' }
  dialog.value = true
}

async function save() {
  error.value = ''
  saving.value = true
  try {
    const run = await $api('/payroll-runs', { method: 'POST', body: form.value })

    dialog.value = false
    router.push(`/hr/payroll-runs/${run.id}`)
  } catch (err) {
    error.value = extractApiErrorMessage(err)
  } finally {
    saving.value = false
  }
}

const statusColor = status => ({
  draft: 'secondary', processing: 'info', approved: 'primary', paid: 'success',
}[status] || 'secondary')

onMounted(loadAll)
</script>

<template>
  <VCard title="Payroll Runs">
    <template #append>
      <VBtn @click="openCreate">
        New Payroll Run
      </VBtn>
    </template>

    <VDataTable
      :headers="[
        { title: 'Period', key: 'period_start' },
        { title: 'Status', key: 'status' },
      ]"
      :items="runs"
      :loading="loading"
      item-value="id"
      @click:row="(_, { item }) => router.push(`/hr/payroll-runs/${item.id}`)"
    >
      <template #item.period_start="{ item }">
        {{ item.period_start?.slice(0, 10) }} to {{ item.period_end?.slice(0, 10) }}
      </template>
      <template #item.status="{ item }">
        <VChip :color="statusColor(item.status)">
          {{ item.status }}
        </VChip>
      </template>
    </VDataTable>

    <VDialog
      v-model="dialog"
      max-width="450"
    >
      <VCard title="New Payroll Run">
        <VCardText>
          <VAlert
            v-if="error"
            type="error"
            class="mb-4"
          >
            {{ error }}
          </VAlert>

          <VTextField
            v-model="form.period_start"
            type="date"
            label="Period Start"
            class="mb-4"
          />
          <VTextField
            v-model="form.period_end"
            type="date"
            label="Period End"
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
