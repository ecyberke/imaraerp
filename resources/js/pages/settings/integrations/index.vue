<script setup>
/* eslint-disable camelcase -- these object keys are literal Laravel API request/response field names (snake_case is the real contract), not JS identifiers to rename. */
definePage({
  meta: { action: 'read', subject: 'Admin' },
})

const PROVIDER_LABELS = { mpesa: 'M-Pesa (Daraja)', etims: 'KRA eTIMS' }

const providers = ref([])
const loading = ref(true)
const saving = ref({})
const notice = ref('')
const noticeType = ref('success')

function flash(message, type = 'success') {
  notice.value = message
  noticeType.value = type
  setTimeout(() => { notice.value = '' }, 6000)
}

async function load() {
  loading.value = true
  try {
    providers.value = await $api('/integration-credentials')
  } finally {
    loading.value = false
  }
}

async function save(provider) {
  saving.value[provider.provider] = true
  try {
    const fields = Object.fromEntries(Object.entries(provider.fields).map(([key, field]) => [key, field.value]))

    const updated = await $api(`/integration-credentials/${provider.provider}`, {
      method: 'PUT',
      body: { is_active: provider.is_active, fields },
    })

    Object.assign(provider, updated)
    flash(`${PROVIDER_LABELS[provider.provider] ?? provider.provider} settings saved.`)
  } catch (err) {
    flash(extractApiErrorMessage(err), 'error')
  } finally {
    saving.value[provider.provider] = false
  }
}

onMounted(load)
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

    <VProgressCircular
      v-if="loading"
      indeterminate
    />

    <VCard
      v-for="provider in providers"
      v-else
      :key="provider.provider"
      class="mb-4"
      :title="PROVIDER_LABELS[provider.provider] ?? provider.provider"
    >
      <template #append>
        <VSwitch
          v-model="provider.is_active"
          label="Enabled"
          hide-details
        />
      </template>
      <VCardText>
        <p
          v-if="!Object.keys(provider.fields).length"
          class="text-medium-emphasis mb-4"
        >
          No configuration fields for this provider yet - this integration lands in a later branch.
        </p>

        <VRow v-else>
          <VCol
            v-for="(field, key) in provider.fields"
            :key="key"
            cols="12"
            md="6"
          >
            <VSelect
              v-if="key === 'environment'"
              v-model="field.value"
              :label="field.label"
              :items="[{ title: 'Sandbox', value: 'sandbox' }, { title: 'Production', value: 'production' }]"
            />
            <VTextField
              v-else
              v-model="field.value"
              :label="field.label"
              :type="field.secret ? 'password' : 'text'"
              :placeholder="field.secret ? 'Leave blank to keep the current value' : null"
            />
          </VCol>
        </VRow>
      </VCardText>
      <VCardActions v-if="Object.keys(provider.fields).length">
        <VSpacer />
        <VBtn
          :loading="saving[provider.provider]"
          @click="save(provider)"
        >
          Save
        </VBtn>
      </VCardActions>
    </VCard>
  </div>
</template>
