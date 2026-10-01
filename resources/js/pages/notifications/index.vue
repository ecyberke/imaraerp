<script setup>
/* eslint-disable camelcase -- these object keys are literal Laravel API notification `type` values (snake_case is the real contract), not JS identifiers to rename. */
definePage({
  meta: { action: 'read', subject: 'Dashboard' },
})

const TYPE_LABELS = {
  reorder_alert: 'Reorder Alert',
  approval_pending: 'Approval Pending',
  qc_failure: 'QC Failure',
  milestone_due: 'Milestone Due',
  retention_release_due: 'Retention Release Due',
  casual_conversion_due: 'Casual Conversion Due',
  dlp_ready_to_close: 'DLP Ready to Close',
  bom_variance_exceeded: 'BOM Variance Exceeded',
  hire_invoice_mismatch: 'Hire Invoice Mismatch',
}

const notifications = ref([])
const loading = ref(true)
const submitting = ref({})

async function load() {
  loading.value = true
  try {
    notifications.value = await $api('/notifications')
  } finally {
    loading.value = false
  }
}

async function markRead(notification) {
  submitting.value[notification.id] = true
  try {
    await $api(`/notifications/${notification.id}/mark-read`, { method: 'POST' })
    await load()
  } finally {
    submitting.value[notification.id] = false
  }
}

onMounted(load)
</script>

<template>
  <VCard title="Notifications">
    <VDataTable
      :headers="[
        { title: 'Type', key: 'type' },
        { title: 'Message', key: 'message' },
        { title: 'Sent', key: 'sent_at' },
        { title: 'Status', key: 'status' },
        { title: '', key: 'actions', sortable: false },
      ]"
      :items="notifications"
      :loading="loading"
      item-value="id"
    >
      <template #item.type="{ item }">
        {{ TYPE_LABELS[item.type] ?? item.type }}
      </template>
      <template #item.sent_at="{ item }">
        {{ item.sent_at ? item.sent_at.slice(0, 16).replace('T', ' ') : 'pending (batched)' }}
      </template>
      <template #item.status="{ item }">
        <VChip :color="item.read_at ? 'secondary' : 'primary'">
          {{ item.read_at ? 'Read' : 'Unread' }}
        </VChip>
      </template>
      <template #item.actions="{ item }">
        <VBtn
          v-if="!item.read_at"
          size="small"
          variant="text"
          :loading="submitting[item.id]"
          @click="markRead(item)"
        >
          Mark Read
        </VBtn>
      </template>
    </VDataTable>
  </VCard>
</template>
