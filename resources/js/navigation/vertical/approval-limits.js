export default [
  {
    title: 'Admin',
    icon: { icon: 'ri-shield-check-line' },
    action: 'read',
    subject: 'Admin',
    children: [
      { title: 'Approval Limits', to: 'approval-limits' },
      { title: 'Integrations', to: 'settings-integrations' },
    ],
  },
]
