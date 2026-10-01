<script setup>
/* eslint-disable camelcase -- these object keys are literal Laravel API request/response field names (snake_case is the real contract), not JS identifiers to rename. */
definePage({
  meta: { action: 'read', subject: 'Manufacturing' },
})

const router = useRouter()

const orders = ref([])
const boms = ref([])
const warehouses = ref([])
const loading = ref(true)
const dialog = ref(false)
const saving = ref(false)
const error = ref('')

const form = ref({ bom_id: null, warehouse_id: null, quantity: 1 })

const bomLabel = bom => bom.finished_good_item?.name ? `${bom.finished_good_item.name} (BOM #${bom.id})` : `BOM #${bom.id}`

async function loadAll() {
  loading.value = true
  try {
    const [o, b, w] = await Promise.all([$api('/production-orders'), $api('/bill-of-materials'), $api('/warehouses')])

    orders.value = o
    boms.value = b
    warehouses.value = w
  } finally {
    loading.value = false
  }
}

function openCreate() {
  error.value = ''
  form.value = { bom_id: null, warehouse_id: warehouses.value.find(w => w.is_default)?.id ?? null, quantity: 1 }
  dialog.value = true
}

async function save() {
  error.value = ''
  saving.value = true
  try {
    const created = await $api('/production-orders', { method: 'POST', body: form.value })

    dialog.value = false
    router.push(`/manufacturing/production-orders/${created.id}`)
  } catch (err) {
    error.value = extractApiErrorMessage(err)
  } finally {
    saving.value = false
  }
}

const statusColor = status => ({ draft: 'secondary', completed: 'success', cancelled: 'error' }[status] || 'secondary')

onMounted(loadAll)
</script>

<template>
  <VCard title="Production Orders">
    <template #append>
      <VBtn @click="openCreate">
        New Production Order
      </VBtn>
    </template>

    <VDataTable
      :headers="[
        { title: 'Order #', key: 'id' },
        { title: 'Quantity', key: 'quantity' },
        { title: 'Status', key: 'status' },
        { title: 'QC Status', key: 'qc_status' },
      ]"
      :items="orders"
      :loading="loading"
      item-value="id"
      @click:row="(_, { item }) => router.push(`/manufacturing/production-orders/${item.id}`)"
    >
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
      <VCard title="New Production Order">
        <VCardText>
          <VAlert
            v-if="error"
            type="error"
            class="mb-4"
          >
            {{ error }}
          </VAlert>

          <VSelect
            v-model="form.bom_id"
            label="Bill of Materials"
            class="mb-4"
            :item-title="bomLabel"
            item-value="id"
            :items="boms"
          />
          <VSelect
            v-model="form.warehouse_id"
            label="Warehouse (FG output lands here)"
            class="mb-4"
            item-title="name"
            item-value="id"
            :items="warehouses"
          />
          <VTextField
            v-model.number="form.quantity"
            type="number"
            label="Quantity to Produce"
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
