<script setup>
/* eslint-disable camelcase -- these object keys are literal Laravel API request/response field names (snake_case is the real contract), not JS identifiers to rename. */
definePage({
  meta: { action: 'read', subject: 'Projects' },
})

const route = useRoute('projects-id')
const projectId = computed(() => route.params.id)

const project = ref(null)
const completionPercentage = ref(null)
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
    const [p, pct] = await Promise.all([
      $api(`/projects/${projectId.value}`),
      $api(`/projects/${projectId.value}/completion-percentage`),
    ])

    project.value = p
    completionPercentage.value = pct.completion_percentage
  } finally {
    loading.value = false
  }
}

async function reload() {
  const [p, pct] = await Promise.all([
    $api(`/projects/${projectId.value}`),
    $api(`/projects/${projectId.value}/completion-percentage`),
  ])

  project.value = p
  completionPercentage.value = pct.completion_percentage
}

const boqLines = computed(() => project.value?.boq?.lines ?? [])
const boqSections = computed(() => project.value?.boq?.sections ?? [])

const statusColor = status => ({
  initiated: 'secondary', in_progress: 'primary', complete: 'info', defects_liability: 'warning',
  closed: 'success', cancelled: 'error', pending: 'secondary', utilized: 'primary', signed_off: 'info',
  rework_required: 'warning', invoiced: 'success', draft: 'secondary', submitted: 'primary',
  approved: 'info', rejected: 'error', executed: 'success', open: 'error',
  rectified: 'info', verified: 'success',
}[status] || 'secondary')

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

// ---- Project actions ----
const cancelDialog = ref(false)
const cancelReason = ref('')

function start() {
  return withSubmitting(() => $api(`/projects/${projectId.value}/start`, { method: 'POST' }), 'Project started.')
}
function markComplete() {
  return withSubmitting(() => $api(`/projects/${projectId.value}/mark-complete`, { method: 'POST' }), 'Project marked complete - Defects Liability Period started.')
}
function closeProject() {
  return withSubmitting(() => $api(`/projects/${projectId.value}/close`, { method: 'POST', body: { version: project.value.version } }), 'Project closed.')
}
async function confirmCancel() {
  await withSubmitting(() => $api(`/projects/${projectId.value}/cancel`, { method: 'POST', body: { reason: cancelReason.value } }), 'Project cancelled.')
  cancelDialog.value = false
  cancelReason.value = ''
}

// ---- Milestones ----
const milestoneDialog = ref(false)
const milestoneForm = ref({ sequence: 1, description: '' })
const allocateDialog = ref(false)
const allocateTarget = ref(null)
const allocateForm = ref({ mode: 'line', boq_line_id: null, section_id: null, percentage_of_value: 100 })
const reworkDialog = ref(false)
const reworkTarget = ref(null)
const reworkReason = ref('')

function openMilestoneDialog() {
  milestoneForm.value = { sequence: (project.value?.milestones?.length ?? 0) + 1, description: '' }
  milestoneDialog.value = true
}
async function saveMilestone() {
  await withSubmitting(() => $api(`/projects/${projectId.value}/milestones`, { method: 'POST', body: milestoneForm.value }))
  milestoneDialog.value = false
}
function openAllocateDialog(milestone) {
  allocateTarget.value = milestone
  allocateForm.value = { mode: 'line', boq_line_id: null, section_id: null, percentage_of_value: 100 }
  allocateDialog.value = true
}
async function saveAllocation() {
  const milestone = allocateTarget.value

  const path = allocateForm.value.mode === 'line'
    ? `/milestones/${milestone.id}/allocate-line`
    : `/milestones/${milestone.id}/allocate-section`

  const body = allocateForm.value.mode === 'line'
    ? { boq_line_id: allocateForm.value.boq_line_id, percentage_of_value: allocateForm.value.percentage_of_value }
    : { section_id: allocateForm.value.section_id, percentage_of_value: allocateForm.value.percentage_of_value }

  await withSubmitting(() => $api(path, { method: 'POST', body }), 'BOQ allocation saved.')
  allocateDialog.value = false
}
function markUtilized(milestone) {
  return withSubmitting(() => $api(`/milestones/${milestone.id}/mark-utilized`, { method: 'POST' }), 'Milestone marked utilized.')
}
function signOff(milestone) {
  return withSubmitting(() => $api(`/milestones/${milestone.id}/sign-off`, { method: 'POST' }), 'Milestone signed off.')
}
function openReworkDialog(milestone) {
  reworkTarget.value = milestone
  reworkReason.value = ''
  reworkDialog.value = true
}
async function confirmRework() {
  await withSubmitting(() => $api(`/milestones/${reworkTarget.value.id}/require-rework`, { method: 'POST', body: { reason: reworkReason.value } }), 'Rework required.')
  reworkDialog.value = false
}
function markInvoiced(milestone) {
  return withSubmitting(() => $api(`/milestones/${milestone.id}/mark-invoiced`, { method: 'POST' }), 'Milestone marked invoiced - billing locked.')
}
function closeMilestone(milestone) {
  return withSubmitting(() => $api(`/milestones/${milestone.id}/close`, { method: 'POST' }), 'Milestone closed.')
}

// ---- Variation Orders ----
const voDialog = ref(false)
const voForm = ref({ description: '' })
const voLineDialog = ref(false)
const voLineTarget = ref(null)
const voLineForm = ref({ boq_line_id: null, section_id: null, variation_type: 'quantity_change', quantity_delta: 0, rate_delta: 0, amount_delta: 0, description: '' })
const voReverseDialog = ref(false)
const voReverseTarget = ref(null)
const voReverseReason = ref('')

function openVoDialog() {
  voForm.value = { description: '' }
  voDialog.value = true
}
async function saveVo() {
  await withSubmitting(() => $api(`/projects/${projectId.value}/variation-orders`, { method: 'POST', body: voForm.value }))
  voDialog.value = false
}
function openVoLineDialog(vo) {
  voLineTarget.value = vo
  voLineForm.value = { boq_line_id: null, section_id: null, variation_type: 'quantity_change', quantity_delta: 0, rate_delta: 0, amount_delta: 0, description: '' }
  voLineDialog.value = true
}
async function saveVoLine() {
  await withSubmitting(() => $api(`/variation-orders/${voLineTarget.value.id}/lines`, { method: 'POST', body: voLineForm.value }), 'Line added to Variation Order.')
  voLineDialog.value = false
}
function submitVo(vo) {
  return withSubmitting(() => $api(`/variation-orders/${vo.id}/submit`, { method: 'POST' }), 'Variation Order submitted for approval.')
}
function approveVo(vo) {
  return withSubmitting(() => $api(`/variation-orders/${vo.id}/approve`, { method: 'POST', body: {} }), 'Variation Order approved - BOQ and Milestones updated.')
}
function rejectVo(vo) {
  return withSubmitting(() => $api(`/variation-orders/${vo.id}/reject`, { method: 'POST' }), 'Variation Order rejected.')
}
function executeVo(vo) {
  return withSubmitting(() => $api(`/variation-orders/${vo.id}/execute`, { method: 'POST' }), 'Variation Order marked executed.')
}
function openVoReverseDialog(vo) {
  voReverseTarget.value = vo
  voReverseReason.value = ''
  voReverseDialog.value = true
}
async function confirmVoReverse() {
  await withSubmitting(() => $api(`/variation-orders/${voReverseTarget.value.id}/reverse`, { method: 'POST', body: { reason: voReverseReason.value } }), 'Variation Order reversed.')
  voReverseDialog.value = false
}

// ---- Defects ----
const defectDialog = ref(false)
const defectForm = ref({ description: '', severity: 'minor', blocks_retention: false, milestone_id: null })

function openDefectDialog() {
  defectForm.value = { description: '', severity: 'minor', blocks_retention: false, milestone_id: null }
  defectDialog.value = true
}
async function saveDefect() {
  await withSubmitting(() => $api(`/projects/${projectId.value}/defects`, { method: 'POST', body: defectForm.value }))
  defectDialog.value = false
}
function toggleBlocksRetention(defect) {
  return withSubmitting(() => $api(`/defects/${defect.id}/blocks-retention`, { method: 'POST', body: { blocks_retention: !defect.blocks_retention } }))
}
function advanceDefect(defect, status) {
  return withSubmitting(() => $api(`/defects/${defect.id}/advance`, { method: 'POST', body: { status } }), 'Defect status updated.')
}
const defectNextStatus = { open: 'in_progress', in_progress: 'rectified', rectified: 'verified', verified: 'closed' }

onMounted(loadAll)
</script>

<template>
  <div v-if="loading">
    <VProgressCircular indeterminate />
  </div>

  <div v-else-if="project">
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
      :title="project.name"
    >
      <template #subtitle>
        {{ project.party?.name }} · Completion: {{ completionPercentage ? Math.round(completionPercentage * 100) : 0 }}%
      </template>
      <template #append>
        <VChip :color="statusColor(project.status)">
          {{ project.status }}
        </VChip>
      </template>
      <VCardText>
        <VBtn
          v-if="project.status === 'initiated'"
          class="me-2"
          :loading="submitting"
          @click="start"
        >
          Start Project
        </VBtn>
        <VBtn
          v-if="project.status === 'in_progress'"
          class="me-2"
          :loading="submitting"
          @click="markComplete"
        >
          Mark Complete
        </VBtn>
        <VBtn
          v-if="project.status === 'defects_liability'"
          class="me-2"
          :disabled="!project.dlp_ready_to_close"
          :loading="submitting"
          @click="closeProject"
        >
          {{ project.dlp_ready_to_close ? 'Close Project' : 'Not Yet Ready to Close (DLP in progress)' }}
        </VBtn>
        <VBtn
          v-if="!['closed', 'cancelled'].includes(project.status)"
          variant="text"
          color="error"
          :loading="submitting"
          @click="cancelDialog = true"
        >
          Cancel Project
        </VBtn>
      </VCardText>
    </VCard>

    <VCard
      class="mb-4"
      title="Milestones"
    >
      <template #append>
        <VBtn
          size="small"
          @click="openMilestoneDialog"
        >
          New Milestone
        </VBtn>
      </template>
      <VCardText>
        <VCard
          v-for="milestone in project.milestones"
          :key="milestone.id"
          variant="outlined"
          class="mb-2"
        >
          <VCardText>
            <p class="mb-2">
              <strong>#{{ milestone.sequence }} {{ milestone.description }}</strong>
              <VChip
                class="ms-2"
                :color="statusColor(milestone.status)"
              >
                {{ milestone.status }}
              </VChip>
              <span class="ms-2 text-medium-emphasis">Billing: {{ milestone.billing_amount }}{{ milestone.billing_locked ? ' (locked)' : '' }}</span>
            </p>

            <VBtn
              size="small"
              class="me-2"
              variant="text"
              :disabled="milestone.billing_locked"
              @click="openAllocateDialog(milestone)"
            >
              Allocate BOQ
            </VBtn>
            <VBtn
              v-if="['pending', 'rework_required'].includes(milestone.status)"
              size="small"
              class="me-2"
              :loading="submitting"
              @click="markUtilized(milestone)"
            >
              Mark Utilized
            </VBtn>
            <VBtn
              v-if="milestone.status === 'utilized'"
              size="small"
              class="me-2"
              :loading="submitting"
              @click="signOff(milestone)"
            >
              Sign Off
            </VBtn>
            <VBtn
              v-if="['utilized', 'signed_off'].includes(milestone.status)"
              size="small"
              class="me-2"
              variant="text"
              color="warning"
              @click="openReworkDialog(milestone)"
            >
              Require Rework
            </VBtn>
            <VBtn
              v-if="milestone.status === 'signed_off'"
              size="small"
              class="me-2"
              :loading="submitting"
              @click="markInvoiced(milestone)"
            >
              Mark Invoiced
            </VBtn>
            <VBtn
              v-if="milestone.status === 'invoiced'"
              size="small"
              :loading="submitting"
              @click="closeMilestone(milestone)"
            >
              Close
            </VBtn>
          </VCardText>
        </VCard>
        <p
          v-if="!project.milestones?.length"
          class="text-medium-emphasis"
        >
          No milestones yet.
        </p>
      </VCardText>
    </VCard>

    <VCard
      class="mb-4"
      title="Variation Orders"
    >
      <template #append>
        <VBtn
          size="small"
          @click="openVoDialog"
        >
          New Variation Order
        </VBtn>
      </template>
      <VCardText>
        <VCard
          v-for="vo in project.variationOrders"
          :key="vo.id"
          variant="outlined"
          class="mb-2"
        >
          <VCardText>
            <p class="mb-2">
              <strong>{{ vo.variation_number }}</strong> - {{ vo.description }}
              <VChip
                class="ms-2"
                :color="statusColor(vo.status)"
              >
                {{ vo.status }}
              </VChip>
              <span class="ms-2 text-medium-emphasis">Delta: {{ vo.amount_delta }}</span>
            </p>

            <VBtn
              v-if="vo.status === 'draft'"
              size="small"
              class="me-2"
              variant="text"
              @click="openVoLineDialog(vo)"
            >
              Add Line
            </VBtn>
            <VBtn
              v-if="vo.status === 'draft'"
              size="small"
              class="me-2"
              :loading="submitting"
              @click="submitVo(vo)"
            >
              Submit
            </VBtn>
            <VBtn
              v-if="vo.status === 'submitted'"
              size="small"
              class="me-2"
              :loading="submitting"
              @click="approveVo(vo)"
            >
              Approve
            </VBtn>
            <VBtn
              v-if="vo.status === 'submitted'"
              size="small"
              class="me-2"
              variant="text"
              color="error"
              :loading="submitting"
              @click="rejectVo(vo)"
            >
              Reject
            </VBtn>
            <VBtn
              v-if="vo.status === 'approved'"
              size="small"
              class="me-2"
              :loading="submitting"
              @click="executeVo(vo)"
            >
              Mark Executed
            </VBtn>
            <VBtn
              v-if="['approved', 'executed'].includes(vo.status)"
              size="small"
              variant="text"
              color="warning"
              @click="openVoReverseDialog(vo)"
            >
              Reverse
            </VBtn>
          </VCardText>
        </VCard>
        <p
          v-if="!project.variationOrders?.length"
          class="text-medium-emphasis"
        >
          No variation orders yet.
        </p>
      </VCardText>
    </VCard>

    <VCard title="Defects">
      <template #append>
        <VBtn
          size="small"
          @click="openDefectDialog"
        >
          Report Defect
        </VBtn>
      </template>
      <VCardText>
        <VCard
          v-for="defect in project.defects"
          :key="defect.id"
          variant="outlined"
          class="mb-2"
        >
          <VCardText>
            <p class="mb-2">
              {{ defect.description }}
              <VChip
                class="ms-2"
                :color="statusColor(defect.status)"
              >
                {{ defect.status }}
              </VChip>
              <VChip
                class="ms-2"
                variant="outlined"
              >
                {{ defect.severity }}
              </VChip>
              <VChip
                v-if="defect.blocks_retention"
                class="ms-2"
                color="error"
                variant="outlined"
              >
                Blocks Retention
              </VChip>
            </p>

            <VBtn
              size="small"
              class="me-2"
              variant="text"
              @click="toggleBlocksRetention(defect)"
            >
              {{ defect.blocks_retention ? 'Unmark' : 'Mark' }} Blocks Retention
            </VBtn>
            <VBtn
              v-if="defectNextStatus[defect.status]"
              size="small"
              :loading="submitting"
              @click="advanceDefect(defect, defectNextStatus[defect.status])"
            >
              Advance to {{ defectNextStatus[defect.status] }}
            </VBtn>
          </VCardText>
        </VCard>
        <p
          v-if="!project.defects?.length"
          class="text-medium-emphasis"
        >
          No defects reported.
        </p>
      </VCardText>
    </VCard>

    <!-- Cancel Project -->
    <VDialog
      v-model="cancelDialog"
      max-width="450"
    >
      <VCard title="Cancel Project">
        <VCardText>
          <p class="mb-4 text-medium-emphasis">
            Any unbilled Milestones will be written off. This cannot be undone.
          </p>
          <VTextarea
            v-model="cancelReason"
            label="Reason"
          />
        </VCardText>
        <VCardActions>
          <VSpacer />
          <VBtn
            variant="text"
            @click="cancelDialog = false"
          >
            Back
          </VBtn>
          <VBtn
            color="error"
            :loading="submitting"
            :disabled="!cancelReason"
            @click="confirmCancel"
          >
            Confirm Cancel
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <!-- New Milestone -->
    <VDialog
      v-model="milestoneDialog"
      max-width="450"
    >
      <VCard title="New Milestone">
        <VCardText>
          <VTextField
            v-model.number="milestoneForm.sequence"
            type="number"
            label="Sequence"
            class="mb-4"
          />
          <VTextField
            v-model="milestoneForm.description"
            label="Description"
          />
        </VCardText>
        <VCardActions>
          <VSpacer />
          <VBtn
            variant="text"
            @click="milestoneDialog = false"
          >
            Cancel
          </VBtn>
          <VBtn
            :loading="submitting"
            @click="saveMilestone"
          >
            Save
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <!-- Allocate BOQ -->
    <VDialog
      v-model="allocateDialog"
      max-width="500"
    >
      <VCard title="Allocate BOQ to Milestone">
        <VCardText>
          <VBtnToggle
            v-model="allocateForm.mode"
            class="mb-4"
            mandatory
          >
            <VBtn value="line">
              Single Line
            </VBtn>
            <VBtn value="section">
              Whole Section
            </VBtn>
          </VBtnToggle>

          <VSelect
            v-if="allocateForm.mode === 'line'"
            v-model="allocateForm.boq_line_id"
            label="BOQ Line"
            class="mb-4"
            item-title="description"
            item-value="id"
            :items="boqLines"
          />
          <VSelect
            v-else
            v-model="allocateForm.section_id"
            label="BOQ Section"
            class="mb-4"
            item-title="name"
            item-value="id"
            :items="boqSections"
          />

          <VTextField
            v-model.number="allocateForm.percentage_of_value"
            type="number"
            label="Percentage of Value"
            suffix="%"
          />
        </VCardText>
        <VCardActions>
          <VSpacer />
          <VBtn
            variant="text"
            @click="allocateDialog = false"
          >
            Cancel
          </VBtn>
          <VBtn
            :loading="submitting"
            @click="saveAllocation"
          >
            Save
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <!-- Require Rework -->
    <VDialog
      v-model="reworkDialog"
      max-width="450"
    >
      <VCard title="Require Rework">
        <VCardText>
          <VTextarea
            v-model="reworkReason"
            label="Reason"
          />
        </VCardText>
        <VCardActions>
          <VSpacer />
          <VBtn
            variant="text"
            @click="reworkDialog = false"
          >
            Cancel
          </VBtn>
          <VBtn
            color="warning"
            :loading="submitting"
            :disabled="!reworkReason"
            @click="confirmRework"
          >
            Confirm
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <!-- New Variation Order -->
    <VDialog
      v-model="voDialog"
      max-width="450"
    >
      <VCard title="New Variation Order">
        <VCardText>
          <VTextarea
            v-model="voForm.description"
            label="Description"
          />
        </VCardText>
        <VCardActions>
          <VSpacer />
          <VBtn
            variant="text"
            @click="voDialog = false"
          >
            Cancel
          </VBtn>
          <VBtn
            :loading="submitting"
            @click="saveVo"
          >
            Save
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <!-- Add VO Line -->
    <VDialog
      v-model="voLineDialog"
      max-width="500"
    >
      <VCard title="Add Variation Order Line">
        <VCardText>
          <VSelect
            v-model="voLineForm.variation_type"
            label="Type"
            class="mb-4"
            :items="[
              { title: 'Quantity Change', value: 'quantity_change' },
              { title: 'Rate Change', value: 'rate_change' },
              { title: 'New Item', value: 'new_item' },
              { title: 'Omission', value: 'omission' },
            ]"
          />
          <VSelect
            v-if="voLineForm.variation_type !== 'new_item'"
            v-model="voLineForm.boq_line_id"
            label="BOQ Line"
            class="mb-4"
            item-title="description"
            item-value="id"
            :items="boqLines"
          />
          <VSelect
            v-else
            v-model="voLineForm.section_id"
            label="BOQ Section (new item goes here)"
            class="mb-4"
            item-title="name"
            item-value="id"
            :items="boqSections"
          />
          <VTextField
            v-model.number="voLineForm.quantity_delta"
            type="number"
            label="Quantity Delta"
            class="mb-4"
          />
          <VTextField
            v-model.number="voLineForm.rate_delta"
            type="number"
            label="Rate Delta"
            class="mb-4"
          />
          <VTextField
            v-model.number="voLineForm.amount_delta"
            type="number"
            label="Amount Delta"
            class="mb-4"
          />
          <VTextField
            v-model="voLineForm.description"
            label="Description"
          />
        </VCardText>
        <VCardActions>
          <VSpacer />
          <VBtn
            variant="text"
            @click="voLineDialog = false"
          >
            Cancel
          </VBtn>
          <VBtn
            :loading="submitting"
            @click="saveVoLine"
          >
            Save
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <!-- Reverse VO -->
    <VDialog
      v-model="voReverseDialog"
      max-width="450"
    >
      <VCard title="Reverse Variation Order">
        <VCardText>
          <p class="mb-4 text-medium-emphasis">
            This restores the BOQ and affected Milestones to their state before this Variation Order.
          </p>
          <VTextarea
            v-model="voReverseReason"
            label="Reason"
          />
        </VCardText>
        <VCardActions>
          <VSpacer />
          <VBtn
            variant="text"
            @click="voReverseDialog = false"
          >
            Cancel
          </VBtn>
          <VBtn
            color="warning"
            :loading="submitting"
            :disabled="!voReverseReason"
            @click="confirmVoReverse"
          >
            Confirm Reverse
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <!-- Report Defect -->
    <VDialog
      v-model="defectDialog"
      max-width="450"
    >
      <VCard title="Report Defect">
        <VCardText>
          <VTextarea
            v-model="defectForm.description"
            label="Description"
            class="mb-4"
          />
          <VSelect
            v-model="defectForm.severity"
            label="Severity"
            class="mb-4"
            :items="[
              { title: 'Minor', value: 'minor' },
              { title: 'Major', value: 'major' },
              { title: 'Critical', value: 'critical' },
            ]"
          />
          <VSwitch
            v-model="defectForm.blocks_retention"
            label="Blocks Retention Release"
          />
        </VCardText>
        <VCardActions>
          <VSpacer />
          <VBtn
            variant="text"
            @click="defectDialog = false"
          >
            Cancel
          </VBtn>
          <VBtn
            :loading="submitting"
            @click="saveDefect"
          >
            Save
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>
