<script setup>
/* eslint-disable camelcase -- these object keys are literal Laravel API request/response field names (snake_case is the real contract), not JS identifiers to rename. */
definePage({
  meta: { action: 'read', subject: 'HR' },
})

const router = useRouter()

const employees = ref([])
const loading = ref(true)
const dialog = ref(false)
const saving = ref(false)
const error = ref('')

const form = ref({
  employee_number: '', name: '', id_number: '', kra_pin: '', nssf_number: '', shif_number: '',
  helb_account_number: '', employment_type: 'full_time', date_of_hire: '',
})

async function loadAll() {
  loading.value = true
  try {
    employees.value = await $api('/employees')
  } finally {
    loading.value = false
  }
}

function openCreate() {
  error.value = ''
  form.value = {
    employee_number: '', name: '', id_number: '', kra_pin: '', nssf_number: '', shif_number: '',
    helb_account_number: '', employment_type: 'full_time', date_of_hire: '',
  }
  dialog.value = true
}

async function save() {
  error.value = ''
  saving.value = true
  try {
    await $api('/employees', { method: 'POST', body: form.value })
    dialog.value = false
    await loadAll()
  } catch (err) {
    error.value = extractApiErrorMessage(err)
  } finally {
    saving.value = false
  }
}

const statusColor = status => ({
  active: 'success', probation: 'info', notice_period: 'warning', terminated: 'error',
}[status] || 'secondary')

onMounted(loadAll)
</script>

<template>
  <VCard title="Employees">
    <template #append>
      <VBtn @click="openCreate">
        New Employee
      </VBtn>
    </template>

    <VDataTable
      :headers="[
        { title: 'Employee #', key: 'employee_number' },
        { title: 'Name', key: 'name' },
        { title: 'Type', key: 'employment_type' },
        { title: 'Status', key: 'status' },
      ]"
      :items="employees"
      :loading="loading"
      item-value="id"
      @click:row="(_, { item }) => router.push(`/hr/employees/${item.id}`)"
    >
      <template #item.status="{ item }">
        <VChip :color="statusColor(item.status)">
          {{ item.status }}
        </VChip>
      </template>
    </VDataTable>

    <VDialog
      v-model="dialog"
      max-width="600"
    >
      <VCard title="New Employee">
        <VCardText>
          <VAlert
            v-if="error"
            type="error"
            class="mb-4"
          >
            {{ error }}
          </VAlert>

          <VRow>
            <VCol
              cols="12"
              md="6"
            >
              <VTextField
                v-model="form.employee_number"
                label="Employee Number"
              />
            </VCol>
            <VCol
              cols="12"
              md="6"
            >
              <VTextField
                v-model="form.name"
                label="Name"
              />
            </VCol>
            <VCol
              cols="12"
              md="6"
            >
              <VTextField
                v-model="form.id_number"
                label="National ID Number"
              />
            </VCol>
            <VCol
              cols="12"
              md="6"
            >
              <VTextField
                v-model="form.kra_pin"
                label="KRA PIN"
              />
            </VCol>
            <VCol
              cols="12"
              md="6"
            >
              <VTextField
                v-model="form.nssf_number"
                label="NSSF Number"
              />
            </VCol>
            <VCol
              cols="12"
              md="6"
            >
              <VTextField
                v-model="form.shif_number"
                label="SHIF Number"
              />
            </VCol>
            <VCol
              cols="12"
              md="6"
            >
              <VTextField
                v-model="form.helb_account_number"
                label="HELB Account Number (optional)"
              />
            </VCol>
            <VCol
              cols="12"
              md="6"
            >
              <VSelect
                v-model="form.employment_type"
                label="Employment Type"
                :items="[
                  { title: 'Full Time', value: 'full_time' },
                  { title: 'Part Time', value: 'part_time' },
                  { title: 'Casual', value: 'casual' },
                  { title: 'Contract', value: 'contract' },
                ]"
              />
            </VCol>
            <VCol
              cols="12"
              md="6"
            >
              <VTextField
                v-model="form.date_of_hire"
                type="date"
                label="Date of Hire"
              />
            </VCol>
          </VRow>
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
