<script setup>
/* eslint-disable camelcase -- these object keys are literal Laravel API request/response field names (snake_case is the real contract), not JS identifiers to rename. */
definePage({
  meta: { action: 'read', subject: 'Assets' },
})

const router = useRouter()

const assets = ref([])
const categories = ref([])
const loading = ref(true)
const dialog = ref(false)
const categoryDialog = ref(false)
const saving = ref(false)
const error = ref('')

const form = ref({
  asset_number: '', name: '', category_id: null, asset_type: 'fixed_asset', serial_number: '',
  date_of_purchase: '', purchase_cost: null, residual_value: 0, useful_life_years: 5, depreciation_method: 'straight_line',
})

const categoryForm = ref({ name: '' })

async function loadAll() {
  loading.value = true
  try {
    const [a, c] = await Promise.all([$api('/assets'), $api('/asset-categories')])

    assets.value = a
    categories.value = c
  } finally {
    loading.value = false
  }
}

function openCreate() {
  error.value = ''
  form.value = {
    asset_number: '', name: '', category_id: null, asset_type: 'fixed_asset', serial_number: '',
    date_of_purchase: '', purchase_cost: null, residual_value: 0, useful_life_years: 5, depreciation_method: 'straight_line',
  }
  dialog.value = true
}

async function save() {
  error.value = ''
  saving.value = true
  try {
    await $api('/assets', { method: 'POST', body: form.value })
    dialog.value = false
    await loadAll()
  } catch (err) {
    error.value = extractApiErrorMessage(err)
  } finally {
    saving.value = false
  }
}

async function saveCategory() {
  saving.value = true
  try {
    await $api('/asset-categories', { method: 'POST', body: categoryForm.value })
    categoryDialog.value = false
    categoryForm.value = { name: '' }
    await loadAll()
  } catch (err) {
    error.value = extractApiErrorMessage(err)
  } finally {
    saving.value = false
  }
}

const statusColor = status => ({
  in_use: 'success', under_maintenance: 'warning', disposed: 'secondary', written_off: 'error',
}[status] || 'secondary')

onMounted(loadAll)
</script>

<template>
  <VCard title="Fixed Assets & Plant/Equipment">
    <template #append>
      <VBtn
        variant="text"
        class="me-2"
        @click="categoryDialog = true"
      >
        New Category
      </VBtn>
      <VBtn @click="openCreate">
        New Asset
      </VBtn>
    </template>

    <VDataTable
      :headers="[
        { title: 'Asset #', key: 'asset_number' },
        { title: 'Name', key: 'name' },
        { title: 'Type', key: 'asset_type' },
        { title: 'Purchase Cost', key: 'purchase_cost' },
        { title: 'Status', key: 'status' },
      ]"
      :items="assets"
      :loading="loading"
      item-value="id"
      @click:row="(_, { item }) => router.push(`/assets/${item.id}`)"
    >
      <template #item.status="{ item }">
        <VChip :color="statusColor(item.status)">
          {{ item.status }}
        </VChip>
      </template>
    </VDataTable>

    <VDialog
      v-model="dialog"
      max-width="600"
    >
      <VCard title="New Asset">
        <VCardText>
          <VAlert
            v-if="error"
            type="error"
            class="mb-4"
          >
            {{ error }}
          </VAlert>

          <VRow>
            <VCol
              cols="12"
              md="6"
            >
              <VTextField
                v-model="form.asset_number"
                label="Asset Number"
              />
            </VCol>
            <VCol
              cols="12"
              md="6"
            >
              <VTextField
                v-model="form.name"
                label="Name"
              />
            </VCol>
            <VCol
              cols="12"
              md="6"
            >
              <VSelect
                v-model="form.category_id"
                label="Category"
                item-title="name"
                item-value="id"
                :items="categories"
              />
            </VCol>
            <VCol
              cols="12"
              md="6"
            >
              <VSelect
                v-model="form.asset_type"
                label="Asset Type"
                :items="[
                  { title: 'Fixed Asset', value: 'fixed_asset' },
                  { title: 'Plant/Equipment', value: 'plant_equipment' },
                ]"
              />
            </VCol>
            <VCol
              cols="12"
              md="6"
            >
              <VTextField
                v-model="form.serial_number"
                label="Serial Number (optional)"
              />
            </VCol>
            <VCol
              cols="12"
              md="6"
            >
              <VTextField
                v-model="form.date_of_purchase"
                type="date"
                label="Date of Purchase"
              />
            </VCol>
            <VCol
              cols="12"
              md="6"
            >
              <VTextField
                v-model.number="form.purchase_cost"
                type="number"
                label="Purchase Cost"
              />
            </VCol>
            <VCol
              cols="12"
              md="6"
            >
              <VTextField
                v-model.number="form.residual_value"
                type="number"
                label="Residual Value"
              />
            </VCol>
            <VCol
              cols="12"
              md="6"
            >
              <VTextField
                v-model.number="form.useful_life_years"
                type="number"
                label="Useful Life (years)"
              />
            </VCol>
            <VCol
              cols="12"
              md="6"
            >
              <VSelect
                v-model="form.depreciation_method"
                label="Depreciation Method"
                :items="[
                  { title: 'Straight Line', value: 'straight_line' },
                  { title: 'Sum of Digits', value: 'sum_of_digits' },
                  { title: 'Diminishing Balance', value: 'diminishing_balance' },
                ]"
              />
            </VCol>
          </VRow>
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

    <VDialog
      v-model="categoryDialog"
      max-width="400"
    >
      <VCard title="New Asset Category">
        <VCardText>
          <VTextField
            v-model="categoryForm.name"
            label="Name"
          />
        </VCardText>
        <VCardActions>
          <VSpacer />
          <VBtn
            variant="text"
            @click="categoryDialog = false"
          >
            Cancel
          </VBtn>
          <VBtn
            :loading="saving"
            @click="saveCategory"
          >
            Save
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>
  </VCard>
</template>
