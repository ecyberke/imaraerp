<script setup>
/* eslint-disable camelcase -- these object keys are literal Laravel API request/response field names (snake_case is the real contract), not JS identifiers to rename. */
definePage({
  meta: { action: 'read', subject: 'SalesBilling' },
})

const router = useRouter()

const orders = ref([])
const parties = ref([])
const loading = ref(true)

const headers = [
  { title: 'Document #', key: 'document_number' },
  { title: 'Party', key: 'party_name' },
  { title: 'Supply Path', key: 'supply_path' },
  { title: 'Status', key: 'status' },
  { title: 'Total (KES)', key: 'total' },
]

const partyName = id => parties.value.find(p => p.id === id)?.name || '—'
const rows = computed(() => orders.value.map(o => ({ ...o, party_name: partyName(o.party_id) })))

const statusColor = status => ({
  draft: 'secondary',
  feasibility_check: 'info',
  rejected: 'error',
  renegotiating: 'warning',
  approved: 'primary',
  delivered: 'success',
  billed: 'success',
  closed: 'success',
}[status] || 'secondary')

async function loadAll() {
  loading.value = true
  try {
    const [ordersRes, partiesRes] = await Promise.all([$api('/sales-orders'), $api('/parties')])

    orders.value = ordersRes
    parties.value = partiesRes
  } finally {
    loading.value = false
  }
}

function openOrder(item) {
  router.push(`/sales-billing/quotations/${item.id}`)
}

onMounted(loadAll)
</script>

<template>
  <VCard title="Quotations & Sales Orders">
    <template #append>
      <VBtn to="/sales-billing/quotations/create">
        New Quotation
      </VBtn>
    </template>

    <VDataTable
      :headers="headers"
      :items="rows"
      :loading="loading"
      item-value="id"
      @click:row="(_, { item }) => openOrder(item)"
    >
      <template #item.status="{ item }">
        <VChip :color="statusColor(item.status)">
          {{ item.status.replaceAll('_', ' ') }}
        </VChip>
      </template>
    </VDataTable>
  </VCard>
</template>
