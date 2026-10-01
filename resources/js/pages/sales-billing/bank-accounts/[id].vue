<script setup>
/* eslint-disable camelcase -- these object keys are literal Laravel API request/response field names (snake_case is the real contract), not JS identifiers to rename. */
definePage({
  meta: { action: 'read', subject: 'SalesBilling' },
})

const route = useRoute('sales-billing-bank-accounts-id')
const bankAccountId = computed(() => route.params.id)

const bankAccount = ref(null)
const summary = ref(null)
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
    const [accounts, s] = await Promise.all([
      $api('/bank-accounts'),
      $api(`/bank-accounts/${bankAccountId.value}/reconciliation-summary`),
    ])

    bankAccount.value = accounts.find(a => String(a.id) === String(bankAccountId.value))
    summary.value = s
  } finally {
    loading.value = false
  }
}

async function reloadSummary() {
  summary.value = await $api(`/bank-accounts/${bankAccountId.value}/reconciliation-summary`)
}

async function reconcile(payment) {
  submitting.value = true
  try {
    await $api(`/payments/${payment.id}/reconcile`, { method: 'POST' })
    await reloadSummary()
    flash('Payment marked as reconciled.')
  } catch (err) {
    flash(extractApiErrorMessage(err), 'error')
  } finally {
    submitting.value = false
  }
}

onMounted(loadAll)
</script>

<template>
  <div v-if="loading">
    <VProgressCircular indeterminate />
  </div>

  <div v-else-if="bankAccount">
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
      :title="bankAccount.bank_name"
    >
      <template #subtitle>
        Account {{ bankAccount.account_number }}
      </template>
      <template #append>
        <VChip :color="bankAccount.status === 'active' ? 'success' : 'secondary'">
          {{ bankAccount.status }}
        </VChip>
      </template>
      <VCardText v-if="summary">
        <VRow>
          <VCol
            cols="12"
            md="6"
          >
            <p class="text-caption text-medium-emphasis mb-1">
              GL Balance (Cash/Bank)
            </p>
            <p class="text-h6">
              {{ summary.gl_balance }} KES
            </p>
          </VCol>
          <VCol
            cols="12"
            md="6"
          >
            <p class="text-caption text-medium-emphasis mb-1">
              Unreconciled Total
            </p>
            <p class="text-h6">
              {{ summary.unreconciled_total }} KES
            </p>
          </VCol>
        </VRow>
      </VCardText>
    </VCard>

    <VCard title="Unreconciled Payments">
      <VCardText>
        <VCard
          v-for="payment in summary?.unreconciled_payments"
          :key="payment.id"
          variant="outlined"
          class="mb-2"
        >
          <VCardText class="d-flex align-center">
            <div>
              <strong>{{ payment.direction === 'receipt' ? '+' : '-' }}{{ payment.amount }} KES</strong>
              <span class="ms-2 text-medium-emphasis">{{ payment.posting_date?.slice(0, 10) }} · {{ payment.method }}</span>
            </div>
            <VSpacer />
            <VBtn
              size="small"
              :loading="submitting"
              @click="reconcile(payment)"
            >
              Mark Reconciled
            </VBtn>
          </VCardText>
        </VCard>
        <p
          v-if="!summary?.unreconciled_payments?.length"
          class="text-medium-emphasis"
        >
          No unreconciled payments against this account.
        </p>
      </VCardText>
    </VCard>
  </div>
</template>
