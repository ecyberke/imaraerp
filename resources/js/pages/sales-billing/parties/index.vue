<script setup>
/* eslint-disable camelcase -- these object keys are literal Laravel API request/response field names (snake_case is the real contract), not JS identifiers to rename. */
definePage({
  meta: { action: 'read', subject: 'SalesBilling' },
})

const parties = ref([])
const loading = ref(true)
const dialog = ref(false)
const saving = ref(false)
const formError = ref('')

const form = ref({
  name: '',
  type: 'customer',
  credit_limit: null,
  payment_terms: null,
})

const headers = [
  { title: 'Name', key: 'name' },
  { title: 'Type', key: 'type' },
  { title: 'Credit Limit (KES)', key: 'credit_limit' },
  { title: 'Status', key: 'is_active' },
]

async function loadParties() {
  loading.value = true
  try {
    parties.value = await $api('/parties')
  } finally {
    loading.value = false
  }
}

function openCreate() {
  formError.value = ''
  form.value = { name: '', type: 'customer', credit_limit: null, payment_terms: null }
  dialog.value = true
}

async function save() {
  formError.value = ''
  saving.value = true
  try {
    await $api('/parties', { method: 'POST', body: form.value })
    dialog.value = false
    await loadParties()
  } catch (err) {
    formError.value = extractApiErrorMessage(err)
  } finally {
    saving.value = false
  }
}

onMounted(loadParties)
</script>

<template>
  <VCard title="Customers & Suppliers">
    <template #append>
      <VBtn @click="openCreate">
        New Party
      </VBtn>
    </template>

    <VDataTable
      :headers="headers"
      :items="parties"
      :loading="loading"
      item-value="id"
    >
      <template #item.is_active="{ item }">
        <VChip :color="item.is_active ? 'success' : 'secondary'">
          {{ item.is_active ? 'Active' : 'Inactive' }}
        </VChip>
      </template>
    </VDataTable>

    <VDialog
      v-model="dialog"
      max-width="500"
    >
      <VCard title="New Party">
        <VCardText>
          <VAlert
            v-if="formError"
            type="error"
            class="mb-4"
          >
            {{ formError }}
          </VAlert>

          <VTextField
            v-model="form.name"
            label="Name"
            class="mb-4"
          />

          <VSelect
            v-model="form.type"
            label="Type"
            class="mb-4"
            :items="[
              { title: 'Customer', value: 'customer' },
              { title: 'Supplier', value: 'supplier' },
              { title: 'Contractor', value: 'contractor' },
              { title: 'Both', value: 'both' },
            ]"
          />

          <VTextField
            v-model.number="form.credit_limit"
            label="Credit Limit (KES)"
            type="number"
            class="mb-4"
          />

          <VSelect
            v-model="form.payment_terms"
            label="Payment Terms"
            clearable
            :items="[
              { title: 'Credit', value: 'credit' },
              { title: 'Cash', value: 'cash' },
            ]"
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
