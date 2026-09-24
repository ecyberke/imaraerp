<script setup>
/* eslint-disable camelcase -- these object keys are literal Laravel API request/response field names (snake_case is the real contract), not JS identifiers to rename. */
definePage({
  meta: { action: 'read', subject: 'SalesBilling' },
})

const router = useRouter()
const route = useRoute()

const parties = ref([])
const items = ref([])
const currencies = ref([])
const saving = ref(false)
const error = ref('')

const form = ref({
  lead_id: route.query.lead_id ? Number(route.query.lead_id) : null,
  party_id: route.query.party_id ? Number(route.query.party_id) : null,
  currency_id: null,
  supply_path: 'direct_sale',
  invoice_policy: 'on_delivery',
  lines: [{ item_id: null, description: '', quantity: 1, rate: 0 }],
})

const supplyPaths = [
  { title: 'Direct Sale', value: 'direct_sale' },
  { title: 'Manufacture for Sale', value: 'manufacture_for_sale' },
  { title: 'Project', value: 'project' },
  { title: 'Manufacture for Project', value: 'manufacture_for_project' },
]

const invoicePolicies = [
  { title: 'On Order Approval', value: 'on_order' },
  { title: 'On Delivery', value: 'on_delivery' },
  { title: 'On Milestone', value: 'on_milestone' },
]

function addLine() {
  form.value.lines.push({ item_id: null, description: '', quantity: 1, rate: 0 })
}

function removeLine(index) {
  form.value.lines.splice(index, 1)
}

function onItemPicked(line) {
  if (!line.item_id)
    return
  const item = items.value.find(i => i.id === line.item_id)
  if (item && !line.description)
    line.description = item.name
}

async function loadOptions() {
  const [partiesRes, itemsRes, currenciesRes] = await Promise.all([$api('/parties'), $api('/items'), $api('/currencies')])

  parties.value = partiesRes.filter(p => ['customer', 'both'].includes(p.type))
  items.value = itemsRes
  currencies.value = currenciesRes
  form.value.currency_id = currenciesRes.find(c => c.is_base)?.id ?? currenciesRes[0]?.id ?? null
}

async function submit() {
  error.value = ''
  saving.value = true
  try {
    const created = await $api('/sales-orders', { method: 'POST', body: form.value })

    router.push(`/sales-billing/quotations/${created.id}`)
  } catch (err) {
    error.value = extractApiErrorMessage(err)
  } finally {
    saving.value = false
  }
}

onMounted(loadOptions)
</script>

<template>
  <VCard title="New Quotation">
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
          <VSelect
            v-model="form.party_id"
            label="Client"
            item-title="name"
            item-value="id"
            :items="parties"
          />
        </VCol>
        <VCol
          cols="12"
          md="6"
        >
          <VSelect
            v-model="form.currency_id"
            label="Currency"
            item-title="code"
            item-value="id"
            :items="currencies"
          />
        </VCol>
        <VCol
          cols="12"
          md="6"
        >
          <VSelect
            v-model="form.supply_path"
            label="Supply Path"
            :items="supplyPaths"
          />
        </VCol>
        <VCol
          cols="12"
          md="6"
        >
          <VSelect
            v-model="form.invoice_policy"
            label="Invoice Trigger"
            :items="invoicePolicies"
          />
        </VCol>
      </VRow>

      <VDivider class="my-4" />

      <h6 class="text-h6 mb-4">
        Line Items
      </h6>

      <VRow
        v-for="(line, index) in form.lines"
        :key="index"
        class="mb-2"
      >
        <VCol
          cols="12"
          md="3"
        >
          <VSelect
            v-model="line.item_id"
            label="Item (optional)"
            item-title="name"
            item-value="id"
            clearable
            :items="items"
            @update:model-value="onItemPicked(line)"
          />
        </VCol>
        <VCol
          cols="12"
          md="3"
        >
          <VTextField
            v-model="line.description"
            label="Description"
          />
        </VCol>
        <VCol
          cols="6"
          md="2"
        >
          <VTextField
            v-model.number="line.quantity"
            type="number"
            label="Quantity"
          />
        </VCol>
        <VCol
          cols="6"
          md="2"
        >
          <VTextField
            v-model.number="line.rate"
            type="number"
            label="Rate (KES)"
          />
        </VCol>
        <VCol
          cols="12"
          md="2"
          class="d-flex align-center"
        >
          <VBtn
            icon="ri-delete-bin-line"
            variant="text"
            :disabled="form.lines.length === 1"
            @click="removeLine(index)"
          />
        </VCol>
      </VRow>

      <VBtn
        variant="tonal"
        class="mb-6"
        @click="addLine"
      >
        Add Line
      </VBtn>

      <div>
        <VBtn
          :loading="saving"
          @click="submit"
        >
          Save Quotation
        </VBtn>
      </div>
    </VCardText>
  </VCard>
</template>
