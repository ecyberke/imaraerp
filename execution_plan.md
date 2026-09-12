# Imara ERP — Execution Plan
**Companion to `Imara-ERP-ARCHITECTURE.md` (check its `**Version:**` line for the current revision — this plan is edited independently and will drift out of sync with a hardcoded number here) and `LedgerPostingServiceTest.php`.** The architecture doc defines *what* to build and *why*; this plan defines *what order* and *how it splits into branches*. The test file is the executable spec for every ledger posting — port it to real Feature tests as part of `ledger-core` below, don't rewrite its logic from scratch. If any of these three documents disagree, the architecture doc is the source of truth; this plan and the test file get corrected, not the other way round.

**Doc-as-code, stated as policy rather than assumed:** all three documents live in the same repo as the code they describe, versioned together. A PR that changes a modeled entity updates the architecture doc in the same PR — the source of truth doesn't drift silently into code, and this plan/the kickoff prompt don't accumulate hardcoded version numbers or method/row counts that go stale the moment the architecture doc changes again (exactly the failure mode two consecutive review rounds caught and fixed here). Prefer "check the current revision" phrasing over "as of vN" in any future edits to either companion document.

**Working conventions** (matching the pattern already used on Lipa Kodi/Sali IMS): named feature branches, one logical unit of work each, PR before merge, no direct commits to main. Each branch below states its scope, its dependencies, and its exit criterion.

---

## Phase 0 — Foundation (blocks everything else)

### Branch: `project-init`
- Laravel 12 / PHP 8.2 / PostgreSQL install from the Materialize Vue-Laravel `full-version` package (**javascript-version**, not typescript — confirmed in §1), per §1 and §9.
- Environment config, Sanctum installed in **pure bearer-token mode** (not SPA cookie mode — §1.1 is explicit about this distinction and its CSRF implications).
- Base Vue Router / Pinia scaffolding confirmed working against the SPA catch-all shell.
- **Exit criterion:** a logged-in session round-trips through Sanctum against a real DB; no ERP entities yet.

### Branch: `platform-foundation`
Implements §1's foundational bullets and §1.1's Operational Envelope — this branch is bigger than it was in earlier plans, because §1.1 didn't exist until v12.
- **`Tenant` and `UserInvitation` (§3.1) come first, literally before anything else in this branch** — every other table's `tenant_id` foreign-keys to a `Tenant` row that has to exist first, and this branch's own exit criterion (a tenant-scoped, policy-gated dummy entity) is unachievable without it. Provisioning flow: a `Tenant` is created, an admin `User` is invited via `UserInvitation`, they accept and set a password, only then does onboarding (Chart of Accounts seed, `DocumentSequence` initialization) proceed.
- `tenant_id` on every tenant-scoped table via a global Eloquent model scope (§3's tenancy rule) — set this pattern once, correctly, before any entity migration is written.
- Laravel Policies skeleton, one policy class per entity family, wired to the RBAC roles in §11 — **CASL stays frontend-only** (§1); no controller action ships without a corresponding Policy check.
- **Security baseline (§1.1):** HTTPS/TLS/HSTS, CORS locked per environment, rate-limited auth endpoints, password policy, account lockout, all Sanctum tokens revoked on password change/termination, **MFA mandatory for Finance and Admin roles** from this branch, not added later.
- Laravel Scheduler + queue worker setup (database queue for v1), `withoutOverlapping()` on every scheduled job (§1).
- `DocumentSequence` — row-locked counter table, per-tenant, resets on the **Kenyan tax year (1 January)** regardless of a client's own fiscal year, format `{prefix}-{fiscal_year}-{next_number, zero-padded to 5 digits}` (§3.10).
- `AccountingPeriod` with the corrected backdating rule: auto-forward only when the *intended* period is itself not open, never blind-forward to "earliest open" when multiple periods are simultaneously open (§3.10).
- `AuditLog` wired as a model observer on every financial entity from this point forward, with its own access Policy (it contains Payslip salary data, §1.1) — soft-delete-only on financial records.
- **Backup & Recovery targets set at infra level, not just documented:** RPO 15 min (WAL archiving/PITR), RTO 4 hr, off-site backups in a Kenya/Africa-adjacent region (not US/EU — the DPA residency constraint and backup-diversity requirement apply simultaneously, §1.1/§15), encrypted at rest.
- File storage: S3-compatible, Kenya/Africa-adjacent region.
- `/health` and `/ready` endpoints, structured logging with correlation IDs, `IdempotencyKey` table (§3.10) and idempotency-key handling built into the webhook-receiving infrastructure itself (even though M-Pesa/TalkSasa integration is Phase 3, the infrastructure for it isn't).
- **Business timezone:** all timestamps stored UTC; every date used to bucket a transaction into a period (`posting_date`, `received_at`, `paid_at`, `delivered_at`) is evaluated in Africa/Nairobi (EAT), not raw UTC (§1.1).
- Server-side input validation as a cross-cutting policy: quantity fields reject zero/negative except `variation_type = omission`; rate/amount fields reject negative outright (§1.1).
- **Exit criterion:** a `Tenant` can be provisioned end-to-end (created, admin invited via `UserInvitation`, invitation accepted); a dummy entity can then be created, is correctly tenant-scoped, policy-gated, audit-logged, rejected if dated into a locked `AccountingPeriod`, and MFA-gated for Finance/Admin roles. **Tenant-isolation tests are part of this exit criterion, not a later addition — and specifically an HTTP-level test, not a query-level one:** a model/repository test proving the Eloquent global scope adds a `WHERE tenant_id` clause is necessary but not sufficient. The test that actually satisfies this criterion authenticates as a real `User` belonging to Tenant A and issues a real HTTP request for a record belonging to Tenant B by ID, through the full routing → middleware → Policy → query stack — asserting a **404**, not a 403 (a 403 would itself leak that the record exists). If the isolation test written for this branch only exercises the scope at the model layer, it hasn't met this criterion yet.

### Branch: `ledger-core`
The single most important branch in Phase 1 — everything downstream posts through this. **Two decisions here are schema-shaping, not implementation details, and both are made in writing before the first migration is written, not discovered partway through:**
1. **Money representation.** The reference file's floats-with-epsilon are a spec convenience, explicitly flagged as not for production (§1.1's file header note). Recommendation: **integer minor units (cents) in the database, decimal-safe arithmetic in application code** — for KES amounts routinely in the millions, with per-line VAT rounding rules that already assume exact cent-level arithmetic (§3.9), floats invite exactly the class of rounding bug this document's own review rounds spent real effort catching. Whatever is decided, it's a schema decision affecting every monetary column in every migration that follows — decide once, here, first.
2. **Closing entries — resolved as none needed, stated explicitly so it isn't reinvented.** §10.1 already resolves this: Retained Earnings for the Balance Sheet is computed at report time (opening plug + running Revenue-less-Expense to date), never physically swept at period-end. `AccountingPeriod.status = closed` blocks new postings into that period; it doesn't trigger a posting of its own. Confirm this understanding before writing the Balance Sheet query, not after discovering there's no `period_close` event to port.
- `JournalEntry` / `JournalLine` Eloquent models per §3.9, with the reversal-only correction policy (`reverse()` method, never edit-in-place), and `JournalEntry.original_intended_posting_date` for the `AccountingPeriod` auto-forward case.
- `AnalyticAccount` (§3.7, with `cost_code`) and `TaxCode` (§3.9, multiple WHT codes per payee type, not a single rate) as supporting tables.
- **Real `LedgerPostingService` implementation — port every `postXxx()` method from `LedgerPostingServiceTest.php` (count them; don't hardcode a number here, it will drift) to read from real Eloquent models instead of the plain-array stand-ins.** Keep the exact Dr/Cr shapes and formulas unchanged. Pay particular attention to the two clearing-path methods most recently added — `postWhtReceivableOffset` and `postCustomerAdvanceRefunded` — since these close a gap in the same class as the v8 statutory-payables fix (an asset/liability created with no posting that ever relieves it), and `postCapitalMovement`, which is what the Cash Flow Statement's Financing category needs to have anything to report at all. **`postPaymentReceived` aggregates `PaymentAllocation` rows, not a single figure** — §7's Payment-received row explicitly says "Cr Accounts Receivable at `net_payable` per `PaymentAllocation` line (one payment can post against several invoices in one entry)"; the ported version must sum across every allocation line for that payment, not assume one payment settles one invoice, even though the reference file's own test cases mostly exercise the single-invoice case for simplicity.
- Port every test in `LedgerPostingServiceTest.php` to a Feature test hitting the real database, **including tenant scoping in the assertions** — the reference file has no DB to scope against, the real Feature suite does and must add `where('tenant_id', ...)` accordingly.
- Standard Chart of Accounts seeded from the actual list in §3.1 (Assets, Liabilities, Equity, Revenue, Expenses — including `sub_type`/`parent_account_id` so Retention Receivable/Payable and each statutory payable get their own Balance Sheet line, not a generic bucket) — this was previously undefined and blocked a first client from posting anything at all.
- Money representation decided explicitly for the real implementation (the test file uses floats deliberately, as a spec; production should decide integer minor units vs. decimal type vs. `bcmath` and not inherit float-with-epsilon by default, §1.1's file header note).
- **Exit criterion:** the full ported `LedgerPostingServiceTest.php` suite passes against the real database. **Separately, write and pass concurrency tests for the four row-locking requirements named elsewhere in the architecture doc** — credit-limit check (§1.1/§3.9), Variation Order/BOQ-line mutation (§3.7), Milestone sign-off (§3.7), stock reservation (§6) — each simulated with two simultaneous requests against the same record, verified to resolve to exactly one success and one clear retry/failure. These are new tests to write against the ported service, not something ported from the reference file — the reference file has no database and no concurrency, so it couldn't contain them. This is a hard gate — every subsequent branch's ledger integration depends on this being correct.

---

## Phase 1 — Commercial, Procurement, Inventory Core

Branches in this phase can be built in parallel once `platform-foundation` and `ledger-core` are both merged, but PRs should land in roughly this order since later branches reference earlier ones' migrations.

### Branch: `master-data`
- `Party` (with `tax_residency_status`, `credit_limit`), `User`↔`Employee` relationship stub (§3.1), `Item`/`Category` (with `valuation_method`)/`UnitOfMeasure`/`BillOfMaterials` (with `tolerance_pct`).
- **Exit criterion:** master data CRUD works, tenant-scoped, policy-gated.

### Branch: `inventory-core`
Implements §3.3 and §6 in full.
- `StockLedger` — physical movements only (`receipt, issue, transfer_out, transfer_in, production_consumption, production_output, adjustment, scrap, return`), with `warehouse_id`/`location_id` and a "Main Warehouse" + "In Transit" warehouse seeded.
- `StockQuarantine`, `SupplierPerformanceLog`, `QualityCheck` (the authoritative inspection record — `StockQuarantine.qc_status` is a denormalized summary kept in sync by it, never the source of truth if they disagree).
- Availability computation (on-hand minus active hard reservations, computed at read time) — **check-and-reserve is one locked transaction, not a check followed by a separate write** (§6, a real race condition, not a style preference).
- Valuation logic per category `valuation_method` (FIFO / weighted average / standard cost), effective-dated changes only, never retroactive.
- **Exit criterion:** a GRN receipt → QC pass → stock_ledger insert → availability computation round-trip works and posts correctly through `ledger-core`; two simulated concurrent reservations against the last unit of an item resolve correctly (one succeeds, one gets a clear retry, never both).

### Branch: `procurement`
Implements §3.4 and §5.2.
- `DemandTrigger` (with reorder-level deduplication — only raise a new trigger if no open one already exists for that item/warehouse), `PurchaseRequisition`/`PurchaseRequisitionLine`, `PurchaseOrder`/`PurchaseOrderLine` (with `currency_id`/`exchange_rate`), `GoodsReceiptNote`/`GRNLine` (with the `partially_received` PO state, §5.2 — many `GRNLine`s can reference one `PurchaseOrderLine`, and **for `standard_cost` categories, the standard-vs-actual variance posting to Purchase Price Variance** — a real §7 row and posting, not a variant of the ordinary FIFO/weighted-average receipt), `LandedCost`, `RMReservation` (the raw-material-side reservation that was previously missing entirely — only the finished-goods side existed), `Subcontract` (with `required_document_types`) / `ProgressClaim` (with `status = reversed` and its mirror-image reversal posting), supplier `Payment` disbursements (`payment_made`, with `settlement_exchange_rate`/`fx_gain_loss` for foreign-currency purchases), post-acceptance supplier returns (distinct from the QC-failure return path).
- **Exit criterion:** PR → PO → GRN → QC → ledger posting round-trips, including a partial delivery across two GRNs against one PO line; a Progress Claim certification posts correctly, gets reversed after a failed inspection, and re-certifies as a fresh row; a foreign-currency PO paid at a different rate than its GRN posts the FX gain/loss correctly and AP clears to zero; **a `standard_cost`-category GRN posts Inventory at standard and Accounts Payable at actual, with the difference landing correctly in Purchase Price Variance in both directions (favorable and unfavorable)** — the account was seeded well before this exit criterion existed, and it needs an actual posting behind it, not just a seeded name.

### Branch: `crm-sales-boq`
Implements §3.2 and §5.1.
- `Lead`, `FeasibilityAssessment` (with the "latest assessment wins" transition rule), `Quotation`/`SalesOrder`/`SalesOrderLine` (Direct Sale previously had no line-item structure at all — this was a real, load-bearing gap), `Delivery`/`DeliveryLine` (mirrors `GRNLine`'s partial-fulfillment shape — the sales side had the identical gap procurement's `PurchaseOrderLine` closed, just not yet applied here), `SalesReturn` (promoted to Phase 1 — a return that only reverses revenue via Credit Note and never touches inventory leaves stock permanently understated), `SalesOrderReservation` (`reserve_type`/`status` as orthogonal fields).
- `BOQ` (polymorphic across Project/Subcontract), `BOQSection`, `BOQLine`, `MeasurementSheet` (with `certified_qty`), `Markup`, `BOQImportStaging` (the upload staging table — described in prose for several architecture revisions before it was actually modeled; build the real thing, not a stub).
- SalesOrder state machine split by supply path (§5.1) — Direct Sale/Manufacture-for-Sale follow the drawn path; Project/Manufacture-for-Project gate `closed` on `Project.status = closed` instead.
- **`invoice_policy` triggers, all three specified and none left to guesswork:** `on_order` fires automatically on `SalesOrder.status = approved`; `on_delivery` fires on `Delivery.status = delivered` (with a scheduled safety-net reconciliation, not the primary trigger); `on_milestone` fires on `Milestone`/`MeasurementSheet` reaching the relevant status.
- BOQ upload-and-parse pipeline into `BOQImportStaging`, reusing the Excel/PDF/CSV parsing pattern from the bookkeeping app's statement import.
- **Exit criterion:** a Quotation can be created, pass Feasibility, select a supply path, and (for Direct Sale) reserve stock with real line items against `inventory-core`; a BOQ upload lands in staging and only becomes a live `BOQLine` after explicit confirmation.

### Branch: `finance-billing`
Implements §3.9, depends on `crm-sales-boq` and `procurement` for `invoice_policy`/`ProgressClaim`.
- `Invoice`/`InvoiceLine` (polymorphic, shared with `CreditNote`/`DebitNote` — a single-figure invoice cannot be submitted to KRA TIMS/eTIMS, which requires line-level detail; VAT rounding rule: per-line rounded amounts sum to the invoice total, never a separate invoice-level recalculation), `CreditNote`/`DebitNote` (own ETR/eTIMS fields), `CreditApproval` (a real record, not just a status flip — with the row-locked credit-limit check: `(sum of open Invoices' net_payable) + this invoice ≤ credit_limit`, blocking by default), `Write-off` (Dr Bad Debt Expense, Cr AR — promoted to Phase 1 given construction clients do go insolvent mid-project), `WHT Receivable offset` and `Customer Advance refunded` postings (§7 — the same clearing-gap class the v8 statutory-payables fix closed, just found later on the asset side), `CapitalMovement` (all four directions — the minimum the Cash Flow Statement's Financing category needs to not be structurally empty from day one).
- `RetentionAccount` (direction receivable/payable), `ContractRetentionTerms` (polymorphic, `retention_cap` enforcement inclusive at the boundary), `RetentionRelease` (`status` enum, automatic `pending → ready` re-evaluation, `early_release_reason` as the documented path for early release — routine in Kenyan practice, not an edge case).
- `PaymentAllocation`, `Payment` (`direction`, `reference_type`/`reference_id`, client-WHT fields scoped to receipts only).
- `OpeningBalanceBatch` — the actual migration mechanism (sub-ledger reconstruction for AR/AP/Stock plus a single opening `JournalEntry`), including `Asset.useful_life_years`' original-vs-remaining-life resolution and `DocumentSequence` initialization to one-past a migrating client's last KRA-issued number per document type.
- **Exit criterion:** the full Invoice → Payment → PaymentAllocation cycle posts correctly, including a proportional Credit Note reversal, a Sales Return, a client-withheld-WHT receipt, and a full `OpeningBalanceBatch` migration for a simulated existing client — all matching `ledger-core`'s verified postings. Two simultaneous credit-limit checks against the same client correctly allow only one to pass.

### Branch: `phase1-reports-dashboards`
Implements the full §10.1 report set — this branch is substantially bigger than in earlier plans, since only Trial Balance/AR-AP Aging were originally in scope before v11/v12 added the rest.
- **Trial Balance, AR Aging, AP Aging** (already scoped).
- **Balance Sheet** (grouped by `ChartOfAccounts.account_type`/`sub_type`), **Income Statement/P&L**, **Project P&L** (named as its own report, not an implied filter parameter), **General Ledger — Detail and Summary**, **Party Ledger / Counterparty Statement** (the transaction-history view AR/AP Aging doesn't provide — what an AP clerk reconciles a supplier statement against).
- **Cash Flow Statement** — the `JournalEntry.eventType`-keyed lookup (operating/investing/financing) applied only to Cash/Bank-touching `JournalLine` rows, now including `payment_made` in the operating category (missing from the mechanism until this pass, since the event itself was only just implemented).
- Master Dashboard + module dashboards per §10, including the now-defined Cash Position widget (live sum of Cash/Bank balances across `BankAccount`s, as of now).
- One parameter convention applied to every report: point-in-time reports default to the last closed `AccountingPeriod`; period reports default to the current open period; dashboards default to live/now.
- **Exit criterion:** Trial Balance nets to zero against real posted data; Balance Sheet and Income Statement tie out against it; Cash Flow Statement's operating/investing totals reconcile against actual `BankAccount` movements for a test period.

### Branch: `phase1-screens`
- Wire Vue screens for everything above using the Materialize template mapping in §9 (Kanban → CRM/procurement pipelines, Invoice app → Quotation/Proforma/PO print layout, Roles & Permissions app + CASL as the frontend UX layer only).
- **"Bill from BOQ" as the primary Project-path billing UX** — pick a project, tick certified BOQ lines, lines auto-populate the Invoice — not a blank 30-field form; Direct Sale gets a bulk-paste grid as its equivalent (§9).
- Bulk milestone-allocation UI at the `BOQSection` level (allocate a whole section in one action, per-line override for exceptions) — a 200-line BOQ times 8 milestones is 1,600 potential allocations if done one at a time, and that's not the intended interaction (§3.7).
- Cross-module navigation: every detail page breadcrumbs to its source document and links to what it generated.
- No multi-step form loses entered state on a server-side validation error.
- First-run onboarding checklist for a new tenant's admin (Chart of Accounts confirmed, users invited, warehouses set up, opening balance run).
- Apply the §9 naming rule throughout: no `JournalLine`, `analytic_account_id`, or raw enum values visible on any screen — construction-language labels only.
- **Exit criterion:** a user can complete Lead → Quotation → Feasibility → Direct Sale → Delivery → Invoice → Payment entirely through the UI, and a Project-path user can bill an entire certified BOQ section in one action rather than line by line.

---

## Phase 2 — Manufacturing, Labour, Projects UI, Fixed Assets, HR & Payroll

Data layer for all of these already exists from Phase 1 per §8's design — Phase 2 branches are additive UI plus a small number of Phase-2-only entities.

### Branch: `manufacturing`
- `ProductionOrder` (with `warehouse_id`), `QualityCheck` (polymorphic GRN/ProductionOrder), BOM consumption/output posting, wastage variance against `tolerance_pct` with `bom_variance_exceeded` notification.

### Branch: `labour-resourcing`
- `Resource`, `ResourceAssignment` (`scheduled`/`active`/`completed`/`cancelled`, date-driven automatic transitions with manual override), Timesheet integration point for Phase 2's HR module.

### Branch: `projects-milestones-ui`
- `Project` (with the `cancelled` terminal state and its documented obligation-handling — open subcontracts, retention, unbilled milestones all get an explicit resolution path, not left in limbo), `Milestone` (`boq_line_allocations`, `billing_locked`, `rework_required` for post-signoff client rejection), `VariationOrder`/`VariationOrderLine`/`VariationOrderReversal` (reusing `ApprovalLimit`, row-locked BOQ-line mutation on approval), `Defect` (`blocks_retention`, `subcontract_id`), full Project state machine including `defects_liability`, the scheduled-flag/manual-confirmation DLP transition, and optimistic locking between the DLP-eligibility job and a manual project-closure action.
- Project completion percentage per the `invoice_policy`-gated formula in §3.7.

### Branch: `fixed-assets-plant`
Scheduled early in Phase 2 per §8 — affects the Balance Sheet directly, shouldn't drift to the end.
- `Asset`, `AssetComponent`, `AssetDepreciationEntry`, `AssetRevaluation` (delta-based postings, stored `net_book_value_at_revaluation`), `AssetDisposal` (stored `net_book_value_at_disposal`), `AssetAssignment` (`internal_daily_rate`, separate analytic-tagged posting from depreciation), `EquipmentHireContract` (`hire_invoice_mismatch` notification).
- **Fixed Asset Register and Depreciation Schedule reports (§10.1)** ship with this module, not after it — an auditor asking for the asset register is exactly as certain as asking for the Trial Balance.
- Monthly depreciation scheduled job.
- **Exit criterion:** an asset can be acquired, depreciated monthly, assigned to a project (internal charge landing in project P&L, not depreciation itself), revalued, and disposed — all matching `ledger-core`'s verified postings.

### Branch: `hr-payroll`
Scheduled early in Phase 2, same treatment as Fixed Assets, per §8.
- `Employee` (non-overlapping `EmploymentContract` enforcement), `EmploymentContract`, `Timesheet` (overtime split), `PublicHoliday`, `LeaveType`/`LeaveRequest`, `StatutoryDeductionRate` (`employer_rate_bands` — NSSF and Housing Levy only, **not SHIF**, per current Kenyan law), `PayrollRun`, `Payslip` (`taxable_pay`, `personal_relief_applied`, `days_paid_not_worked`, `employer_nssf_amount`/`employer_housing_levy_amount`, and a mid-period casual-to-permanent conversion splitting into two `Payslip` rows), `P9A`/`P10`, `StatutoryRemittance`, `FinalSettlement`/`LeaveEncashment` for mid-month termination.
- Casual-to-permanent conversion tracking (`cumulative_casual_days_worked`, `casual_conversion_due` notification, conversion effective the day after the threshold is crossed).
- **Exit criterion:** a full payroll cycle — run, approve, post, disburse net pay, remit statutory payables — nets every payable to zero; a mid-month termination produces a correct prorated `FinalSettlement`; a casual-to-permanent conversion crossing mid-period produces two correctly-computed `Payslip` rows. **Gross pay splits into one `JournalLine` per `AnalyticAccount` a Timesheet touched that period, verified by a test with an employee working on two projects in the same run** — the reference file's single-`analyticAccountId` shape is a documented simplification (its own docstring flags it), not a spec to port as-is, and until now nothing actually required the real split to be built. This is the one item from that flag with no enforcement anywhere until this line.

### Branch: `approval-notification-compliance`
- `ApprovalLimit` UI (with the same-user maker-checker check — a second approval must come from a different user, not just a different role held by the same person), `Notification` (full type enum, default channel policy, digest batching for lower-priority types), `ComplianceDocument` (blocking at Progress Claim certification, reading `Subcontract.required_document_types`, inclusive expiry-date boundary), `BankAccount` reconciliation UI beyond the Phase 1 minimal entity, Site Supervisor GRN-creation permission (site staff are routinely the ones physically receiving materials — confirm this matches the actual client workflow or configure the warehouse-handshake alternative explicitly).

---

## Phase 3 — Integrations & Advanced Features

Each of these is additive and doesn't touch the Phase 1/2 core schema:
- Live inventory board (materialized view / read replica over `stock_ledger`, per the Two-Year Outlook's data-volume note).
- **PWA offline for Site Supervisors** — until this ships, the interim path is structured SMS/WhatsApp templates via the existing TalkSasa infrastructure (GRN lines, Defects, QC results, Timesheets as fixed-format messages parsed into staging records, same confirm-before-live pattern as `BOQImportStaging`), not "write it on paper."
- M-Pesa STK Push/API integration (fields already exist on `Payment` from Phase 1).
- KRA iTax/TIMS/eTIMS filing submission (fields already exist on `Invoice`/`CreditNote` from Phase 1).
- KRA/NSSF/SHIF/HELB statutory online filing API (the manual `StatutoryRemittance` recording from Phase 2 stays as the fallback/audit trail either way).

---

## Cross-Cutting Reminders for Every Branch

- **§12's item #9 (payroll data visibility) is already resolved in the architecture doc** — Finance gets aggregate `JournalLine` access only, never individual `Payslip` records. Build `hr-payroll`'s Policy classes against that resolution directly; nothing left to decide here.
- Every branch that touches a financial entity gets its posting logic tested against `ledger-core`'s `LedgerPostingServiceTest.php` patterns before merge — one test per new posting type, using `assertEqualsWithDelta` for every monetary comparison, never exact float equality.
- Every branch introducing a concurrent-access risk (credit limits, VO/BOQ mutation, Milestone sign-off, stock reservation, DLP-eligibility vs. manual project closure) needs its row-locking or optimistic-locking behavior tested with an actual concurrent-request simulation, not just asserted in a comment — these are exactly the class of bug that looks fine in a single-user test and breaks the first time two people use the system at once.
- No screen ships with technical/ledger language visible — check against §9's naming rule before any PR is considered done.
- User training material (short walkthroughs for the top daily workflows) is a Phase 1 deliverable owned by whoever runs client onboarding — track it, but it's not a schema or process decision for any branch above to resolve.

**Two more Phase 1 deliverables — not runbooks (§1.1 correctly keeps those out of this document), but concrete validation work that needs to happen before the first real client, not be assumed to just work:**

- **Migration rehearsal.** Run one full `OpeningBalanceBatch` (§3.9) against a deliberately messy *simulated* client — mid-fiscal-year, partially-depreciated assets, open retention on an in-progress project, YTD payslips for P9A continuity — end to end, twice. The second run restores from a backup taken partway through the first and exercises the reconcile-and-re-post procedure §1.1 describes for the restore-vs-reversal-policy interaction. This procedure is currently only described in prose; it's untested until someone actually runs it, and the first time it runs shouldn't be against a real client's real data.
- **Load/performance validation.** §1.1's targets (p95 <300ms reads, <800ms writes, <2s reports) are numbers to measure against, not numbers already known to hold. Seed a tenant at a representative data volume (a few years of transactions for a mid-size contractor — thousands of `JournalLine` rows, not dozens) and measure against the actual targets once, before the first client, rather than discovering the real numbers from a live complaint.
