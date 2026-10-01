<script setup>
/* eslint-disable camelcase -- these object keys are literal Laravel API request/response field names (snake_case is the real contract), not JS identifiers to rename. */
definePage({
  meta: { action: 'read', subject: 'SalesBilling' },
})

const DOCUMENT_TYPE_LABELS = {
  insurance: 'Insurance',
  lien_waiver: 'Lien Waiver',
  safety_cert: 'Safety Certificate',
  tax_compliance: 'Tax Compliance',
}

const STATUS_COLOR = {
  valid: 'success',
  expiring_soon: 'warning',
  expired: 'error',
}

const route = useRoute('sales-billing-parties-id')
const partyId = computed(() => route.params.id)

const party = ref(null)
const documents = ref([])
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
    const [p, d] = await Promise.all([
      $api(`/parties/${partyId.value}`),
      $api(`/parties/${partyId.value}/compliance-documents`),
    ])

    party.value = p
    documents.value = d
  } finally {
    loading.value = false
  }
}

async function reloadDocuments() {
  documents.value = await $api(`/parties/${partyId.value}/compliance-documents`)
}

const dialog = ref(false)
const form = ref({ document_type: null, issue_date: '', expiry_date: '' })
const formError = ref('')

function openCreate() {
  formError.value = ''
  form.value = { document_type: null, issue_date: '', expiry_date: '' }
  dialog.value = true
}

async function save() {
  formError.value = ''
  submitting.value = true
  try {
    await $api(`/parties/${partyId.value}/compliance-documents`, { method: 'POST', body: form.value })
    dialog.value = false
    await reloadDocuments()
    flash('Compliance document added.')
  } catch (err) {
    formError.value = extractApiErrorMessage(err)
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

  <div v-else-if="party">
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
      :title="party.name"
    >
      <template #subtitle>
        {{ party.type }} · Credit Limit {{ party.credit_limit }} KES · {{ party.payment_terms ?? 'no terms set' }}
      </template>
      <template #append>
        <VChip :color="party.is_active ? 'success' : 'secondary'">
          {{ party.is_active ? 'Active' : 'Inactive' }}
        </VChip>
      </template>
    </VCard>

    <VCard title="Compliance Documents">
      <template #append>
        <VBtn
          size="small"
          @click="openCreate"
        >
          Add Document
        </VBtn>
      </template>
      <VCardText>
        <VCard
          v-for="document in documents"
          :key="document.id"
          variant="outlined"
          class="mb-2"
        >
          <VCardText class="d-flex align-center">
            <div>
              <strong>{{ DOCUMENT_TYPE_LABELS[document.document_type] ?? document.document_type }}</strong>
              <span class="ms-2 text-medium-emphasis">
                issued {{ document.issue_date?.slice(0, 10) }}
                <template v-if="document.expiry_date">
                  · expires {{ document.expiry_date.slice(0, 10) }}
                </template>
                <template v-else>
                  · no expiry
                </template>
              </span>
            </div>
            <VSpacer />
            <VChip :color="STATUS_COLOR[document.status] ?? 'secondary'">
              {{ document.status }}
            </VChip>
          </VCardText>
        </VCard>
        <p
          v-if="!documents.length"
          class="text-medium-emphasis"
        >
          No compliance documents on file.
        </p>
      </VCardText>
    </VCard>

    <VDialog
      v-model="dialog"
      max-width="450"
    >
      <VCard title="Add Compliance Document">
        <VCardText>
          <VAlert
            v-if="formError"
            type="error"
            class="mb-4"
          >
            {{ formError }}
          </VAlert>

          <VSelect
            v-model="form.document_type"
            label="Document Type"
            class="mb-4"
            :items="Object.entries(DOCUMENT_TYPE_LABELS).map(([value, title]) => ({ title, value }))"
          />
          <VTextField
            v-model="form.issue_date"
            type="date"
            label="Issue Date"
            class="mb-4"
          />
          <VTextField
            v-model="form.expiry_date"
            type="date"
            label="Expiry Date (optional)"
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
            :loading="submitting"
            :disabled="!form.document_type || !form.issue_date"
            @click="save"
          >
            Save
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>
