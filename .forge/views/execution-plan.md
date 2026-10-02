# Execution Plan

## PLAN-001: Imara ERP v1.1.0 — land v1.0, make every gate real, harden, measure, complete, release
Architecture: ARCH-001

### Phase 0 — Land v1.0 and establish the Forge baseline
_PRs #18 -> #19 -> #20 merged in order by a human (eTIMS behind a default-off flag), constitution and ADRs approved, baseline inventory and test registry populated, README reproducible, CI running. Exit: forge validate exit 0 on main; workflow stage advanced to PLAN._

- **TASK-019** [PR #18] approval-notification-compliance — human review and merge to main (spec: SPEC-010, requirement: REQ-018, status: done)
- **TASK-020** [PR #19] mpesa-integration — human review and merge after #18 (spec: SPEC-011, requirement: REQ-020, status: done)
- **TASK-021** [PR #20] etims-integration — merge after #19, only with TASK-100 applied (spec: SPEC-013, requirement: REQ-021, status: done)
- **TASK-022** [PR #19] IntegrationCredentialService (encrypted, masked) (spec: SPEC-014, requirement: REQ-024, status: done)
- **TASK-100** Per-tenant integration feature flags; eTIMS and M-Pesa default OFF (spec: SPEC-013, requirement: REQ-045, status: done)
- **TASK-102** Brownfield baseline: complete .forge/baselines/inventory.yaml from a code read, populate domain.yaml entities from migrations, register every suite test in verification/tests.yaml (spec: SPEC-019, requirement: NFR-007, status: review)
- **TASK-103** Approve constitution (project.yaml, risk class) and ADR-001..008 (spec: SPEC-019, requirement: NFR-007, status: done)
- **TASK-104** Clean-room README, delete ExampleTest scaffolding, exact-cent assertion convention; run `forge scaffold` (never --merge) for CLAUDE.md/AGENTS.md marker blocks plus a hand-written Imara section (spec: SPEC-019, requirement: NFR-009, status: todo)
- **TASK-105** CI workflow (GitHub Actions + PostgreSQL 16 service) running every declared dimension (spec: SPEC-019, requirement: NFR-007, status: todo)

### Phase 1 — Make the gates real
_Every R4 application-profile dimension has a command that can fail. Missing proofs added for existing behaviour: authorization matrix, payroll confidentiality, four new concurrency tests, audit coverage, MFA route sweep, cross-tenant sweep, contract tests, e2e journeys. Exit: build, typecheck, lint, unit, integration, contract, e2e green on CI; every POLICY-* and concurrency INV-* has a passing test._

- **TASK-110** Larastan level 6 with committed baseline; ESLint as error gate (spec: SPEC-019, requirement: NFR-004, status: todo)
- **TASK-111** composer audit, npm audit --audit-level=high, gitleaks in the security dimension (spec: SPEC-019, requirement: NFR-004, status: todo)
- **TASK-112** OpenAPI 3.1 generation (dedoc/scramble) and response contract tests (spec: SPEC-019, requirement: NFR-008, status: todo)
- **TASK-113** Authorization matrix test generated from domain.yaml POLICY-* (allow, deny, other-tenant 404, unauthenticated 401) (spec: SPEC-002, requirement: REQ-005, status: todo)
- **TASK-114** Payroll confidentiality: Finance denied Payslip and salary-bearing AuditLog rows (spec: SPEC-002, requirement: REQ-031, status: todo)
- **TASK-115** Playwright harness on runtime.yaml boot contract; journeys JRN-001..007 (spec: SPEC-019, requirement: NFR-007, status: todo)
- **TASK-116** Real-subprocess concurrency test for DocumentSequence (spec: SPEC-003, requirement: REQ-007, status: todo)
- **TASK-117** Real-subprocess concurrency test for simultaneous approval decisions (and fix if it races) (spec: SPEC-010, requirement: REQ-018, status: todo)
- **TASK-118** Real-subprocess concurrency test: DLP-eligibility job vs manual project close (spec: SPEC-008, requirement: REQ-015, status: todo)
- **TASK-119** Concurrent duplicate callback and concurrent idempotency-key tests (spec: SPEC-014, requirement: REQ-023, status: todo)
- **TASK-120** Architecture test: every money-bearing model has the audit observer; mass update/delete banned on them (spec: SPEC-004, requirement: REQ-022, status: todo)
- **TASK-121** Route sweep: every mutating route reachable by Finance/Admin carries the mfa middleware (spec: SPEC-001, requirement: REQ-003, status: todo)
- **TASK-122** Cross-tenant HTTP sweep over every resource route (spec: SPEC-002, requirement: REQ-004, status: todo)
- **TASK-140** Hotfix: stop returning the eTIMS cmcKey in any HTTP response; scrub secrets before persisting raw provider responses (spec: SPEC-014, requirement: REQ-024, status: todo)

### Phase 2 — Security hardening
_Close the security gaps the new gates expose: token lifecycle, rate limits, upload pipeline, M-Pesa trust model, DB-level immutability, tenant context for jobs, RLS (if ADR-003 approved), secret rotation and redaction, headers and adversarial suite. Exit: security and adversarial dimensions pass, 0 exploited._

- **TASK-124** User deactivation and role change, revoking all of the user's tokens (spec: SPEC-001, requirement: REQ-050, status: todo)
- **TASK-130** Token lifecycle: expiry, idle timeout, logout-everywhere, recovery codes, audited MFA reset, TOTP replay guard (spec: SPEC-001, requirement: REQ-033, status: todo)
- **TASK-131** Named rate limiters per tenant/user/operation class with 429 + Retry-After (spec: SPEC-016, requirement: REQ-034, status: todo)
- **TASK-132** Upload service: finfo sniffing, caps, PhpSpreadsheet hardening, private disk, UUID keys, signed URLs (spec: SPEC-012, requirement: REQ-035, status: todo)
- **TASK-133** M-Pesa trust model: per-request callback token, IP allowlist, STK Query confirmation, unique receipt constraint (spec: SPEC-011, requirement: REQ-032, status: todo)
- **TASK-134** PostgreSQL triggers blocking UPDATE/DELETE on posted journals and audit_logs; deferred balanced-entry constraint (spec: SPEC-003, requirement: REQ-036, status: todo)
- **TASK-135** TenantContext for queued jobs, scheduled commands and console; tenant-keyed cache; lint banning withoutGlobalScopes outside an allowlist (spec: SPEC-015, requirement: REQ-037, status: todo)
- **TASK-136** PostgreSQL Row-Level Security on tenant tables (SET LOCAL app.tenant_id) (spec: SPEC-015, requirement: REQ-037, status: todo)
- **TASK-137** APP_KEY rotation via APP_PREVIOUS_KEYS + credential re-encrypt command; redact secrets in logs and retained raw responses (spec: SPEC-014, requirement: REQ-024, status: todo)
- **TASK-138** Security headers (HSTS, CSP, frame-ancestors), CORS per environment, adversarial suite ADV-001..009 (spec: SPEC-002, requirement: REQ-004, status: todo)
- **TASK-139** Add missing foreign keys (sales_returns.credit_note_id, users.employee_id) after an orphaned-rows check (spec: SPEC-019, requirement: NFR-005, status: todo)

### Phase 3 — Reliability and data protection
_Outbox, circuit breakers, reconciliation, scheduler hardening, migration lint, observability, DPA tooling, hosting decision, restore drill and migration rehearsal. Exit: reliability and runtime dimensions pass; ACC-001, ACC-002, ACC-006 recorded via forge accept._

- **TASK-150** HTTP client policy: explicit timeouts, jittered backoff for idempotent calls only, circuit breakers per provider (spec: SPEC-014, requirement: REQ-041, status: todo)
- **TASK-151** Transactional outbox for every external side effect; dead-letter surfaced as Notification (spec: SPEC-014, requirement: REQ-041, status: todo)
- **TASK-152** M-Pesa reconciliation job: STK Query for pending_external older than 3 minutes, expire after 24h (spec: SPEC-011, requirement: REQ-032, status: todo)
- **TASK-153** Scheduler hardening: onOneServer, Redis lock store, runtime-budget overrun Notification (spec: SPEC-014, requirement: REQ-041, status: todo)
- **TASK-154** WAL/PITR backups, monthly restore drill, reconcile-and-repost runbook exercised (spec: SPEC-017, requirement: NFR-002, status: todo)
- **TASK-155** Migration rehearsal: messy simulated client OpeningBalanceBatch, twice, second from mid-run restore (spec: SPEC-007, requirement: REQ-012, status: todo)
- **TASK-156** Migration lint: forbid drop/rename/type-change on financial tables (spec: SPEC-019, requirement: NFR-005, status: todo)
- **TASK-157** Observability: keep /up (liveness, unauthenticated) and /api/ready (DB, queue, cache); /api/health is authenticated and not a liveness check; add metrics export and alert rules for error rate, latency, queue depth, dead letters (spec: SPEC-017, requirement: NFR-003, status: todo)
- **TASK-158** DPA tooling: employee anonymisation, tenant data export, tenant soft-delete with retention window (spec: SPEC-015, requirement: REQ-040, status: todo)
- **TASK-159** Choose Kenya/Africa hosting for DB, backups, object storage; record ADR (spec: SPEC-017, requirement: NFR-006, status: todo)

### Phase 4 — Performance and efficiency
_Measure, then fix: volume seeder, N+1 guards, index audit, load test against budgets, bundle budget, tenant-keyed report cache. Exit: performance dimension passes at ASM-010 load._

- **TASK-170** Volume seeder: 3-year mid-size contractor tenant (ASM-010 figures) (spec: SPEC-018, requirement: NFR-001, status: todo)
- **TASK-171** Query-count assertions on every list endpoint (spec: SPEC-018, requirement: NFR-001, status: todo)
- **TASK-172** FK index audit test and EXPLAIN check on top-20 queries (spec: SPEC-018, requirement: NFR-001, status: todo)
- **TASK-173** k6 load test against per-class budgets; reports over budget move to queued job + Notification (spec: SPEC-018, requirement: NFR-001, status: todo)
- **TASK-174** SPA bundle budget: route code-splitting, Vuetify tree-shaking, drop unused Materialize apps; Lighthouse mobile (spec: SPEC-018, requirement: NFR-001, status: todo)
- **TASK-175** Tenant-keyed report cache invalidated on ledger posting (spec: SPEC-018, requirement: NFR-001, status: todo)

### Phase 5 — Functional completion
_PO cancel/short-close, settle/dispute, module dashboards, accessibility. Blocked items (eTIMS submission, Daraja sandbox, employee self-service) proceed only when their external blocker clears. Exit: accessibility dimension passes; blocked tasks either done or carried to v1.2.0 by a scoped exception._

- **TASK-180** Purchase Order cancel and short-close, wired into Project.cancel() (spec: SPEC-006, requirement: REQ-038, status: todo)
- **TASK-181** Subcontract and Progress Claim settle/dispute actions (spec: SPEC-006, requirement: REQ-039, status: todo)
- **TASK-182** Dashboards for Manufacturing, Labour, Projects, Fixed Assets, HR (spec: SPEC-007, requirement: REQ-042, status: todo)
- **TASK-183** Accessibility pass on core screens; axe-core assertions inside Playwright journeys (spec: SPEC-020, requirement: REQ-043, status: todo)
- **TASK-184** Employee self-service payslip (own_only) (spec: SPEC-009, requirement: REQ-031, status: blocked)
- **TASK-185** eTIMS sales invoice, credit note and item registration via outbox (spec: SPEC-013, requirement: REQ-044, status: blocked)
- **TASK-186** Verify M-Pesa end-to-end against the real Daraja sandbox (spec: SPEC-011, requirement: REQ-020, status: blocked)
- **TASK-187** Live inventory availability board (materialised view, polled) (spec: SPEC-006, requirement: REQ-046, status: todo)

### Phase 6 — Verify, stage, approve and release v1.1.0
_Self-audit across 23 categories from real evidence; RC on the application profile -> VERIFIED; same artifact on staging -> STAGING_VERIFIED; UAT, independent security review, rehearsed rollback plans and operational handoff; human sign-off -> PRODUCTION_APPROVED; deploy and smoke -> PRODUCTION_VERIFIED. Exit: `forge release-check` exit 0 at each profile and maturity PRODUCTION_VERIFIED._

- **TASK-190** Self-audit command writing AUDIT-* for all 23 categories from real evidence (spec: SPEC-019, requirement: NFR-007, status: todo)
- **TASK-191** RC (application profile) -> VERIFIED; deploy the same artifact to staging; `forge verify --profile staging --environment staging` -> STAGING_VERIFIED (spec: SPEC-019, requirement: NFR-007, status: todo)
- **TASK-192** UAT on staging with the first client (ACC-005) and short walkthroughs for daily workflows (spec: SPEC-020, requirement: REQ-047, status: todo)
- **TASK-193** Operational handoff: runbooks for the top 3 incident classes (M-Pesa settlement drift, queue/outbox backlog, database failover/restore), alerts wired, on-call contacts recorded (spec: SPEC-017, requirement: NFR-003, status: todo)
- **TASK-194** Independent security review / penetration test of staging (ACC-008) — Forge R4 does not provide this; findings become tasks (spec: SPEC-019, requirement: NFR-004, status: todo)
- **TASK-195** Production approval (human sign-off) -> deploy -> smoke checks -> `forge verify --profile production --environment production` -> PRODUCTION_VERIFIED (spec: SPEC-019, requirement: NFR-007, status: todo)
- **TASK-196** Rollback plans ADR-009 (staging) and ADR-010 (production) completed and rehearsed on staging (spec: SPEC-017, requirement: NFR-002, status: todo)

### Notes
Baseline tasks TASK-001..018 are done and listed in no phase. Phases 2-4 can overlap once Phase 1 exits, but every task respects depends_on (forge validate rejects out-of-order starts). Nothing merges autonomously: each task is a branch and PR reviewed by a human, as in v1.0. Tasks marked blocked wait on external human actions (KRA OSCU registration, Daraja credentials, role decision) and never block the release by themselves — if still blocked at Phase 6 they move to v1.2.0 via an RC-scoped exception with an expiry, never a project-scoped one (R4). Maturity for this brownfield baseline starts at PLANNED. PLANNED -> SPECIFIED and SPECIFIED -> IMPLEMENTED are human gates in Forge (no profile): take the first when TASK-103 approves the constitution and ADRs, the second when every non-blocked Phase 1-5 task is done. IMPLEMENTED -> VERIFIED, -> STAGING_VERIFIED, -> PRODUCTION_APPROVED and PRODUCTION_DEPLOYED -> PRODUCTION_VERIFIED are gate-enforced; PRODUCTION_APPROVED -> PRODUCTION_DEPLOYED is a third human gate. Human gates are passed by the owner editing state.yaml then running forge state reset-integrity, with the approval recorded in the commit message. R4 adds no independent security review or enforced segregation of duties — TASK-194 supplies the first by hand.
