export default [
  {
    title: 'Fixed Assets & Plant',
    icon: { icon: 'ri-truck-line' },
    action: 'read',
    subject: 'Assets',
    children: [
      { title: 'Assets', to: 'assets' },
      { title: 'Equipment Hire Contracts', to: 'assets-equipment-hire' },
    ],
  },
]
