<script setup>
/* eslint-disable camelcase -- these object keys are literal Laravel API request/response field names (snake_case is the real contract), not JS identifiers to rename. */
definePage({
  meta: { action: 'read', subject: 'SalesBilling' },
})

const route = useRoute('sales-billing-boq-id')
const boqId = computed(() => route.params.id)

const boq = ref(null)
const stagingRows = ref([])
const items = ref([])
const projectOrders = ref([])
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
    const [b, staging, i, orders] = await Promise.all([
      $api(`/boqs/${boqId.value}`),
      $api('/boq-import-stagings'),
      $api('/items'),
      $api('/sales-orders'),
    ])

    boq.value = b
    stagingRows.value = staging.filter(s => s.boq_id === Number(boqId.value) && s.status === 'pending_review')
    items.value = i
    projectOrders.value = orders.filter(o => ['project', 'manufacture_for_project'].includes(o.supply_path))
  } finally {
    loading.value = false
  }
}

async function reload() {
  const [b, staging] = await Promise.all([$api(`/boqs/${boqId.value}`), $api('/boq-import-stagings')])

  boq.value = b
  stagingRows.value = staging.filter(s => s.boq_id === Number(boqId.value) && s.status === 'pending_review')
}

// --- Import a row into staging ---
const newRow = ref({ description: '', unit: '', quantity: 1, rate: 0 })

async function stageRow() {
  submitting.value = true
  try {
    await $api('/boq-import-stagings', {
      method: 'POST',
      body: {
        boq_id: boqId.value,
        rows: [{ description: newRow.value.description, unit: newRow.value.unit }],
      },
    })
    newRow.value = { description: '', unit: '', quantity: 1, rate: 0 }
    await reload()
    flash('Row added to staging - map it to a section and confirm below.')
  } catch (err) {
    flash(extractApiErrorMessage(err), 'error')
  } finally {
    submitting.value = false
  }
}

// --- Map + confirm a staging row ---
const mapForms = ref({})

function mapForm(row) {
  if (!mapForms.value[row.id])
    mapForms.value[row.id] = { item_id: null, section: '', quantity: 1, rate: 0 }

  return mapForms.value[row.id]
}

async function mapAndConfirm(row) {
  submitting.value = true
  try {
    const form = mapForm(row)

    await $api(`/boq-import-stagings/${row.id}/map`, { method: 'PATCH', body: form })
    await $api(`/boq-import-stagings/${row.id}/confirm`, { method: 'POST', body: { boq_id: boqId.value } })
    await reload()
    flash('Line confirmed onto the BOQ.')
  } catch (err) {
    flash(extractApiErrorMessage(err), 'error')
  } finally {
    submitting.value = false
  }
}

// --- Measurement + certification ---
const measureForms = ref({})

function measureForm(lineId) {
  if (!measureForms.value[lineId])
    measureForms.value[lineId] = { period: '', current_qty: 0 }

  return measureForms.value[lineId]
}

async function recordMeasurement(line) {
  submitting.value = true
  try {
    await $api(`/boq-lines/${line.id}/measurement-sheets`, { method: 'POST', body: measureForm(line.id) })
    await reload()
    flash('Measurement recorded - certify it once verified.')
  } catch (err) {
    flash(extractApiErrorMessage(err), 'error')
  } finally {
    submitting.value = false
  }
}

async function certifyLatest(line) {
  const sheets = line.measurement_sheets || []
  const latest = sheets.filter(s => s.status !== 'certified').at(-1)

  if (!latest)
    return

  submitting.value = true
  try {
    await $api(`/measurement-sheets/${latest.id}/certify`, { method: 'POST' })
    await reload()
    flash('Measurement certified.')
  } catch (err) {
    flash(extractApiErrorMessage(err), 'error')
  } finally {
    submitting.value = false
  }
}

// --- Bill a whole section in one action ---
const billOrderId = ref(null)

function linesForSection(sectionId) {
  return (boq.value?.lines || []).filter(l => l.section_id === sectionId)
}

function billableQuantity(line) {
  const certifiedSheets = (line.measurement_sheets || []).filter(s => s.status === 'certified')
  if (!certifiedSheets.length)
    return line.quantity

  return Math.max(...certifiedSheets.map(s => Number(s.certified_qty)))
}

async function billSection(section) {
  if (!billOrderId.value)
    return

  submitting.value = true
  try {
    const lines = linesForSection(section.id).map(l => ({
      description: l.description,
      quantity: billableQuantity(l),
      unit_price: l.rate,
      source_type: 'BoqLine',
      source_id: l.id,
    }))

    await $api('/invoices', {
      method: 'POST',
      body: { sales_order_id: billOrderId.value, payment_terms: 'credit', lines },
    })
    flash(`Section "${section.name}" billed in one action - Invoice created. Open the Quotation to raise it.`)
  } catch (err) {
    flash(extractApiErrorMessage(err), 'error')
  } finally {
    submitting.value = false
  }
}

onMounted(loadEverything)
</script>

<template>
  <div v-if="loading">
    <VProgressCircular indeterminate />
  </div>

  <div v-else-if="boq">
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
      :title="`BOQ #${boq.id}`"
    >
      <template #subtitle>
        Status: {{ boq.status }} · {{ boq.lines.length }} confirmed line(s)
      </template>
    </VCard>

    <!-- Bill an entire section in one action -->
    <VCard
      v-if="boq.sections.length"
      class="mb-4"
      title="Bill from BOQ Section"
    >
      <VCardText>
        <p class="mb-4 text-medium-emphasis">
          Pick a Project-path Quotation to bill, then bill an entire section's certified lines in one action - no need to enter each line by hand.
        </p>

        <VSelect
          v-model="billOrderId"
          label="Project-path Quotation to bill"
          class="mb-4"
          item-title="document_number"
          item-value="id"
          :items="projectOrders"
        />

        <VCard
          v-for="section in boq.sections"
          :key="section.id"
          variant="outlined"
          class="mb-2"
        >
          <VCardText class="d-flex align-center justify-space-between">
            <div>
              <strong>{{ section.name }}</strong>
              <span class="text-medium-emphasis ms-2">{{ linesForSection(section.id).length }} line(s)</span>
            </div>
            <VBtn
              :disabled="!billOrderId || !linesForSection(section.id).length"
              :loading="submitting"
              @click="billSection(section)"
            >
              Bill This Section
            </VBtn>
          </VCardText>
        </VCard>
      </VCardText>
    </VCard>

    <!-- Confirmed lines: measurement + certification -->
    <VCard
      v-if="boq.lines.length"
      class="mb-4"
      title="BOQ Lines"
    >
      <VCardText>
        <VCard
          v-for="line in boq.lines"
          :key="line.id"
          variant="outlined"
          class="mb-2"
        >
          <VCardText>
            <p class="mb-2">
              <strong>{{ line.description }}</strong> - Qty {{ line.quantity }} @ KES {{ line.rate }}
            </p>

            <VChip
              v-for="sheet in line.measurement_sheets"
              :key="sheet.id"
              class="me-2 mb-2"
              :color="sheet.status === 'certified' ? 'success' : 'warning'"
            >
              {{ sheet.period }}: {{ sheet.current_qty }} ({{ sheet.status }})
            </VChip>

            <VRow align="center">
              <VCol
                cols="6"
                md="3"
              >
                <VTextField
                  v-model="measureForm(line.id).period"
                  label="Period"
                  placeholder="2026-09"
                />
              </VCol>
              <VCol
                cols="6"
                md="3"
              >
                <VTextField
                  v-model.number="measureForm(line.id).current_qty"
                  type="number"
                  label="Measured Qty"
                />
              </VCol>
              <VCol
                cols="6"
                md="3"
              >
                <VBtn
                  :loading="submitting"
                  @click="recordMeasurement(line)"
                >
                  Record Measurement
                </VBtn>
              </VCol>
              <VCol
                cols="6"
                md="3"
              >
                <VBtn
                  variant="tonal"
                  :disabled="!(line.measurement_sheets || []).some(s => s.status !== 'certified')"
                  :loading="submitting"
                  @click="certifyLatest(line)"
                >
                  Certify Latest
                </VBtn>
              </VCol>
            </VRow>
          </VCardText>
        </VCard>
      </VCardText>
    </VCard>

    <!-- Staging: rows awaiting mapping/confirmation -->
    <VCard title="Import BOQ Rows">
      <VCardText>
        <VRow class="mb-4">
          <VCol
            cols="12"
            md="5"
          >
            <VTextField
              v-model="newRow.description"
              label="Description"
            />
          </VCol>
          <VCol
            cols="12"
            md="3"
          >
            <VTextField
              v-model="newRow.unit"
              label="Unit"
            />
          </VCol>
          <VCol
            cols="12"
            md="4"
            class="d-flex align-center"
          >
            <VBtn
              :loading="submitting"
              @click="stageRow"
            >
              Stage Row
            </VBtn>
          </VCol>
        </VRow>

        <VCard
          v-for="row in stagingRows"
          :key="row.id"
          variant="outlined"
          class="mb-2"
        >
          <VCardText>
            <p class="mb-2">
              <strong>{{ row.raw_row_data.description }}</strong> ({{ row.raw_row_data.unit }})
            </p>
            <VRow>
              <VCol
                cols="12"
                md="3"
              >
                <VSelect
                  v-model="mapForm(row).item_id"
                  label="Item (optional)"
                  item-title="name"
                  item-value="id"
                  clearable
                  :items="items"
                />
              </VCol>
              <VCol
                cols="12"
                md="3"
              >
                <VTextField
                  v-model="mapForm(row).section"
                  label="Section"
                />
              </VCol>
              <VCol
                cols="6"
                md="2"
              >
                <VTextField
                  v-model.number="mapForm(row).quantity"
                  type="number"
                  label="Quantity"
                />
              </VCol>
              <VCol
                cols="6"
                md="2"
              >
                <VTextField
                  v-model.number="mapForm(row).rate"
                  type="number"
                  label="Rate"
                />
              </VCol>
              <VCol
                cols="12"
                md="2"
              >
                <VBtn
                  :loading="submitting"
                  @click="mapAndConfirm(row)"
                >
                  Confirm Line
                </VBtn>
              </VCol>
            </VRow>
          </VCardText>
        </VCard>
      </VCardText>
    </VCard>
  </div>
</template>
