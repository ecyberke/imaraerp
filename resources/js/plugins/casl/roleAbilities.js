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
// 'Manufacturing' (ProductionOrder - manufacturing branch, mirrors
// ProductionOrderPolicy's own Admin/Warehouse/Procurement default),
// 'Resourcing' (Resource/ResourceAssignment - labour-resourcing branch,
// mirrors §11's "Project Manager - Project, Milestone, Resource
// Assignment"), 'Projects' (Project/Milestone/VariationOrder/Defect -
// projects-milestones-ui branch, same §11 line plus "Site Supervisor -
// Project Utilization, Quality Sign Off" for the narrower utilize/sign-
// off/defect actions), 'Assets' (Asset/AssetComponent/AssetRevaluation/
// AssetDisposal/AssetAssignment/EquipmentHireContract - fixed-assets-plant
// branch, mirrors §11's "Asset Manager" line), 'Dashboard' (every role can
// at least see their own dashboard).
export function buildAbilityRulesForRole(roleName) {
  if (roleName === 'admin')
    return [{ action: 'manage', subject: 'all' }]

  const rules = [{ action: 'read', subject: 'Dashboard' }]

  if (['sales', 'finance', 'project_manager'].includes(roleName))
    rules.push({ action: 'manage', subject: 'SalesBilling' })
  else if (['procurement', 'warehouse'].includes(roleName))
    rules.push({ action: 'read', subject: 'SalesBilling' })

  if (['warehouse', 'procurement'].includes(roleName))
    rules.push({ action: 'manage', subject: 'Manufacturing' })

  if (roleName === 'project_manager')
    rules.push({ action: 'manage', subject: 'Resourcing' })

  if (['project_manager', 'site_supervisor'].includes(roleName))
    rules.push({ action: 'manage', subject: 'Projects' })

  if (roleName === 'asset_manager')
    rules.push({ action: 'manage', subject: 'Assets' })

  return rules
}
