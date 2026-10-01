<script setup>
/* eslint-disable camelcase -- these object keys are literal Laravel API request/response field names (snake_case is the real contract), not JS identifiers to rename. */
definePage({
  meta: { action: 'read', subject: 'SalesBilling' },
})

const router = useRouter()

const boqs = ref([])
const subcontracts = ref([])
const loading = ref(true)
const dialog = ref(false)
const saving = ref(false)
const error = ref('')

const form = ref({ boqable_type: 'subcontract', boqable_id: null, uses_sections: true, source: 'manual' })

async function loadAll() {
  loading.value = true
  try {
    const [b, s] = await Promise.all([$api('/boqs'), $api('/subcontracts').catch(() => [])])

    boqs.value = b
    subcontracts.value = s
  } finally {
    loading.value = false
  }
}

function openCreate() {
  error.value = ''
  form.value = { boqable_type: 'subcontract', boqable_id: null, uses_sections: true, source: 'manual' }
  dialog.value = true
}

async function save() {
  error.value = ''
  saving.value = true
  try {
    const created = await $api('/boqs', { method: 'POST', body: form.value })

    dialog.value = false
    router.push(`/sales-billing/boq/${created.id}`)
  } catch (err) {
    error.value = extractApiErrorMessage(err)
  } finally {
    saving.value = false
  }
}

onMounted(loadAll)
</script>

<template>
  <VCard title="Bills of Quantities">
    <template #append>
      <VBtn @click="openCreate">
        New BOQ
      </VBtn>
    </template>

    <VDataTable
      :headers="[
        { title: 'BOQ #', key: 'id' },
        { title: 'Attached To', key: 'boqable_type' },
        { title: 'Status', key: 'status' },
        { title: 'Source', key: 'source' },
      ]"
      :items="boqs"
      :loading="loading"
      item-value="id"
      @click:row="(_, { item }) => router.push(`/sales-billing/boq/${item.id}`)"
    />

    <VDialog
      v-model="dialog"
      max-width="450"
    >
      <VCard title="New BOQ">
        <VCardText>
          <VAlert
            v-if="error"
            type="error"
            class="mb-4"
          >
            {{ error }}
          </VAlert>

          <p class="mb-4 text-medium-emphasis">
            Attaching a BOQ to a Project isn't available yet - Project doesn't exist as a module in this build yet. Attach it to a Subcontract for now.
          </p>

          <VSelect
            v-model="form.boqable_id"
            label="Subcontract"
            item-title="id"
            item-value="id"
            class="mb-4"
            :items="subcontracts"
          />

          <VSwitch
            v-model="form.uses_sections"
            label="Organize into sections"
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
