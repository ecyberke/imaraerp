<script setup>
/* eslint-disable camelcase -- these object keys are literal Laravel API request/response field names (snake_case is the real contract), not JS identifiers to rename. */
definePage({
  meta: { action: 'read', subject: 'Admin' },
})

const ENTITY_TYPE_LABELS = {
  purchase_requisition: 'Purchase Requisition',
  purchase_order: 'Purchase Order',
  credit_approval: 'Credit Approval',
  variation_order: 'Variation Order',
}

const limits = ref([])
const roles = ref([])
const loading = ref(true)
const dialog = ref(false)
const saving = ref(false)
const error = ref('')
const editingId = ref(null)

const emptyForm = {
  role_id: null,
  entity_type: null,
  max_amount: null,
  requires_second_approval_above: null,
  second_approver_role_id: null,
}

const form = ref({ ...emptyForm })

const headers = [
  { title: 'Role', key: 'role_label' },
  { title: 'Entity', key: 'entity_label' },
  { title: 'Max Amount (KES)', key: 'max_amount' },
  { title: 'Second Approval Above (KES)', key: 'requires_second_approval_above' },
  { title: 'Second Approver Role', key: 'second_approver_label' },
  { title: '', key: 'actions', sortable: false },
]

const rows = computed(() => limits.value.map(limit => ({
  ...limit,
  role_label: limit.role?.label ?? limit.role?.name,
  entity_label: ENTITY_TYPE_LABELS[limit.entity_type] ?? limit.entity_type,
  second_approver_label: limit.second_approver_role?.label ?? limit.second_approver_role?.name ?? '-',
})))

async function loadAll() {
  loading.value = true
  try {
    const [l, r] = await Promise.all([$api('/approval-limits'), $api('/roles')])

    limits.value = l
    roles.value = r
  } finally {
    loading.value = false
  }
}

function openCreate() {
  error.value = ''
  editingId.value = null
  form.value = { ...emptyForm }
  dialog.value = true
}

function openEdit(limit) {
  error.value = ''
  editingId.value = limit.id
  form.value = {
    role_id: limit.role_id,
    entity_type: limit.entity_type,
    max_amount: limit.max_amount,
    requires_second_approval_above: limit.requires_second_approval_above,
    second_approver_role_id: limit.second_approver_role_id,
  }
  dialog.value = true
}

async function save() {
  error.value = ''
  saving.value = true
  try {
    if (editingId.value)
      await $api(`/approval-limits/${editingId.value}`, { method: 'PUT', body: form.value })
    else
      await $api('/approval-limits', { method: 'POST', body: form.value })
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
  <VCard
    title="Approval Limits"
    subtitle="Who may approve what, and when a second approver is required - Admin only."
  >
    <template #append>
      <VBtn @click="openCreate">
        New Approval Limit
      </VBtn>
    </template>

    <VDataTable
      :headers="headers"
      :items="rows"
      :loading="loading"
      item-value="id"
    >
      <template #item.actions="{ item }">
        <VBtn
          size="small"
          variant="text"
          @click="openEdit(item)"
        >
          Edit
        </VBtn>
      </template>
    </VDataTable>

    <VDialog
      v-model="dialog"
      max-width="480"
    >
      <VCard :title="editingId ? 'Edit Approval Limit' : 'New Approval Limit'">
        <VCardText>
          <VAlert
            v-if="error"
            type="error"
            class="mb-4"
          >
            {{ error }}
          </VAlert>

          <VSelect
            v-model="form.role_id"
            label="Role"
            class="mb-4"
            item-title="label"
            item-value="id"
            :items="roles"
          />

          <VSelect
            v-model="form.entity_type"
            label="Entity Type"
            class="mb-4"
            :items="Object.entries(ENTITY_TYPE_LABELS).map(([value, title]) => ({ title, value }))"
          />

          <VTextField
            v-model.number="form.max_amount"
            label="Max Amount (KES)"
            type="number"
            class="mb-4"
          />

          <VTextField
            v-model.number="form.requires_second_approval_above"
            label="Requires Second Approval Above (KES, optional)"
            type="number"
            class="mb-4"
          />

          <VSelect
            v-model="form.second_approver_role_id"
            label="Second Approver Role (required if the field above is set)"
            clearable
            item-title="label"
            item-value="id"
            :items="roles"
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
