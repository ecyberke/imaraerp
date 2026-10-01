<script setup>
/* eslint-disable camelcase -- these object keys are literal Laravel API request/response field names (snake_case is the real contract), not JS identifiers to rename. */
definePage({
  meta: { action: 'read', subject: 'Manufacturing' },
})

const route = useRoute('manufacturing-production-orders-id')
const orderId = computed(() => route.params.id)

const order = ref(null)
const bom = ref(null)
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
    const o = await $api(`/production-orders/${orderId.value}`)
    const boms = await $api('/bill-of-materials')

    order.value = o
    bom.value = boms.find(b => b.id === o.bom_id) ?? null
  } finally {
    loading.value = false
  }
}

async function reload() {
  order.value = await $api(`/production-orders/${orderId.value}`)
}

// --- Complete: optional actual quantity per BOM line ---
const actualQuantities = ref({})

function actualQtyModel(lineId) {
  return actualQuantities.value[lineId]
}

async function complete() {
  submitting.value = true
  try {
    const payload = {}
    for (const [lineId, qty] of Object.entries(actualQuantities.value)) {
      if (qty !== null && qty !== undefined && qty !== '')
        payload[lineId] = qty
    }

    await $api(`/production-orders/${orderId.value}/complete`, {
      method: 'POST',
      body: { actual_quantities: payload },
    })
    await reload()
    flash('Production order completed - RM consumed, FG produced, and posted to the ledger.')
  } catch (err) {
    flash(extractApiErrorMessage(err), 'error')
  } finally {
    submitting.value = false
  }
}

// --- Quality check ---
const qcForm = ref({ result: 'pass', disposition: 'accept' })

async function submitQualityCheck() {
  submitting.value = true
  try {
    await $api(`/production-orders/${orderId.value}/quality-checks`, { method: 'POST', body: qcForm.value })
    await reload()
    flash('Quality check recorded.')
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

  <div v-else-if="order">
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
      :title="`Production Order #${order.id}`"
    >
      <template #subtitle>
        Status: {{ order.status }} · QC: {{ order.qc_status }}
      </template>
      <VCardText>
        <p>Quantity to produce: {{ order.quantity }}</p>
        <template v-if="order.status === 'completed'">
          <p>RM consumed (actual): KES {{ order.rm_value_actual }}</p>
          <p>FG produced (at standard cost): KES {{ order.fg_value_standard }}</p>
          <p>Wastage variance: KES {{ order.wastage_variance }}</p>
          <VAlert
            v-if="order.bom_variance_exceeded"
            type="warning"
            class="mt-2"
          >
            Actual material consumption exceeded the BOM's wastage tolerance ({{ (order.bom_variance_pct * 100).toFixed(1) }}%). This would normally raise a notification once that module exists.
          </VAlert>
        </template>
      </VCardText>
    </VCard>

    <VCard
      v-if="order.status === 'draft' && bom"
      class="mb-4"
      title="Complete Production"
    >
      <VCardText>
        <p class="mb-4 text-medium-emphasis">
          Leave a line blank to use its planned quantity (BOM quantity x order quantity, plus the BOM's normal wastage allowance). Enter an actual quantity only if more or less material was really used.
        </p>

        <VRow
          v-for="line in bom.lines"
          :key="line.id"
          class="mb-2"
        >
          <VCol
            cols="12"
            md="6"
          >
            {{ line.raw_material_item?.name || `Item #${line.raw_material_item_id}` }} - planned {{ (line.quantity * order.quantity).toFixed(4) }}
          </VCol>
          <VCol
            cols="12"
            md="6"
          >
            <VTextField
              :model-value="actualQtyModel(line.id)"
              type="number"
              label="Actual Quantity Consumed (optional)"
              @update:model-value="val => actualQuantities[line.id] = val"
            />
          </VCol>
        </VRow>

        <VBtn
          :loading="submitting"
          @click="complete"
        >
          Complete Production Order
        </VBtn>
      </VCardText>
    </VCard>

    <VCard
      v-if="order.status === 'completed' && order.qc_status === 'pending'"
      title="Record Quality Check"
    >
      <VCardText>
        <VSelect
          v-model="qcForm.result"
          label="Result"
          class="mb-4"
          :items="[{ title: 'Pass', value: 'pass' }, { title: 'Fail', value: 'fail' }]"
        />
        <VSelect
          v-model="qcForm.disposition"
          label="Disposition"
          class="mb-4"
          :items="[
            { title: 'Accept', value: 'accept' },
            { title: 'Rework', value: 'rework' },
            { title: 'Scrap', value: 'scrap' },
          ]"
        />
        <VBtn
          :loading="submitting"
          @click="submitQualityCheck"
        >
          Record Quality Check
        </VBtn>
      </VCardText>
    </VCard>
  </div>
</template>
