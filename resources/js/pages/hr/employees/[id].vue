<script setup>
/* eslint-disable camelcase -- these object keys are literal Laravel API request/response field names (snake_case is the real contract), not JS identifiers to rename. */
definePage({
  meta: { action: 'read', subject: 'HR' },
})

const route = useRoute('hr-employees-id')
const employeeId = computed(() => route.params.id)

const employee = ref(null)
const projects = ref([])
const leaveTypes = ref([])
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
    const [e, p, lt] = await Promise.all([$api(`/employees/${employeeId.value}`), $api('/projects'), $api('/leave-types')])

    employee.value = e
    projects.value = p
    leaveTypes.value = lt
  } finally {
    loading.value = false
  }
}

async function reload() {
  employee.value = await $api(`/employees/${employeeId.value}`)
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

// ---- Contracts ----
const contractDialog = ref(false)
const contractForm = ref({ contract_type: 'full_time', pay_frequency: 'monthly', basic_salary: null, hourly_rate: null, daily_rate: null, start_date: '' })

function openContractDialog() {
  contractForm.value = { contract_type: 'full_time', pay_frequency: 'monthly', basic_salary: null, hourly_rate: null, daily_rate: null, start_date: '' }
  contractDialog.value = true
}
async function saveContract() {
  await withSubmitting(() => $api(`/employees/${employeeId.value}/employment-contracts`, { method: 'POST', body: contractForm.value }), 'Contract created.')
  contractDialog.value = false
}

// ---- Timesheets ----
const timesheetDialog = ref(false)
const timesheetForm = ref({ date: '', hours_normal: 8, hours_overtime_weekday: null, hours_overtime_restday: null, project_id: null })

function openTimesheetDialog() {
  timesheetForm.value = { date: '', hours_normal: 8, hours_overtime_weekday: null, hours_overtime_restday: null, project_id: null }
  timesheetDialog.value = true
}
async function saveTimesheet() {
  await withSubmitting(() => $api(`/employees/${employeeId.value}/timesheets`, { method: 'POST', body: timesheetForm.value }), 'Timesheet submitted.')
  timesheetDialog.value = false
}
function approveTimesheet(timesheet) {
  return withSubmitting(() => $api(`/timesheets/${timesheet.id}/approve`, { method: 'POST' }), 'Timesheet approved.')
}
function rejectTimesheet(timesheet) {
  return withSubmitting(() => $api(`/timesheets/${timesheet.id}/reject`, { method: 'POST' }), 'Timesheet rejected.')
}

// ---- Leave Requests ----
const leaveDialog = ref(false)
const leaveForm = ref({ leave_type_id: null, start_date: '', end_date: '' })

function openLeaveDialog() {
  leaveForm.value = { leave_type_id: null, start_date: '', end_date: '' }
  leaveDialog.value = true
}
async function saveLeave() {
  await withSubmitting(() => $api(`/employees/${employeeId.value}/leave-requests`, { method: 'POST', body: leaveForm.value }), 'Leave request submitted.')
  leaveDialog.value = false
}
function approveLeave(leaveRequest) {
  return withSubmitting(() => $api(`/leave-requests/${leaveRequest.id}/approve`, { method: 'POST' }), 'Leave approved.')
}
function rejectLeave(leaveRequest) {
  return withSubmitting(() => $api(`/leave-requests/${leaveRequest.id}/reject`, { method: 'POST' }), 'Leave rejected.')
}

const statusColor = status => ({
  active: 'success', probation: 'info', notice_period: 'warning', terminated: 'error',
  submitted: 'secondary', approved: 'success', rejected: 'error', pending: 'secondary',
}[status] || 'secondary')

onMounted(loadAll)
</script>

<template>
  <div v-if="loading">
    <VProgressCircular indeterminate />
  </div>

  <div v-else-if="employee">
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
      :title="`${employee.employee_number} - ${employee.name}`"
    >
      <template #subtitle>
        {{ employee.employment_type }} · hired {{ employee.date_of_hire?.slice(0, 10) }}
        <span
          v-if="employee.casual_conversion_due"
          class="text-error"
        > · Casual-to-permanent conversion due</span>
      </template>
      <template #append>
        <VChip :color="statusColor(employee.status)">
          {{ employee.status }}
        </VChip>
      </template>
    </VCard>

    <VCard
      class="mb-4"
      title="Employment Contracts"
    >
      <template #append>
        <VBtn
          size="small"
          @click="openContractDialog"
        >
          New Contract
        </VBtn>
      </template>
      <VCardText>
        <VCard
          v-for="contract in employee.contracts"
          :key="contract.id"
          variant="outlined"
          class="mb-2"
        >
          <VCardText>
            {{ contract.contract_type }} ({{ contract.pay_frequency }}) - {{ contract.start_date?.slice(0, 10) }} to {{ contract.end_date?.slice(0, 10) || 'ongoing' }}
            <span class="text-medium-emphasis">
              · {{ contract.basic_salary || contract.hourly_rate || contract.daily_rate }}
            </span>
          </VCardText>
        </VCard>
        <p
          v-if="!employee.contracts?.length"
          class="text-medium-emphasis"
        >
          No contracts yet.
        </p>
      </VCardText>
    </VCard>

    <VCard
      class="mb-4"
      title="Timesheets"
    >
      <template #append>
        <VBtn
          size="small"
          @click="openTimesheetDialog"
        >
          Submit Timesheet
        </VBtn>
      </template>
      <VCardText>
        <VCard
          v-for="timesheet in employee.timesheets"
          :key="timesheet.id"
          variant="outlined"
          class="mb-2"
        >
          <VCardText>
            <p class="mb-2">
              {{ timesheet.date?.slice(0, 10) }} - {{ timesheet.hours_normal }}h normal
              <span v-if="timesheet.hours_overtime_weekday"> + {{ timesheet.hours_overtime_weekday }}h OT (weekday)</span>
              <span v-if="timesheet.hours_overtime_restday"> + {{ timesheet.hours_overtime_restday }}h OT (restday)</span>
              <VChip
                class="ms-2"
                :color="statusColor(timesheet.status)"
              >
                {{ timesheet.status }}
              </VChip>
            </p>
            <template v-if="timesheet.status === 'submitted'">
              <VBtn
                size="small"
                class="me-2"
                :loading="submitting"
                @click="approveTimesheet(timesheet)"
              >
                Approve
              </VBtn>
              <VBtn
                size="small"
                variant="text"
                :loading="submitting"
                @click="rejectTimesheet(timesheet)"
              >
                Reject
              </VBtn>
            </template>
          </VCardText>
        </VCard>
        <p
          v-if="!employee.timesheets?.length"
          class="text-medium-emphasis"
        >
          No timesheets yet.
        </p>
      </VCardText>
    </VCard>

    <VCard title="Leave Requests">
      <template #append>
        <VBtn
          size="small"
          @click="openLeaveDialog"
        >
          Request Leave
        </VBtn>
      </template>
      <VCardText>
        <VCard
          v-for="leaveRequest in employee.leaveRequests"
          :key="leaveRequest.id"
          variant="outlined"
          class="mb-2"
        >
          <VCardText>
            <p class="mb-2">
              {{ leaveRequest.start_date?.slice(0, 10) }} to {{ leaveRequest.end_date?.slice(0, 10) }}
              <VChip
                class="ms-2"
                :color="statusColor(leaveRequest.status)"
              >
                {{ leaveRequest.status }}
              </VChip>
            </p>
            <template v-if="leaveRequest.status === 'pending'">
              <VBtn
                size="small"
                class="me-2"
                :loading="submitting"
                @click="approveLeave(leaveRequest)"
              >
                Approve
              </VBtn>
              <VBtn
                size="small"
                variant="text"
                :loading="submitting"
                @click="rejectLeave(leaveRequest)"
              >
                Reject
              </VBtn>
            </template>
          </VCardText>
        </VCard>
        <p
          v-if="!employee.leaveRequests?.length"
          class="text-medium-emphasis"
        >
          No leave requests yet.
        </p>
      </VCardText>
    </VCard>

    <!-- New Contract -->
    <VDialog
      v-model="contractDialog"
      max-width="500"
    >
      <VCard title="New Employment Contract">
        <VCardText>
          <VSelect
            v-model="contractForm.contract_type"
            label="Contract Type"
            class="mb-4"
            :items="[
              { title: 'Full Time', value: 'full_time' },
              { title: 'Part Time', value: 'part_time' },
              { title: 'Casual', value: 'casual' },
              { title: 'Contract', value: 'contract' },
            ]"
          />
          <VSelect
            v-model="contractForm.pay_frequency"
            label="Pay Frequency"
            class="mb-4"
            :items="[
              { title: 'Monthly', value: 'monthly' },
              { title: 'Weekly', value: 'weekly' },
              { title: 'Daily', value: 'daily' },
            ]"
          />
          <VTextField
            v-model.number="contractForm.basic_salary"
            type="number"
            label="Basic Salary (monthly staff)"
            class="mb-4"
          />
          <VTextField
            v-model.number="contractForm.hourly_rate"
            type="number"
            label="Hourly Rate (part-time staff)"
            class="mb-4"
          />
          <VTextField
            v-model.number="contractForm.daily_rate"
            type="number"
            label="Daily Rate (casual staff)"
            class="mb-4"
          />
          <VTextField
            v-model="contractForm.start_date"
            type="date"
            label="Start Date"
          />
        </VCardText>
        <VCardActions>
          <VSpacer />
          <VBtn
            variant="text"
            @click="contractDialog = false"
          >
            Cancel
          </VBtn>
          <VBtn
            :loading="submitting"
            @click="saveContract"
          >
            Save
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <!-- New Timesheet -->
    <VDialog
      v-model="timesheetDialog"
      max-width="500"
    >
      <VCard title="Submit Timesheet">
        <VCardText>
          <VTextField
            v-model="timesheetForm.date"
            type="date"
            label="Date"
            class="mb-4"
          />
          <VTextField
            v-model.number="timesheetForm.hours_normal"
            type="number"
            label="Normal Hours"
            class="mb-4"
          />
          <VTextField
            v-model.number="timesheetForm.hours_overtime_weekday"
            type="number"
            label="Overtime Hours (weekday, optional)"
            class="mb-4"
          />
          <VTextField
            v-model.number="timesheetForm.hours_overtime_restday"
            type="number"
            label="Overtime Hours (rest day, optional)"
            class="mb-4"
          />
          <VSelect
            v-model="timesheetForm.project_id"
            label="Project (optional)"
            item-title="name"
            item-value="id"
            :items="projects"
          />
        </VCardText>
        <VCardActions>
          <VSpacer />
          <VBtn
            variant="text"
            @click="timesheetDialog = false"
          >
            Cancel
          </VBtn>
          <VBtn
            :loading="submitting"
            @click="saveTimesheet"
          >
            Save
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <!-- New Leave Request -->
    <VDialog
      v-model="leaveDialog"
      max-width="450"
    >
      <VCard title="Request Leave">
        <VCardText>
          <VSelect
            v-model="leaveForm.leave_type_id"
            label="Leave Type"
            class="mb-4"
            item-title="name"
            item-value="id"
            :items="leaveTypes"
          />
          <VTextField
            v-model="leaveForm.start_date"
            type="date"
            label="Start Date"
            class="mb-4"
          />
          <VTextField
            v-model="leaveForm.end_date"
            type="date"
            label="End Date"
          />
        </VCardText>
        <VCardActions>
          <VSpacer />
          <VBtn
            variant="text"
            @click="leaveDialog = false"
          >
            Cancel
          </VBtn>
          <VBtn
            :loading="submitting"
            @click="saveLeave"
          >
            Save
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>
