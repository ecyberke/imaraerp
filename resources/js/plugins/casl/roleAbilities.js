// CASL is a frontend-only UX layer (hide/show nav and actions) - the
// real authorization is every Laravel Policy the backend already
// enforces per-request. This maps each of the 9 seeded roles (§11) to
// a small set of CASL subjects, kept coarse on purpose: getting this
// mapping slightly wrong only ever hides or shows a button, it never
// grants or blocks a real action (the API call behind it still goes
// through its own Policy check and can still 403).
//
// Subjects used by this app's own routes: 'SalesBilling' (Lead,
// Quotation, Feasibility, Delivery, Invoice, Payment, BOQ, Bank
// Accounts - the phase1-screens exit-criterion flow, kept as one
// subject rather than one per entity since every role that can touch
// any part of that flow needs to see the whole thing to complete it),
// 'Dashboard' (every role can at least see their own dashboard).
export function buildAbilityRulesForRole(roleName) {
  if (roleName === 'admin')
    return [{ action: 'manage', subject: 'all' }]

  const rules = [{ action: 'read', subject: 'Dashboard' }]

  if (['sales', 'finance', 'project_manager'].includes(roleName))
    rules.push({ action: 'manage', subject: 'SalesBilling' })
  else if (['procurement', 'warehouse'].includes(roleName))
    rules.push({ action: 'read', subject: 'SalesBilling' })

  return rules
}
