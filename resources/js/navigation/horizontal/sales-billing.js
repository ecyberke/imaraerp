export default [
  {
    title: 'Sales & Billing',
    icon: { icon: 'ri-file-list-3-line' },
    action: 'read',
    subject: 'SalesBilling',
    children: [
      { title: 'Leads', to: 'sales-billing-leads' },
      { title: 'Quotations', to: 'sales-billing-quotations' },
      { title: 'BOQs', to: 'sales-billing-boq' },
      { title: 'Catalog', to: 'sales-billing-catalog' },
      { title: 'Customers & Suppliers', to: 'sales-billing-parties' },
      { title: 'Bank Accounts', to: 'sales-billing-bank-accounts' },
    ],
  },
]
