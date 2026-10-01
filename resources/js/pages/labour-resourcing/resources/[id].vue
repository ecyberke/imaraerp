<script setup>
/* eslint-disable camelcase -- these object keys are literal Laravel API request/response field names (snake_case is the real contract), not JS identifiers to rename. */
definePage({
  meta: { action: 'read', subject: 'Resourcing' },
})

const route = useRoute('labour-resourcing-resources-id')
const resourceId = computed(() => route.params.id)

const resource = ref(null)
const salesOrders = ref([])
const loading = ref(true)
const submitting = ref(false)
const notice = ref('')
const noticeType = ref('success')

function flash(message, type = 'success') {
  notice.value = message
  noticeType.value = type
  setTimeout(() => { notice.value = '' }, 6000)
}

async function loadEverything() {
  loading.value = true
  try {
    const [r, orders] = await Promise.all([$api(`/resources/${resourceId.value}`), $api('/sales-orders')])

    resource.value = r
    salesOrders.value = orders
  } finally {
    loading.value = false
  }
}

async function reload() {
  resource.value = await $api(`/resources/${resourceId.value}`)
}

const assignForm = ref({ sales_order_id: null, block_start_date: '', block_end_date: '' })

async function createAssignment() {
  submitting.value = true
  try {
    await $api(`/resources/${resourceId.value}/resource-assignments`, { method: 'POST', body: assignForm.value })
    assignForm.value = { sales_order_id: null, block_start_date: '', block_end_date: '' }
    await reload()
    flash('Assignment created.')
  } catch (err) {
    flash(extractApiErrorMessage(err), 'error')
  } finally {
    submitting.value = false
  }
}

async function endEarly(assignment) {
  submitting.value = true
  try {
    await $api(`/resource-assignments/${assignment.id}/end-early`, { method: 'POST' })
    await reload()
    flash('Assignment ended early.')
  } catch (err) {
    flash(extractApiErrorMessage(err), 'error')
  } finally {
    submitting.value = false
  }
}

const extendDate = ref({})

async function extend(assignment) {
  submitting.value = true
  try {
    await $api(`/resource-assignments/${assignment.id}/extend`, {
      method: 'POST',
      body: { block_end_date: extendDate.value[assignment.id] },
    })
    await reload()
    flash('Assignment extended.')
  } catch (err) {
    flash(extractApiErrorMessage(err), 'error')
  } finally {
    submitting.value = false
  }
}

async function cancelAssignment(assignment) {
  submitting.value = true
  try {
    await $api(`/resource-assignments/${assignment.id}/cancel`, { method: 'POST' })
    await reload()
    flash('Assignment cancelled.')
  } catch (err) {
    flash(extractApiErrorMessage(err), 'error')
  } finally {
    submitting.value = false
  }
}

const statusColor = status => ({
  scheduled: 'info', active: 'primary', completed: 'success', cancelled: 'secondary',
}[status] || 'secondary')

onMounted(loadEverything)
</script>

<template>
  <div v-if="loading">
    <VProgressCircular indeterminate />
  </div>

  <div v-else-if="resource">
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
      :title="`Resource #${resource.id}`"
    >
      <template #subtitle>
        {{ resource.type }} · {{ resource.skill_category || 'No skill category set' }}
      </template>
    </VCard>

    <VCard
      class="mb-4"
      title="New Assignment"
    >
      <VCardText>
        <VRow>
          <VCol
            cols="12"
            md="4"
          >
            <VSelect
              v-model="assignForm.sales_order_id"
              label="Quotation / Sales Order"
              item-title="document_number"
              item-value="id"
              :items="salesOrders"
            />
          </VCol>
          <VCol
            cols="6"
            md="3"
          >
            <VTextField
              v-model="assignForm.block_start_date"
              type="date"
              label="Start Date"
            />
          </VCol>
          <VCol
            cols="6"
            md="3"
          >
            <VTextField
              v-model="assignForm.block_end_date"
              type="date"
              label="End Date"
            />
          </VCol>
          <VCol
            cols="12"
            md="2"
            class="d-flex align-center"
          >
            <VBtn
              :loading="submitting"
              @click="createAssignment"
            >
              Assign
            </VBtn>
          </VCol>
        </VRow>
        <p class="text-caption text-medium-emphasis mt-2">
          Attaching an assignment to a Project isn't available yet - Project doesn't exist as a module in this build. Assign against a Quotation/Sales Order for now.
        </p>
      </VCardText>
    </VCard>

    <VCard title="Assignments">
      <VCardText>
        <VCard
          v-for="assignment in resource.assignments"
          :key="assignment.id"
          variant="outlined"
          class="mb-2"
        >
          <VCardText>
            <p class="mb-2">
              <strong>{{ assignment.block_start_date?.slice(0, 10) }} to {{ assignment.block_end_date?.slice(0, 10) }}</strong>
              <VChip
                class="ms-2"
                :color="statusColor(assignment.status)"
              >
                {{ assignment.status }}
              </VChip>
            </p>

            <template v-if="['scheduled', 'active'].includes(assignment.status)">
              <VBtn
                size="small"
                class="me-2"
                :loading="submitting"
                @click="endEarly(assignment)"
              >
                End Early
              </VBtn>
              <VBtn
                size="small"
                class="me-2"
                variant="text"
                :loading="submitting"
                @click="cancelAssignment(assignment)"
              >
                Cancel
              </VBtn>
            </template>

            <VRow
              align="center"
              class="mt-2"
            >
              <VCol
                cols="6"
                md="4"
              >
                <VTextField
                  v-model="extendDate[assignment.id]"
                  type="date"
                  label="Extend To"
                  density="compact"
                />
              </VCol>
              <VCol
                cols="6"
                md="4"
              >
                <VBtn
                  size="small"
                  :disabled="!extendDate[assignment.id]"
                  :loading="submitting"
                  @click="extend(assignment)"
                >
                  Extend
                </VBtn>
              </VCol>
            </VRow>
          </VCardText>
        </VCard>
      </VCardText>
    </VCard>
  </div>
</template>
