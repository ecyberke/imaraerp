export default [
  {
    title: 'HR & Payroll',
    icon: { icon: 'ri-team-line' },
    action: 'read',
    subject: 'HR',
    children: [
      { title: 'Employees', to: 'hr-employees' },
      { title: 'Payroll Runs', to: 'hr-payroll-runs' },
    ],
  },
]
