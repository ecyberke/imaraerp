<script setup>
/* eslint-disable camelcase -- these object keys are literal Laravel API request/response field names (snake_case is the real contract), not JS identifiers to rename. */
definePage({
  meta: { action: 'read', subject: 'SalesBilling' },
})

const STATUS_COLOR = { pending: 'warning', completed: 'success', failed: 'error', cancelled: 'secondary' }

const parties = ref([])
const invoices = ref([])
const history = ref([])
const loading = ref(true)
const submitting = ref(false)
const notice = ref('')
const noticeType = ref('success')
const activeRequest = ref(null)
let pollHandle = null

const form = ref({ party_id: null, invoice_id: null, amount: null, phone_number: '' })

const unpaidInvoicesForParty = computed(() => invoices.value.filter(i => i.party_id === form.value.party_id && i.status !== 'paid'))

function flash(message, type = 'success') {
  notice.value = message
  noticeType.value = type
  setTimeout(() => { notice.value = '' }, 6000)
}

async function loadAll() {
  loading.value = true
  try {
    const [p, h] = await Promise.all([$api('/parties'), $api('/mpesa/stk-requests')])

    parties.value = p
    history.value = h
  } finally {
    loading.value = false
  }
}

async function onPartyChange() {
  form.value.invoice_id = null
  if (!form.value.party_id)
    return
  invoices.value = await $api('/invoices', { query: { party_id: form.value.party_id } })
}

function onInvoiceChange() {
  const invoice = invoices.value.find(i => i.id === form.value.invoice_id)

  form.value.amount = invoice ? Number(invoice.net_payable) : null
}

async function initiate() {
  submitting.value = true
  try {
    activeRequest.value = await $api('/mpesa/stk-requests', { method: 'POST', body: form.value })
    flash('STK Push sent - ask the customer to check their phone and enter their M-Pesa PIN.')
    pollStatus()
  } catch (err) {
    flash(extractApiErrorMessage(err), 'error')
  } finally {
    submitting.value = false
  }
}

function pollStatus() {
  clearInterval(pollHandle)
  pollHandle = setInterval(async () => {
    const updated = await $api(`/mpesa/stk-requests/${activeRequest.value.id}`)

    activeRequest.value = updated
    if (updated.status !== 'pending') {
      clearInterval(pollHandle)
      await loadAll()
      if (updated.status === 'completed')
        flash(`Payment received - M-Pesa receipt ${updated.mpesa_receipt_number}.`)
      else
        flash(updated.result_desc || 'The STK Push was not completed.', 'error')
    }
  }, 3000)
}

onMounted(loadAll)
onUnmounted(() => clearInterval(pollHandle))
</script>

<template>
  <div>
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
      title="Collect Payment via M-Pesa"
    >
      <VCardText>
        <VRow>
          <VCol
            cols="12"
            md="6"
          >
            <VSelect
              v-model="form.party_id"
              label="Customer"
              item-title="name"
              item-value="id"
              class="mb-4"
              :items="parties"
              @update:model-value="onPartyChange"
            />
            <VSelect
              v-model="form.invoice_id"
              label="Invoice (optional - leave blank for an advance payment)"
              item-title="document_number"
              item-value="id"
              class="mb-4"
              clearable
              :disabled="!form.party_id"
              :items="unpaidInvoicesForParty"
              @update:model-value="onInvoiceChange"
            />
          </VCol>
          <VCol
            cols="12"
            md="6"
          >
            <VTextField
              v-model.number="form.amount"
              label="Amount (KES)"
              type="number"
              class="mb-4"
            />
            <VTextField
              v-model="form.phone_number"
              label="Phone Number (07XX XXX XXX)"
              class="mb-4"
            />
            <VBtn
              block
              :loading="submitting"
              :disabled="!form.party_id || !form.amount || !form.phone_number"
              @click="initiate"
            >
              Send STK Push
            </VBtn>
          </VCol>
        </VRow>

        <VAlert
          v-if="activeRequest"
          :type="STATUS_COLOR[activeRequest.status] === 'error' ? 'error' : 'info'"
          class="mt-4"
        >
          Status: {{ activeRequest.status }}
          <template v-if="activeRequest.result_desc">
            - {{ activeRequest.result_desc }}
          </template>
        </VAlert>
      </VCardText>
    </VCard>

    <VCard title="Recent M-Pesa Collection Attempts">
      <VDataTable
        :headers="[
          { title: 'Customer', key: 'party_name' },
          { title: 'Amount (KES)', key: 'amount' },
          { title: 'Phone', key: 'phone_number' },
          { title: 'Status', key: 'status' },
          { title: 'Receipt', key: 'mpesa_receipt_number' },
        ]"
        :items="history.map(h => ({ ...h, party_name: h.party?.name }))"
        :loading="loading"
        item-value="id"
      >
        <template #item.status="{ item }">
          <VChip :color="STATUS_COLOR[item.status] ?? 'secondary'">
            {{ item.status }}
          </VChip>
        </template>
      </VDataTable>
    </VCard>
  </div>
</template>
