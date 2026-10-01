<script setup>
/* eslint-disable camelcase -- these object keys are literal Laravel API request/response field names (snake_case is the real contract), not JS identifiers to rename. */
definePage({
  meta: { action: 'read', subject: 'Projects' },
})

const router = useRouter()

const projects = ref([])
const parties = ref([])
const loading = ref(true)
const dialog = ref(false)
const saving = ref(false)
const error = ref('')

const form = ref({ party_id: null, name: '', specification_file_path: '' })

async function loadAll() {
  loading.value = true
  try {
    const [p, parties_] = await Promise.all([$api('/projects'), $api('/parties')])

    projects.value = p
    parties.value = parties_
  } finally {
    loading.value = false
  }
}

function openCreate() {
  error.value = ''
  form.value = { party_id: null, name: '', specification_file_path: '' }
  dialog.value = true
}

async function save() {
  error.value = ''
  saving.value = true
  try {
    await $api('/projects', { method: 'POST', body: form.value })
    dialog.value = false
    await loadAll()
  } catch (err) {
    error.value = extractApiErrorMessage(err)
  } finally {
    saving.value = false
  }
}

const statusColor = status => ({
  initiated: 'secondary', in_progress: 'primary', complete: 'info',
  defects_liability: 'warning', closed: 'success', cancelled: 'error',
}[status] || 'secondary')

onMounted(loadAll)
</script>

<template>
  <VCard title="Projects">
    <template #append>
      <VBtn @click="openCreate">
        New Project
      </VBtn>
    </template>

    <VDataTable
      :headers="[
        { title: 'Name', key: 'name' },
        { title: 'Status', key: 'status' },
      ]"
      :items="projects"
      :loading="loading"
      item-value="id"
      @click:row="(_, { item }) => router.push(`/projects/${item.id}`)"
    >
      <template #item.status="{ item }">
        <VChip :color="statusColor(item.status)">
          {{ item.status }}
        </VChip>
      </template>
    </VDataTable>

    <VDialog
      v-model="dialog"
      max-width="450"
    >
      <VCard title="New Project">
        <VCardText>
          <VAlert
            v-if="error"
            type="error"
            class="mb-4"
          >
            {{ error }}
          </VAlert>

          <VSelect
            v-model="form.party_id"
            label="Client"
            class="mb-4"
            item-title="name"
            item-value="id"
            :items="parties"
          />
          <VTextField
            v-model="form.name"
            label="Project Name"
            class="mb-4"
          />
          <VTextField
            v-model="form.specification_file_path"
            label="Specification File Path (optional)"
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
