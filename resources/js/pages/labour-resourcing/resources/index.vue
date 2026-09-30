<script setup>
/* eslint-disable camelcase -- these object keys are literal Laravel API request/response field names (snake_case is the real contract), not JS identifiers to rename. */
definePage({
  meta: { action: 'read', subject: 'Resourcing' },
})

const router = useRouter()

const resources = ref([])
const parties = ref([])
const loading = ref(true)
const dialog = ref(false)
const saving = ref(false)
const error = ref('')

const form = ref({ type: 'internal', party_id: null, skill_category: '' })

async function loadAll() {
  loading.value = true
  try {
    const [r, p] = await Promise.all([$api('/resources'), $api('/parties')])

    resources.value = r
    parties.value = p
  } finally {
    loading.value = false
  }
}

function openCreate() {
  error.value = ''
  form.value = { type: 'internal', party_id: null, skill_category: '' }
  dialog.value = true
}

async function save() {
  error.value = ''
  saving.value = true
  try {
    await $api('/resources', { method: 'POST', body: form.value })
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
  <VCard title="Resources">
    <template #append>
      <VBtn @click="openCreate">
        New Resource
      </VBtn>
    </template>

    <VDataTable
      :headers="[
        { title: 'Type', key: 'type' },
        { title: 'Skill Category', key: 'skill_category' },
      ]"
      :items="resources"
      :loading="loading"
      item-value="id"
      @click:row="(_, { item }) => router.push(`/labour-resourcing/resources/${item.id}`)"
    />

    <VDialog
      v-model="dialog"
      max-width="450"
    >
      <VCard title="New Resource">
        <VCardText>
          <VAlert
            v-if="error"
            type="error"
            class="mb-4"
          >
            {{ error }}
          </VAlert>

          <VSelect
            v-model="form.type"
            label="Type"
            class="mb-4"
            :items="[
              { title: 'Internal Staff', value: 'internal' },
              { title: 'Contractor', value: 'contractor' },
            ]"
          />
          <VSelect
            v-if="form.type === 'contractor'"
            v-model="form.party_id"
            label="Contractor Party"
            class="mb-4"
            item-title="name"
            item-value="id"
            :items="parties"
          />
          <VTextField
            v-model="form.skill_category"
            label="Skill Category"
            placeholder="e.g. Electrical, Masonry, Site Supervision"
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
