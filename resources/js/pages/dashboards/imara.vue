<script setup>
definePage({
  meta: { action: 'read', subject: 'Dashboard' },
})

const master = ref(null)
const loading = ref(true)

async function load() {
  loading.value = true
  try {
    master.value = await $api('/dashboards/master')
  } finally {
    loading.value = false
  }
}

const checklist = [
  { label: 'Chart of Accounts confirmed', done: true, hint: 'Seeded automatically for every new tenant.' },
  { label: 'Warehouses set up', done: true, hint: '"Main Warehouse" and "In Transit" are seeded automatically.', to: null },
  { label: 'Users invited', done: false, hint: 'Invite your Finance and Procurement teammates.', to: null },
  { label: 'Opening balances migrated (if migrating from another system)', done: false, hint: 'Not required for a brand-new tenant.', to: null },
]

onMounted(load)
</script>

<template>
  <VRow>
    <VCol cols="12">
      <VCard title="Getting Started">
        <VCardText>
          <VList>
            <VListItem
              v-for="step in checklist"
              :key="step.label"
            >
              <template #prepend>
                <VIcon
                  :icon="step.done ? 'ri-checkbox-circle-fill' : 'ri-checkbox-blank-circle-line'"
                  :color="step.done ? 'success' : 'secondary'"
                />
              </template>
              <VListItemTitle>{{ step.label }}</VListItemTitle>
              <VListItemSubtitle>{{ step.hint }}</VListItemSubtitle>
            </VListItem>
          </VList>
        </VCardText>
      </VCard>
    </VCol>

    <VCol
      cols="12"
      md="4"
    >
      <VCard title="Cash Position">
        <VCardText v-if="master">
          <p class="text-h4">
            KES {{ master.cash_position.total }}
          </p>
          <p class="text-medium-emphasis">
            {{ master.cash_position.bank_accounts.length }} active bank account(s)
          </p>
        </VCardText>
      </VCard>
    </VCol>

    <VCol
      cols="12"
      md="4"
    >
      <VCard title="Overdue Receivables">
        <VCardText v-if="master">
          <p class="text-h4">
            KES {{ master.overdue_receivables }}
          </p>
        </VCardText>
      </VCard>
    </VCol>

    <VCol
      cols="12"
      md="4"
    >
      <VCard title="Pending Approvals">
        <VCardText v-if="master">
          <p>Purchase Requisitions: {{ master.pending_approvals.purchase_requisitions }}</p>
          <p>Credit Approvals: {{ master.pending_approvals.credit_approvals }}</p>
          <p>Low Stock Alerts: {{ master.low_stock_alerts }}</p>
        </VCardText>
      </VCard>
    </VCol>

    <VCol cols="12">
      <VCard title="Quick Links">
        <VCardText class="d-flex flex-wrap gap-4">
          <VBtn to="/sales-billing/leads">
            Leads
          </VBtn>
          <VBtn to="/sales-billing/quotations">
            Quotations
          </VBtn>
          <VBtn to="/sales-billing/boq">
            BOQs
          </VBtn>
          <VBtn to="/sales-billing/catalog">
            Catalog
          </VBtn>
          <VBtn to="/account/mfa-setup">
            Enable MFA
          </VBtn>
        </VCardText>
      </VCard>
    </VCol>
  </VRow>
</template>
