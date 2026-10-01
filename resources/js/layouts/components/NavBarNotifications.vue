<script setup>
/* eslint-disable camelcase -- these object keys are literal Laravel API notification `type`/field values (snake_case is the real contract), not JS identifiers to rename. */
const TYPE_META = {
  reorder_alert: { title: 'Reorder Alert', icon: 'ri-refresh-line', color: 'warning' },
  approval_pending: { title: 'Approval Pending', icon: 'ri-time-line', color: 'primary' },
  qc_failure: { title: 'QC Failure', icon: 'ri-error-warning-line', color: 'error' },
  milestone_due: { title: 'Milestone Due', icon: 'ri-flag-line', color: 'info' },
  retention_release_due: { title: 'Retention Release Due', icon: 'ri-wallet-3-line', color: 'info' },
  casual_conversion_due: { title: 'Casual Conversion Due', icon: 'ri-user-line', color: 'warning' },
  dlp_ready_to_close: { title: 'DLP Ready to Close', icon: 'ri-checkbox-circle-line', color: 'success' },
  bom_variance_exceeded: { title: 'BOM Variance Exceeded', icon: 'ri-stack-line', color: 'error' },
  hire_invoice_mismatch: { title: 'Hire Invoice Mismatch', icon: 'ri-file-warning-line', color: 'error' },
}

const raw = ref([])

const notifications = computed(() => raw.value
  .filter(n => n.sent_at)
  .map(n => ({
    id: n.id,
    icon: TYPE_META[n.type]?.icon ?? 'ri-notification-2-line',
    color: TYPE_META[n.type]?.color ?? 'secondary',
    title: TYPE_META[n.type]?.title ?? n.type,
    subtitle: n.message,
    time: n.sent_at?.slice(0, 16).replace('T', ' ') ?? '',
    isSeen: !!n.read_at,
  })))

async function load() {
  try {
    raw.value = await $api('/notifications')
  } catch {
    raw.value = []
  }
}

const removeNotification = notificationId => {
  raw.value = raw.value.filter(item => item.id !== notificationId)
}

async function markRead(notificationIds) {
  raw.value.forEach(item => {
    if (notificationIds.includes(item.id))
      item.read_at = item.read_at ?? new Date().toISOString()
  })
  await Promise.all(notificationIds.map(id => $api(`/notifications/${id}/mark-read`, { method: 'POST' }).catch(() => {})))
}

// No backend endpoint marks a notification unread again - this is a
// client-only UI toggle for this session, not persisted.
const markUnRead = notificationIds => {
  raw.value.forEach(item => {
    if (notificationIds.includes(item.id))
      item.read_at = null
  })
}

const handleNotificationClick = notification => {
  if (!notification.isSeen)
    markRead([notification.id])
}

onMounted(load)
</script>

<template>
  <Notifications
    :notifications="notifications"
    @remove="removeNotification"
    @read="markRead"
    @unread="markUnRead"
    @click:notification="handleNotificationClick"
  />
</template>
