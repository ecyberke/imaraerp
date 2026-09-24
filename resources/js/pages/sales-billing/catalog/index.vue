<script setup>
/* eslint-disable camelcase -- these object keys are literal Laravel API request/response field names (snake_case is the real contract), not JS identifiers to rename. */
definePage({
  meta: { action: 'read', subject: 'SalesBilling' },
})

const tab = ref('items')

const categories = ref([])
const units = ref([])
const items = ref([])
const loading = ref(true)

const catDialog = ref(false)
const catForm = ref({ name: '', valuation_method: 'fifo' })
const catError = ref('')
const catSaving = ref(false)

const uomDialog = ref(false)
const uomForm = ref({ code: '', name: '' })
const uomError = ref('')
const uomSaving = ref(false)

const itemDialog = ref(false)
const itemForm = ref({ sku: '', name: '', category_id: null, type: 'finished_good', uom_id: null })
const itemError = ref('')
const itemSaving = ref(false)

const categoryHeaders = [{ title: 'Name', key: 'name' }, { title: 'Valuation Method', key: 'valuation_method' }]
const unitHeaders = [{ title: 'Code', key: 'code' }, { title: 'Name', key: 'name' }]
const itemHeaders = [{ title: 'SKU', key: 'sku' }, { title: 'Name', key: 'name' }, { title: 'Type', key: 'type' }]

async function loadAll() {
  loading.value = true
  try {
    const [c, u, i] = await Promise.all([$api('/categories'), $api('/units-of-measure'), $api('/items')])

    categories.value = c
    units.value = u
    items.value = i
  } finally {
    loading.value = false
  }
}

function openCategory() {
  catError.value = ''
  catForm.value = { name: '', valuation_method: 'fifo' }
  catDialog.value = true
}

async function saveCategory() {
  catError.value = ''
  catSaving.value = true
  try {
    await $api('/categories', { method: 'POST', body: catForm.value })
    catDialog.value = false
    await loadAll()
  } catch (err) {
    catError.value = extractApiErrorMessage(err)
  } finally {
    catSaving.value = false
  }
}

function openUom() {
  uomError.value = ''
  uomForm.value = { code: '', name: '' }
  uomDialog.value = true
}

async function saveUom() {
  uomError.value = ''
  uomSaving.value = true
  try {
    await $api('/units-of-measure', { method: 'POST', body: uomForm.value })
    uomDialog.value = false
    await loadAll()
  } catch (err) {
    uomError.value = extractApiErrorMessage(err)
  } finally {
    uomSaving.value = false
  }
}

function openItem() {
  itemError.value = ''
  itemForm.value = { sku: '', name: '', category_id: null, type: 'finished_good', uom_id: null }
  itemDialog.value = true
}

async function saveItem() {
  itemError.value = ''
  itemSaving.value = true
  try {
    await $api('/items', { method: 'POST', body: itemForm.value })
    itemDialog.value = false
    await loadAll()
  } catch (err) {
    itemError.value = extractApiErrorMessage(err)
  } finally {
    itemSaving.value = false
  }
}

onMounted(loadAll)
</script>

<template>
  <VCard title="Product Catalog">
    <VTabs v-model="tab">
      <VTab value="items">
        Items
      </VTab>
      <VTab value="categories">
        Categories
      </VTab>
      <VTab value="units">
        Units of Measure
      </VTab>
    </VTabs>

    <VCardText>
      <VWindow v-model="tab">
        <VWindowItem value="items">
          <div class="d-flex justify-end mb-4">
            <VBtn @click="openItem">
              New Item
            </VBtn>
          </div>
          <VDataTable
            :headers="itemHeaders"
            :items="items"
            :loading="loading"
            item-value="id"
          />
        </VWindowItem>

        <VWindowItem value="categories">
          <div class="d-flex justify-end mb-4">
            <VBtn @click="openCategory">
              New Category
            </VBtn>
          </div>
          <VDataTable
            :headers="categoryHeaders"
            :items="categories"
            :loading="loading"
            item-value="id"
          />
        </VWindowItem>

        <VWindowItem value="units">
          <div class="d-flex justify-end mb-4">
            <VBtn @click="openUom">
              New Unit
            </VBtn>
          </div>
          <VDataTable
            :headers="unitHeaders"
            :items="units"
            :loading="loading"
            item-value="id"
          />
        </VWindowItem>
      </VWindow>
    </VCardText>

    <VDialog
      v-model="catDialog"
      max-width="450"
    >
      <VCard title="New Category">
        <VCardText>
          <VAlert
            v-if="catError"
            type="error"
            class="mb-4"
          >
            {{ catError }}
          </VAlert>
          <VTextField
            v-model="catForm.name"
            label="Name"
            class="mb-4"
          />
          <VSelect
            v-model="catForm.valuation_method"
            label="Valuation Method"
            :items="[
              { title: 'FIFO', value: 'fifo' },
              { title: 'Weighted Average', value: 'weighted_average' },
              { title: 'Standard Cost', value: 'standard_cost' },
            ]"
          />
        </VCardText>
        <VCardActions>
          <VSpacer />
          <VBtn
            variant="text"
            @click="catDialog = false"
          >
            Cancel
          </VBtn>
          <VBtn
            :loading="catSaving"
            @click="saveCategory"
          >
            Save
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <VDialog
      v-model="uomDialog"
      max-width="450"
    >
      <VCard title="New Unit of Measure">
        <VCardText>
          <VAlert
            v-if="uomError"
            type="error"
            class="mb-4"
          >
            {{ uomError }}
          </VAlert>
          <VTextField
            v-model="uomForm.code"
            label="Code"
            placeholder="e.g. PC, BAG, M3"
            class="mb-4"
          />
          <VTextField
            v-model="uomForm.name"
            label="Name"
          />
        </VCardText>
        <VCardActions>
          <VSpacer />
          <VBtn
            variant="text"
            @click="uomDialog = false"
          >
            Cancel
          </VBtn>
          <VBtn
            :loading="uomSaving"
            @click="saveUom"
          >
            Save
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <VDialog
      v-model="itemDialog"
      max-width="450"
    >
      <VCard title="New Item">
        <VCardText>
          <VAlert
            v-if="itemError"
            type="error"
            class="mb-4"
          >
            {{ itemError }}
          </VAlert>
          <VTextField
            v-model="itemForm.sku"
            label="SKU"
            class="mb-4"
          />
          <VTextField
            v-model="itemForm.name"
            label="Name"
            class="mb-4"
          />
          <VSelect
            v-model="itemForm.type"
            label="Type"
            class="mb-4"
            :items="[
              { title: 'Finished Good', value: 'finished_good' },
              { title: 'Raw Material', value: 'raw_material' },
            ]"
          />
          <VSelect
            v-model="itemForm.category_id"
            label="Category"
            class="mb-4"
            item-title="name"
            item-value="id"
            :items="categories"
          />
          <VSelect
            v-model="itemForm.uom_id"
            label="Unit of Measure"
            item-title="name"
            item-value="id"
            :items="units"
          />
        </VCardText>
        <VCardActions>
          <VSpacer />
          <VBtn
            variant="text"
            @click="itemDialog = false"
          >
            Cancel
          </VBtn>
          <VBtn
            :loading="itemSaving"
            @click="saveItem"
          >
            Save
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>
  </VCard>
</template>
