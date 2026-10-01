<script setup>
/* eslint-disable camelcase -- these object keys are literal Laravel API request/response field names (snake_case is the real contract), not JS identifiers to rename. */
definePage({
  meta: { action: 'read', subject: 'SalesBilling' },
})

const router = useRouter()

const leads = ref([])
const parties = ref([])
const loading = ref(true)
const dialog = ref(false)
const saving = ref(false)
const formError = ref('')

const form = ref({ party_id: null, source: '' })

const headers = [
  { title: 'Party', key: 'party_name' },
  { title: 'Source', key: 'source' },
  { title: 'Status', key: 'status' },
  { title: '', key: 'actions', sortable: false },
]

const partyName = id => parties.value.find(p => p.id === id)?.name || '—'

const rows = computed(() => leads.value.map(l => ({ ...l, party_name: partyName(l.party_id) })))

async function loadAll() {
  loading.value = true
  try {
    const [leadsRes, partiesRes] = await Promise.all([$api('/leads'), $api('/parties')])

    leads.value = leadsRes
    parties.value = partiesRes.filter(p => ['customer', 'both'].includes(p.type))
  } finally {
    loading.value = false
  }
}

function openCreate() {
  formError.value = ''
  form.value = { party_id: null, source: '' }
  dialog.value = true
}

async function save() {
  formError.value = ''
  saving.value = true
  try {
    await $api('/leads', { method: 'POST', body: form.value })
    dialog.value = false
    await loadAll()
  } catch (err) {
    formError.value = extractApiErrorMessage(err)
  } finally {
    saving.value = false
  }
}

function convertToQuotation(lead) {
  router.push({ path: '/sales-billing/quotations/create', query: { lead_id: lead.id, party_id: lead.party_id } })
}

onMounted(loadAll)
</script>

<template>
  <VCard title="Leads">
    <template #append>
      <VBtn @click="openCreate">
        New Lead
      </VBtn>
    </template>

    <VDataTable
      :headers="headers"
      :items="rows"
      :loading="loading"
      item-value="id"
    >
      <template #item.status="{ item }">
        <VChip :color="item.status === 'open' ? 'primary' : 'secondary'">
          {{ item.status }}
        </VChip>
      </template>
      <template #item.actions="{ item }">
        <VBtn
          size="small"
          variant="text"
          @click="convertToQuotation(item)"
        >
          Create Quotation
        </VBtn>
      </template>
    </VDataTable>

    <VDialog
      v-model="dialog"
      max-width="500"
    >
      <VCard title="New Lead">
        <VCardText>
          <VAlert
            v-if="formError"
            type="error"
            class="mb-4"
          >
            {{ formError }}
          </VAlert>

          <VSelect
            v-model="form.party_id"
            label="Party"
            class="mb-4"
            item-title="name"
            item-value="id"
            :items="parties"
            clearable
          />

          <VTextField
            v-model="form.source"
            label="Source"
            placeholder="e.g. Referral, Site visit, Website"
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
