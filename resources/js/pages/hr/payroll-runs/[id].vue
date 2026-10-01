<script setup>
/* eslint-disable camelcase -- these object keys are literal Laravel API request/response field names (snake_case is the real contract), not JS identifiers to rename. */
definePage({
  meta: { action: 'read', subject: 'HR' },
})

const route = useRoute('hr-payroll-runs-id')
const runId = computed(() => route.params.id)

const run = ref(null)
const employees = ref([])
const loading = ref(true)
const submitting = ref(false)
const notice = ref('')
const noticeType = ref('success')

function flash(message, type = 'success') {
  notice.value = message
  noticeType.value = type
  setTimeout(() => { notice.value = '' }, 6000)
}

async function loadAll() {
  loading.value = true
  try {
    const [r, e] = await Promise.all([$api(`/payroll-runs/${runId.value}`), $api('/employees')])

    run.value = r
    employees.value = e
  } finally {
    loading.value = false
  }
}

async function reload() {
  run.value = await $api(`/payroll-runs/${runId.value}`)
}

async function withSubmitting(fn, successMessage) {
  submitting.value = true
  try {
    await fn()
    await reload()
    if (successMessage)
      flash(successMessage)
  } catch (err) {
    flash(extractApiErrorMessage(err), 'error')
  } finally {
    submitting.value = false
  }
}

function generate() {
  return withSubmitting(() => $api(`/payroll-runs/${runId.value}/generate`, { method: 'POST' }), 'Payslips generated.')
}
function approve() {
  return withSubmitting(() => $api(`/payroll-runs/${runId.value}/approve`, { method: 'POST' }), 'Payroll run approved and posted.')
}
function disburse() {
  return withSubmitting(() => $api(`/payroll-runs/${runId.value}/disburse-net-pay`, { method: 'POST' }), 'Net pay disbursed.')
}

const settleDialog = ref(false)
const settleForm = ref({ employee_id: null, last_working_day: '', encash_leave: true })

async function settleEmployee() {
  await withSubmitting(() => $api(`/payroll-runs/${runId.value}/settle-employee`, { method: 'POST', body: settleForm.value }), 'Final settlement recorded.')
  settleDialog.value = false
}

const statusColor = status => ({
  draft: 'secondary', processing: 'info', approved: 'primary', paid: 'success',
}[status] || 'secondary')

onMounted(loadAll)
</script>

<template>
  <div v-if="loading">
    <VProgressCircular indeterminate />
  </div>

  <div v-else-if="run">
    <VAlert
      v-if="notice"
      :type="noticeType"
      class="mb-4"
      closable
      @click:close="notice = ''"
    >
      {{ notice }}
    </VAlert>

    <VCard
      class="mb-4"
      :title="`Payroll Run: ${run.period_start?.slice(0, 10)} to ${run.period_end?.slice(0, 10)}`"
    >
      <template #append>
        <VChip :color="statusColor(run.status)">
          {{ run.status }}
        </VChip>
      </template>
      <VCardText>
        <VBtn
          v-if="run.status === 'draft'"
          class="me-2"
          :loading="submitting"
          @click="generate"
        >
          Generate Payslips
        </VBtn>
        <VBtn
          v-if="run.status === 'processing'"
          class="me-2"
          :loading="submitting"
          @click="approve"
        >
          Approve &amp; Post
        </VBtn>
        <VBtn
          v-if="run.status === 'approved'"
          class="me-2"
          :loading="submitting"
          @click="disburse"
        >
          Disburse Net Pay
        </VBtn>
        <VBtn
          v-if="['draft', 'processing'].includes(run.status)"
          variant="text"
          @click="settleDialog = true"
        >
          Settle a Terminating Employee
        </VBtn>
      </VCardText>
    </VCard>

    <VCard title="Payslips">
      <VDataTable
        :headers="[
          { title: 'Employee', key: 'employee.name' },
          { title: 'Period', key: 'period_start' },
          { title: 'Gross Pay', key: 'gross_pay' },
          { title: 'PAYE', key: 'paye_amount' },
          { title: 'Net Pay', key: 'net_pay' },
          { title: 'Status', key: 'status' },
        ]"
        :items="run.payslips"
      >
        <template #item.period_start="{ item }">
          {{ item.period_start?.slice(0, 10) }} to {{ item.period_end?.slice(0, 10) }}
        </template>
        <template #item.status="{ item }">
          <VChip
            size="small"
            :color="statusColor(item.status)"
          >
            {{ item.status }}
          </VChip>
        </template>
      </VDataTable>
    </VCard>

    <VDialog
      v-model="settleDialog"
      max-width="450"
    >
      <VCard title="Settle a Terminating Employee">
        <VCardText>
          <p class="mb-4 text-medium-emphasis">
            Prorates salary to the last working day and computes leave encashment.
          </p>
          <VSelect
            v-model="settleForm.employee_id"
            label="Employee"
            class="mb-4"
            item-title="name"
            item-value="id"
            :items="employees"
          />
          <VTextField
            v-model="settleForm.last_working_day"
            type="date"
            label="Last Working Day"
            class="mb-4"
          />
          <VSwitch
            v-model="settleForm.encash_leave"
            label="Encash accrued leave"
          />
        </VCardText>
        <VCardActions>
          <VSpacer />
          <VBtn
            variant="text"
            @click="settleDialog = false"
          >
            Cancel
          </VBtn>
          <VBtn
            :loading="submitting"
            @click="settleEmployee"
          >
            Confirm Settlement
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>
