<script setup>
/* eslint-disable camelcase -- these object keys are literal Laravel API request/response field names (snake_case is the real contract), not JS identifiers to rename. */
definePage({
  meta: { action: 'read', subject: 'SalesBilling' },
})

const accounts = ref([])
const glAccounts = ref([])
const currencies = ref([])
const loading = ref(true)
const dialog = ref(false)
const saving = ref(false)
const error = ref('')

const form = ref({ bank_name: '', account_number: '', currency_id: null, gl_account_id: null })

async function loadAll() {
  loading.value = true
  try {
    const [a, currenciesRes, coa] = await Promise.all([$api('/bank-accounts'), $api('/currencies'), $api('/chart-of-accounts')])

    accounts.value = a
    currencies.value = currenciesRes
    glAccounts.value = coa.filter(acc => acc.name === 'Cash/Bank')
  } finally {
    loading.value = false
  }
}

function openCreate() {
  error.value = ''
  form.value = {
    bank_name: '',
    account_number: '',
    currency_id: currencies.value.find(c => c.is_base)?.id ?? null,
    gl_account_id: glAccounts.value[0]?.id ?? null,
  }
  dialog.value = true
}

async function save() {
  error.value = ''
  saving.value = true
  try {
    await $api('/bank-accounts', { method: 'POST', body: form.value })
    dialog.value = false
    await loadAll()
  } catch (err) {
    error.value = extractApiErrorMessage(err)
  } finally {
    saving.value = false
  }
}

onMounted(loadAll)
</script>

<template>
  <VCard title="Bank Accounts">
    <template #append>
      <VBtn @click="openCreate">
        New Bank Account
      </VBtn>
    </template>

    <VDataTable
      :headers="[
        { title: 'Bank', key: 'bank_name' },
        { title: 'Account Number', key: 'account_number' },
        { title: 'Status', key: 'status' },
      ]"
      :items="accounts"
      :loading="loading"
      item-value="id"
    />

    <VDialog
      v-model="dialog"
      max-width="450"
    >
      <VCard title="New Bank Account">
        <VCardText>
          <VAlert
            v-if="error"
            type="error"
            class="mb-4"
          >
            {{ error }}
          </VAlert>

          <VTextField
            v-model="form.bank_name"
            label="Bank Name"
            class="mb-4"
          />
          <VTextField
            v-model="form.account_number"
            label="Account Number"
            class="mb-4"
          />
          <VSelect
            v-model="form.currency_id"
            label="Currency"
            item-title="code"
            item-value="id"
            class="mb-4"
            :items="currencies"
          />
          <p class="text-caption text-medium-emphasis">
            This will post to the tenant's single "Cash/Bank" ledger account - splitting Cash/Bank by GL account per bank isn't supported yet.
          </p>
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
