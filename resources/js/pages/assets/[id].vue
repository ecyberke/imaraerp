<script setup>
/* eslint-disable camelcase -- these object keys are literal Laravel API request/response field names (snake_case is the real contract), not JS identifiers to rename. */
definePage({
  meta: { action: 'read', subject: 'Assets' },
})

const route = useRoute('assets-id')
const assetId = computed(() => route.params.id)

const asset = ref(null)
const projects = ref([])
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
    const [a, p] = await Promise.all([$api(`/assets/${assetId.value}`), $api('/projects')])

    asset.value = a
    projects.value = p
  } finally {
    loading.value = false
  }
}

async function reload() {
  asset.value = await $api(`/assets/${assetId.value}`)
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

// ---- Lifespan / status ----
const lifespanDialog = ref(false)
const lifespanForm = ref({ useful_life_years: null, reason: '' })

function openLifespanDialog() {
  lifespanForm.value = { useful_life_years: asset.value.useful_life_years, reason: '' }
  lifespanDialog.value = true
}
async function saveLifespan() {
  await withSubmitting(() => $api(`/assets/${assetId.value}/lifespan`, { method: 'POST', body: lifespanForm.value }), 'Useful life updated.')
  lifespanDialog.value = false
}
function toggleMaintenance() {
  const status = asset.value.status === 'under_maintenance' ? 'in_use' : 'under_maintenance'

  return withSubmitting(() => $api(`/assets/${assetId.value}/status`, { method: 'POST', body: { status } }), 'Status updated.')
}

// ---- Revaluation ----
const revalueDialog = ref(false)
const revalueForm = ref({ new_valuation: null, new_residual_value: null, new_useful_life_years: null, reason: '' })

function openRevalueDialog() {
  revalueForm.value = { new_valuation: null, new_residual_value: null, new_useful_life_years: null, reason: '' }
  revalueDialog.value = true
}
async function saveRevaluation() {
  await withSubmitting(() => $api(`/assets/${assetId.value}/revalue`, { method: 'POST', body: revalueForm.value }), 'Asset revalued.')
  revalueDialog.value = false
}

// ---- Disposal ----
const disposeDialog = ref(false)
const disposeForm = ref({ disposal_type: 'sold', sale_proceeds: null, sold_on_credit: false })

async function confirmDispose() {
  await withSubmitting(() => $api(`/assets/${assetId.value}/dispose`, { method: 'POST', body: disposeForm.value }), 'Asset disposed.')
  disposeDialog.value = false
}

// ---- Assignments ----
const assignDialog = ref(false)
const assignForm = ref({ project_id: null, assigned_date: '', internal_daily_rate: null })
const chargeDays = ref({})

function openAssignDialog() {
  assignForm.value = { project_id: null, assigned_date: '', internal_daily_rate: null }
  assignDialog.value = true
}
async function saveAssignment() {
  await withSubmitting(() => $api(`/assets/${assetId.value}/assignments`, { method: 'POST', body: assignForm.value }), 'Asset assigned to project.')
  assignDialog.value = false
}
function releaseAssignment(assignment) {
  return withSubmitting(() => $api(`/asset-assignments/${assignment.id}/release`, {
    method: 'POST',
    body: { released_date: new Date().toISOString().slice(0, 10) },
  }), 'Assignment released.')
}
function postCharge(assignment) {
  return withSubmitting(() => $api(`/asset-assignments/${assignment.id}/post-charge`, {
    method: 'POST',
    body: { days: chargeDays.value[assignment.id] },
  }), 'Internal equipment charge posted.')
}

const statusColor = status => ({
  in_use: 'success', under_maintenance: 'warning', disposed: 'secondary', written_off: 'error', active: 'primary', released: 'secondary',
}[status] || 'secondary')

onMounted(loadAll)
</script>

<template>
  <div v-if="loading">
    <VProgressCircular indeterminate />
  </div>

  <div v-else-if="asset">
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
      :title="`${asset.asset_number} - ${asset.name}`"
    >
      <template #subtitle>
        {{ asset.category?.name }} · Purchase Cost {{ asset.purchase_cost }} · Residual {{ asset.residual_value }} · {{ asset.useful_life_years }}yr {{ asset.depreciation_method }}
      </template>
      <template #append>
        <VChip :color="statusColor(asset.status)">
          {{ asset.status }}
        </VChip>
      </template>
      <VCardText v-if="!['disposed', 'written_off'].includes(asset.status)">
        <VBtn
          size="small"
          class="me-2"
          variant="text"
          @click="openLifespanDialog"
        >
          Change Useful Life
        </VBtn>
        <VBtn
          size="small"
          class="me-2"
          variant="text"
          :loading="submitting"
          @click="toggleMaintenance"
        >
          {{ asset.status === 'under_maintenance' ? 'Mark In Use' : 'Mark Under Maintenance' }}
        </VBtn>
        <VBtn
          size="small"
          class="me-2"
          variant="text"
          @click="openRevalueDialog"
        >
          Revalue
        </VBtn>
        <VBtn
          size="small"
          variant="text"
          color="error"
          @click="disposeDialog = true"
        >
          Dispose
        </VBtn>
      </VCardText>
    </VCard>

    <VCard
      v-if="asset.asset_type === 'plant_equipment'"
      class="mb-4"
      title="Project Assignments"
    >
      <template #append>
        <VBtn
          v-if="!['disposed', 'written_off'].includes(asset.status)"
          size="small"
          @click="openAssignDialog"
        >
          Assign to Project
        </VBtn>
      </template>
      <VCardText>
        <VCard
          v-for="assignment in asset.assignments"
          :key="assignment.id"
          variant="outlined"
          class="mb-2"
        >
          <VCardText>
            <p class="mb-2">
              <strong>Project #{{ assignment.project_id }}</strong> from {{ assignment.assigned_date?.slice(0, 10) }}
              <VChip
                class="ms-2"
                :color="statusColor(assignment.status)"
              >
                {{ assignment.status }}
              </VChip>
              <span class="ms-2 text-medium-emphasis">{{ assignment.internal_daily_rate }}/day</span>
            </p>

            <template v-if="assignment.status === 'active'">
              <VRow align="center">
                <VCol
                  cols="6"
                  md="3"
                >
                  <VTextField
                    v-model.number="chargeDays[assignment.id]"
                    type="number"
                    label="Days to charge"
                    density="compact"
                  />
                </VCol>
                <VCol
                  cols="6"
                  md="3"
                >
                  <VBtn
                    size="small"
                    class="me-2"
                    :disabled="!chargeDays[assignment.id]"
                    :loading="submitting"
                    @click="postCharge(assignment)"
                  >
                    Post Charge
                  </VBtn>
                  <VBtn
                    size="small"
                    variant="text"
                    :loading="submitting"
                    @click="releaseAssignment(assignment)"
                  >
                    Release
                  </VBtn>
                </VCol>
              </VRow>
            </template>
          </VCardText>
        </VCard>
        <p
          v-if="!asset.assignments?.length"
          class="text-medium-emphasis"
        >
          Not currently assigned to a project.
        </p>
      </VCardText>
    </VCard>

    <VCard title="Revaluation History">
      <VCardText>
        <VCard
          v-for="revaluation in asset.revaluations"
          :key="revaluation.id"
          variant="outlined"
          class="mb-2"
        >
          <VCardText>
            {{ revaluation.revaluation_date?.slice(0, 10) }} - NBV {{ revaluation.net_book_value_at_revaluation }} → {{ revaluation.new_valuation }} ({{ revaluation.reason }})
          </VCardText>
        </VCard>
        <p
          v-if="!asset.revaluations?.length"
          class="text-medium-emphasis"
        >
          No revaluations recorded.
        </p>
      </VCardText>
    </VCard>

    <!-- Lifespan -->
    <VDialog
      v-model="lifespanDialog"
      max-width="450"
    >
      <VCard title="Change Useful Life">
        <VCardText>
          <VTextField
            v-model.number="lifespanForm.useful_life_years"
            type="number"
            label="New Useful Life (years)"
            class="mb-4"
          />
          <VTextarea
            v-model="lifespanForm.reason"
            label="Reason"
          />
        </VCardText>
        <VCardActions>
          <VSpacer />
          <VBtn
            variant="text"
            @click="lifespanDialog = false"
          >
            Cancel
          </VBtn>
          <VBtn
            :loading="submitting"
            :disabled="!lifespanForm.reason"
            @click="saveLifespan"
          >
            Save
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <!-- Revalue -->
    <VDialog
      v-model="revalueDialog"
      max-width="450"
    >
      <VCard title="Revalue Asset">
        <VCardText>
          <VTextField
            v-model.number="revalueForm.new_valuation"
            type="number"
            label="New Valuation"
            class="mb-4"
          />
          <VTextField
            v-model.number="revalueForm.new_residual_value"
            type="number"
            label="New Residual Value (optional)"
            class="mb-4"
          />
          <VTextField
            v-model.number="revalueForm.new_useful_life_years"
            type="number"
            label="New Useful Life Years (optional)"
            class="mb-4"
          />
          <VTextarea
            v-model="revalueForm.reason"
            label="Reason"
          />
        </VCardText>
        <VCardActions>
          <VSpacer />
          <VBtn
            variant="text"
            @click="revalueDialog = false"
          >
            Cancel
          </VBtn>
          <VBtn
            :loading="submitting"
            :disabled="!revalueForm.new_valuation || !revalueForm.reason"
            @click="saveRevaluation"
          >
            Save
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <!-- Dispose -->
    <VDialog
      v-model="disposeDialog"
      max-width="450"
    >
      <VCard title="Dispose Asset">
        <VCardText>
          <VSelect
            v-model="disposeForm.disposal_type"
            label="Disposal Type"
            class="mb-4"
            :items="[
              { title: 'Sold', value: 'sold' },
              { title: 'Scrapped', value: 'scrapped' },
              { title: 'Written Off', value: 'written_off' },
            ]"
          />
          <VTextField
            v-model.number="disposeForm.sale_proceeds"
            type="number"
            label="Sale Proceeds (optional)"
            class="mb-4"
          />
          <VSwitch
            v-model="disposeForm.sold_on_credit"
            label="Sold on Credit"
          />
        </VCardText>
        <VCardActions>
          <VSpacer />
          <VBtn
            variant="text"
            @click="disposeDialog = false"
          >
            Cancel
          </VBtn>
          <VBtn
            color="error"
            :loading="submitting"
            @click="confirmDispose"
          >
            Confirm Dispose
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <!-- Assign -->
    <VDialog
      v-model="assignDialog"
      max-width="450"
    >
      <VCard title="Assign to Project">
        <VCardText>
          <VSelect
            v-model="assignForm.project_id"
            label="Project"
            class="mb-4"
            item-title="name"
            item-value="id"
            :items="projects"
          />
          <VTextField
            v-model="assignForm.assigned_date"
            type="date"
            label="Assigned Date"
            class="mb-4"
          />
          <VTextField
            v-model.number="assignForm.internal_daily_rate"
            type="number"
            label="Internal Daily Rate"
          />
        </VCardText>
        <VCardActions>
          <VSpacer />
          <VBtn
            variant="text"
            @click="assignDialog = false"
          >
            Cancel
          </VBtn>
          <VBtn
            :loading="submitting"
            @click="saveAssignment"
          >
            Save
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>
