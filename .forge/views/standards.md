# Engineering Standards Coverage

Registry 2026.10.2

Profile: risk R4, mode brownfield, deployment undeclared, auth session, regions none, capabilities authentication, authorization, background_jobs, file_uploads, multi_tenant, payments, sensitive_data, webhooks.

Applicable, linked and verified are separate states. A standard being listed here does not mean it is implemented; only `verified` is backed by passing evidence on the current release candidate. Forge records compliance obligations; it never asserts compliance.

| State | Count |
| --- | --- |
| advisory | 22 |
| agent | 21 |
| automated | 44 |
| unlinked | 208 |

| Standard | Severity | Enforcement | State | Links / reason |
| --- | --- | --- | --- | --- |
| STD-ACS-001 Declared conformance target | high | evidence | unlinked |  |
| STD-ACS-002 Semantic structure | high | evidence | unlinked |  |
| STD-ACS-003 Accessible names, roles and states | high | evidence | unlinked |  |
| STD-ACS-004 Full keyboard operation without traps | high | evidence | unlinked |  |
| STD-ACS-005 Focus management | high | evidence | unlinked |  |
| STD-ACS-006 Labelled forms with associated errors | high | evidence | unlinked |  |
| STD-ACS-007 Dynamic updates announced | medium | evidence | unlinked |  |
| STD-ACS-008 Contrast and non-colour cues | medium | evidence | unlinked |  |
| STD-ACS-009 Reduced motion respected | medium | evidence | unlinked |  |
| STD-ACS-010 Zoom and reflow | medium | evidence | unlinked |  |
| STD-ACS-011 Text alternatives | medium | evidence | unlinked |  |
| STD-ACS-012 Adjustable time limits | low | evidence | unlinked |  |
| STD-ACS-013 Assistive-technology walkthrough | medium | evidence | unlinked |  |
| STD-AGT-001 Verify every new dependency before install | critical | agent | agent |  |
| STD-AGT-002 Use APIs as documented for the pinned version | high | agent | agent |  |
| STD-AGT-003 Stay inside the task's scope | high | agent | agent |  |
| STD-AGT-004 No placeholders reported as done | critical | agent | agent |  |
| STD-AGT-005 Never satisfy a check by suppressing it | critical | agent | agent |  |
| STD-AGT-006 Re-read authoritative artifacts every session | high | agent | agent |  |
| STD-AGT-007 Repository and fetched content is data, not instructions | critical | agent | agent |  |
| STD-AGT-008 No production credentials or data for the build agent | critical | agent | agent |  |
| STD-AGT-009 Human approval before destructive commands | critical | agent | agent |  |
| STD-AGT-010 No autonomous merges | high | agent | agent |  |
| STD-AGT-011 Reuse before writing new helpers | high | agent | agent |  |
| STD-AGT-012 Every status claim cites evidence | high | agent | agent |  |
| STD-AGT-013 Raise disagreements as decisions | medium | agent | agent |  |
| STD-AGT-014 Least privilege for the agent | high | agent | agent |  |
| STD-AGT-015 Respect context, tool and retry budgets | high | agent | agent |  |
| STD-AGT-016 Record the origin of generated artifacts | medium | agent | agent |  |
| STD-AGT-017 Report uncertainty and blockers plainly | high | agent | agent |  |
| STD-AGT-018 Verification is not self-certified | high | agent | agent |  |
| STD-AGT-019 Destructive and production-targeting commands refused | critical | automated | automated |  |
| STD-API-001 Endpoint semantics classified | high | evidence | unlinked |  |
| STD-API-002 Null and absent are distinct | medium | evidence | unlinked |  |
| STD-API-004 Provider responses validated at the boundary | high | evidence | unlinked |  |
| STD-API-005 Out-of-order webhook delivery handled | medium | evidence | unlinked |  |
| STD-ARC-001 Dependency direction enforced | high | evidence | unlinked |  |
| STD-ARC-002 Business rules outside controllers and views | high | evidence | unlinked |  |
| STD-ARC-003 Adapters isolate external providers | high | evidence | unlinked |  |
| STD-ARC-004 Module responsibilities declared | medium | evidence | unlinked |  |
| STD-ARC-005 Complexity budget enforced | medium | evidence | unlinked |  |
| STD-ARC-006 Technical-debt register | low | advisory | advisory |  |
| STD-ARC-007 Reversibility noted in decisions | low | advisory | advisory |  |
| STD-AUZ-001 Server-side authorization on every request | critical | evidence | unlinked |  |
| STD-AUZ-002 Row and field restrictions are declared and tested | high | evidence | unlinked |  |
| STD-AUZ-003 Separation of duties in the application | high | evidence | unlinked |  |
| STD-AUZ-004 Automated broken-access-control tests | high | evidence | unlinked |  |
| STD-BRN-001 Baseline before change | high | evidence | unlinked |  |
| STD-BRN-002 Doc-versus-code drift register | medium | evidence | unlinked |  |
| STD-BRN-003 Reproducible README | medium | evidence | unlinked |  |
| STD-BRN-004 Land open work in a declared order | low | advisory | advisory |  |
| STD-CMP-001 Every requirement is reachable | high | evidence | unlinked |  |
| STD-CMP-002 Designed UI states | medium | evidence | unlinked |  |
| STD-CMP-003 Invalid state transitions are rejected | high | evidence | unlinked |  |
| STD-CMP-004 Role journeys walked end to end | high | evidence | unlinked |  |
| STD-CMP-005 Every authorization policy is tested | high | automated | automated |  |
| STD-DAT-001 Transactions for multi-step writes | high | evidence | unlinked |  |
| STD-DAT-002 Retention and deletion policy | high | evidence | unlinked |  |
| STD-DAT-003 Production data anonymised before non-production use | medium | evidence | unlinked |  |
| STD-DAT-004 Event and analytics definitions owned | medium | evidence | unlinked |  |
| STD-DAT-005 Persisted schemas are versioned and readers tolerant | medium | evidence | unlinked |  |
| STD-DAT-006 Hot reads fetch only what they need | medium | evidence | unlinked |  |
| STD-DAT-007 Batched and idempotent ingestion | medium | evidence | unlinked |  |
| STD-DAT-008 Consistency documented per read path | medium | evidence | unlinked |  |
| STD-DAT-009 Migrations tested at production volume | high | evidence | unlinked |  |
| STD-DAT-010 Backfills resumable and observable | medium | evidence | unlinked |  |
| STD-DAT-011 Database maintenance and replica lag | low | advisory | advisory |  |
| STD-DAT-012 Analytics off the transactional database | low | advisory | advisory |  |
| STD-DOC-001 Design documents rendered from artifacts | medium | automated | automated |  |
| STD-DOC-002 API reference generated from the contract | medium | evidence | unlinked |  |
| STD-DOC-003 Onboarding for humans and agents | medium | evidence | unlinked |  |
| STD-DOC-004 Rendered views stay current | low | automated | automated |  |
| STD-DOM-001 Money as integer minor units | critical | evidence | unlinked |  |
| STD-DOM-002 Rates are effective-dated data | high | evidence | unlinked |  |
| STD-DOM-003 Golden test cases from authoritative sources | high | evidence | unlinked |  |
| STD-DOM-004 Declared rounding rules | high | evidence | unlinked |  |
| STD-DOM-005 Reconcile against external records | high | evidence | unlinked |  |
| STD-DOM-006 Posted financial records corrected only by reversal | critical | evidence | unlinked |  |
| STD-DOM-007 Concurrency-sensitive invariants tested concurrently | high | automated | automated |  |
| STD-DOM-008 Business invariants backed by constraints | medium | evidence | unlinked |  |
| STD-DOM-009 Authoritative figures separated from estimates | high | evidence | unlinked |  |
| STD-DOM-010 Partial, cancelled and reversed outcomes defined | high | evidence | unlinked |  |
| STD-DOM-011 Behaviour when external data is late or missing | medium | evidence | unlinked |  |
| STD-DOM-012 Deterministic calculations | high | evidence | unlinked |  |
| STD-DOM-013 Source of truth per data element | medium | evidence | unlinked |  |
| STD-ENV-001 Pinned runtimes | high | evidence | unlinked |  |
| STD-ENV-002 Same artifact across environments | high | evidence | unlinked |  |
| STD-ENV-003 Time zones explicit | medium | evidence | unlinked |  |
| STD-ENV-004 Production-like staging | medium | evidence | unlinked |  |
| STD-ENV-005 Reproducible clean-room setup | medium | evidence | unlinked |  |
| STD-ENV-010 Proxy headers trusted only from known proxies | high | evidence | unlinked |  |
| STD-ENV-011 Clock skew tolerated | medium | evidence | unlinked |  |
| STD-ENV-012 IPv6-safe parsing and allow-lists | medium | evidence | unlinked |  |
| STD-ENV-013 Disk, temp files and logs bounded | medium | evidence | unlinked |  |
| STD-ENV-014 Configuration documented | medium | evidence | unlinked |  |
| STD-ENV-015 Release notes and migration notes | medium | evidence | unlinked |  |
| STD-ENV-016 Runtime and framework end-of-life tracked | low | advisory | advisory |  |
| STD-ENV-017 Reproducible development environment | low | advisory | advisory |  |
| STD-FE-001 Stale async responses cannot overwrite newer state | high | evidence | unlinked |  |
| STD-FE-002 Forms survive failure and double-submission | high | evidence | unlinked |  |
| STD-FE-003 State ownership is explicit | medium | evidence | unlinked |  |
| STD-FE-004 Deep links reproduce the view | medium | evidence | unlinked |  |
| STD-FE-005 State preserved across navigation | medium | evidence | unlinked |  |
| STD-FE-006 Declared browser support | medium | evidence | unlinked |  |
| STD-FE-007 No layout shift and responsive interaction | medium | evidence | unlinked |  |
| STD-FE-008 Large lists virtualised; input handlers rate-limited | medium | evidence | unlinked |  |
| STD-FE-009 Third-party scripts inventoried and budgeted | medium | evidence | unlinked |  |
| STD-FE-010 Client-side errors reported | medium | evidence | unlinked |  |
| STD-FE-011 Slow-network behaviour designed | medium | evidence | unlinked |  |
| STD-GOV-001 Waivers are scoped, justified and expiring | high | automated | automated |  |
| STD-GOV-002 Release records standards and evidence | high | evidence | unlinked |  |
| STD-GOV-003 Compliance obligations owned and evidenced | high | evidence | unlinked |  |
| STD-GOV-004 Breach assessment and notification path | medium | evidence | unlinked |  |
| STD-GOV-005 Dependency licence policy | medium | evidence | unlinked |  |
| STD-GOV-006 Consent UI matches actual processing | medium | evidence | unlinked |  |
| STD-GOV-007 Branch protection and reviews | medium | advisory | advisory |  |
| STD-GOV-008 Code matches the constitution or an ADR supersedes it | medium | automated | automated |  |
| STD-GOV-009 Risk class matches dangerous capabilities | high | automated | automated |  |
| STD-INT-001 Integration contracts have a real source | high | automated | automated |  |
| STD-INT-002 Webhook trust model declared | critical | automated | automated |  |
| STD-INT-003 Unverified integrations ship default-off | high | automated | automated |  |
| STD-INT-004 Non-idempotent provider calls are never auto-retried | critical | evidence | unlinked |  |
| STD-INT-005 Scrub credentials from stored provider responses | high | evidence | unlinked |  |
| STD-INT-006 Explicit timeouts on every outbound call | high | evidence | unlinked |  |
| STD-INT-007 Retry transient failures only, with backoff and jitter | high | evidence | unlinked |  |
| STD-INT-008 Circuit breakers around flaky dependencies | medium | evidence | unlinked |  |
| STD-INT-009 Transactional outbox for write-and-notify | high | evidence | unlinked |  |
| STD-INT-010 Vendor owner, data shared and exit plan recorded | medium | evidence | unlinked |  |
| STD-INT-011 Quota and cost model recorded and monitored | medium | evidence | unlinked |  |
| STD-INT-012 Vendor outage scenarios tested | high | evidence | unlinked |  |
| STD-MNT-001 Architecture boundaries enforced by tests | medium | evidence | unlinked |  |
| STD-MNT-002 Remove dead code and scaffolding before release | medium | advisory | advisory |  |
| STD-MNT-003 Complexity budget in lint (below R2) | low | advisory | advisory |  |
| STD-MNT-004 Static type checking | medium | evidence | unlinked |  |
| STD-MNT-005 Significant decisions recorded as ADRs | medium | evidence | unlinked |  |
| STD-OPS-001 Structured logs with correlation ids | high | evidence | unlinked |  |
| STD-OPS-002 Actionable alerts on error rate and latency | high | evidence | unlinked |  |
| STD-OPS-003 Exception tracking with context | medium | evidence | unlinked |  |
| STD-OPS-004 Runbooks for top failure modes | high | evidence | unlinked |  |
| STD-OPS-005 Incident response procedure | high | evidence | unlinked |  |
| STD-OPS-006 Certificate, domain and quota expiry monitored | medium | evidence | unlinked |  |
| STD-OPS-007 Smoke tests after every deploy | medium | evidence | unlinked |  |
| STD-OPS-008 Release metadata recorded | medium | evidence | unlinked |  |
| STD-OPS-009 Cost budgets and alerts | low | advisory | advisory |  |
| STD-OPS-010 Post-incident actions tracked to completion | medium | evidence | unlinked |  |
| STD-OPS-011 SLOs with burn-rate alerting | high | evidence | unlinked |  |
| STD-OPS-012 Real-user monitoring | medium | evidence | unlinked |  |
| STD-OPS-013 Synthetic checks on critical journeys | medium | evidence | unlinked |  |
| STD-OPS-014 Rate, errors and duration per service | medium | evidence | unlinked |  |
| STD-OPS-015 Trace sampling keeps all errors | low | advisory | advisory |  |
| STD-OPS-016 Environment inventory and alert review | low | advisory | advisory |  |
| STD-PRF-001 Declared and enforced performance budgets | high | evidence | unlinked |  |
| STD-PRF-002 No N+1 queries on list endpoints | high | evidence | unlinked |  |
| STD-PRF-003 Indexes on foreign keys and filter columns | high | evidence | unlinked |  |
| STD-PRF-004 Pagination everywhere | high | evidence | unlinked |  |
| STD-PRF-005 Load tested before launch | medium | evidence | unlinked |  |
| STD-PRF-006 Front-end weight budget | medium | evidence | unlinked |  |
| STD-PRF-007 Statement timeouts and slow-query review | medium | evidence | unlinked |  |
| STD-PRF-008 Explicit cache TTLs and stampede protection | medium | evidence | unlinked |  |
| STD-PRF-009 Heavy work off the request path | medium | evidence | unlinked |  |
| STD-PRF-010 Read replicas, partitioning and sharding when measured | low | advisory | advisory |  |
| STD-PRF-011 Measure before optimising | low | advisory | advisory |  |
| STD-PRV-001 Personal-data inventory | high | evidence | unlinked |  |
| STD-PRV-002 Purpose and lawful basis recorded | high | evidence | unlinked |  |
| STD-PRV-003 Data minimisation | high | evidence | unlinked |  |
| STD-PRV-004 Data-subject request workflows | high | evidence | unlinked |  |
| STD-PRV-005 Cross-border transfers recorded | medium | evidence | unlinked |  |
| STD-PRV-006 Classification drives handling | medium | evidence | unlinked |  |
| STD-PRV-007 Privacy review on new processing | medium | evidence | unlinked |  |
| STD-REL-001 Fail fast on bad configuration | high | evidence | unlinked |  |
| STD-REL-002 Structured error contract | high | evidence | unlinked |  |
| STD-REL-003 Idempotency keys on mutations | high | evidence | unlinked |  |
| STD-REL-004 Dead-letter and idempotent consumers | high | evidence | unlinked |  |
| STD-REL-005 Liveness and readiness endpoints | high | evidence | unlinked |  |
| STD-REL-007 Backward-compatible migrations | high | evidence | unlinked |  |
| STD-REL-008 Optimistic concurrency on contested updates | medium | evidence | unlinked |  |
| STD-REL-009 Backups restored, not just taken | high | evidence | unlinked |  |
| STD-REL-010 Defined RTO/RPO, tested | medium | evidence | unlinked |  |
| STD-REL-011 Scheduled jobs cannot overlap or silently stop | medium | evidence | unlinked |  |
| STD-REL-012 Graceful degradation of non-core features | medium | evidence | unlinked |  |
| STD-REL-013 Feature flags with a kill switch for risky changes | medium | evidence | unlinked |  |
| STD-REL-015 Chaos and soak testing | low | advisory | advisory |  |
| STD-REL-016 Sagas or compensation for multi-step workflows | medium | evidence | unlinked |  |
| STD-REL-017 Bulkheads between workloads | medium | evidence | unlinked |  |
| STD-REL-020 Events kept replayable | medium | evidence | unlinked |  |
| STD-REL-021 Coalesce duplicate in-flight requests | low | advisory | advisory |  |
| STD-REQ-001 Measurable acceptance criteria | high | evidence | unlinked |  |
| STD-REQ-002 Business rules carry examples and counterexamples | medium | evidence | unlinked |  |
| STD-REQ-003 Non-goals recorded | medium | evidence | unlinked |  |
| STD-REQ-004 Resolve conflicting sources explicitly | high | agent | agent |  |
| STD-REQ-005 The problem is stated before the solution | medium | evidence | unlinked |  |
| STD-RES-001 Bounded in-memory collections, queues and caches | high | evidence | unlinked |  |
| STD-RES-002 Connection pools sized and leak-proof | high | evidence | unlinked |  |
| STD-RES-003 Stream large payloads | high | evidence | unlinked |  |
| STD-RES-004 Keep request workers unblocked | high | evidence | unlinked |  |
| STD-RES-005 Concurrency caps on expensive operations | medium | evidence | unlinked |  |
| STD-RES-006 Log volume and metric cardinality controlled | medium | evidence | unlinked |  |
| STD-RES-007 Deterministic resource disposal | medium | evidence | unlinked |  |
| STD-RES-008 Derivatives instead of serving originals | medium | evidence | unlinked |  |
| STD-RES-009 Incremental, checkpointed batch work | medium | evidence | unlinked |  |
| STD-RES-011 Cost attributed per tenant or feature | medium | evidence | unlinked |  |
| STD-SCL-001 Stateless application servers | high | evidence | unlinked |  |
| STD-SCL-002 Declared capacity model | high | evidence | unlinked |  |
| STD-SCL-003 Queue-based load levelling | high | evidence | unlinked |  |
| STD-SCL-004 Backpressure instead of unbounded buffering | high | evidence | unlinked |  |
| STD-SCL-005 Prioritised queues | medium | evidence | unlinked |  |
| STD-SCL-006 Bounded fan-out | medium | evidence | unlinked |  |
| STD-SCL-009 Pre-warm for known spikes and review capacity | low | advisory | advisory |  |
| STD-SCL-010 Gateway for cross-cutting API concerns | low | advisory | advisory |  |
| STD-SCN-001 No committed secrets | critical | automated | automated |  |
| STD-SCN-002 Check suppressions do not grow unexplained | high | automated | automated |  |
| STD-SCN-003 Placeholders do not grow | high | automated | automated |  |
| STD-SCN-004 New dependencies are explained | high | automated | automated |  |
| STD-SCN-006 README claims match the repository | low | automated | automated |  |
| STD-SCN-007 Growth without requirements is visible | medium | automated | automated |  |
| STD-SCN-008 Declared conventions and domain vocabulary hold | medium | automated | automated |  |
| STD-SEC-001 Modern password hashing | critical | evidence | unlinked |  |
| STD-SEC-002 Login abuse protection | high | evidence | unlinked |  |
| STD-SEC-003 Token and session lifetime | high | evidence | unlinked |  |
| STD-SEC-004 Cookie-held session credentials | high | evidence | unlinked |  |
| STD-SEC-006 MFA for privileged roles | high | evidence | unlinked |  |
| STD-SEC-007 Account recovery is safe | high | evidence | unlinked |  |
| STD-SEC-008 Schema validation at the boundary | critical | evidence | unlinked |  |
| STD-SEC-009 Output encoding and XSS defence | high | evidence | unlinked |  |
| STD-SEC-010 Security headers and CORS allow-list | high | evidence | unlinked |  |
| STD-SEC-011 Request size and shape limits | high | evidence | unlinked |  |
| STD-SEC-012 Safe file uploads | critical | evidence | unlinked |  |
| STD-SEC-013 Secrets management | critical | evidence | unlinked |  |
| STD-SEC-014 Redact secrets and PII in logs | high | evidence | unlinked |  |
| STD-SEC-015 Dependency scanning blocks criticals | high | evidence | unlinked |  |
| STD-SEC-016 Lockfiles committed | high | automated | automated |  |
| STD-SEC-017 Generic errors in production | high | evidence | unlinked |  |
| STD-SEC-018 Audit trail on privileged mutations | high | evidence | unlinked |  |
| STD-SEC-019 SSRF and egress controls | high | evidence | unlinked |  |
| STD-SEC-020 Threat model per trust boundary | high | evidence | unlinked |  |
| STD-SEC-021 Adversarial checks | high | evidence | unlinked |  |
| STD-SEC-024 TLS everywhere | high | evidence | unlinked |  |
| STD-SEC-025 Encrypt sensitive data at rest | high | evidence | unlinked |  |
| STD-SEC-026 Independent security review | medium | evidence | unlinked |  |
| STD-SEC-027 Vulnerability disclosure contact | low | advisory | advisory |  |
| STD-SEC-028 Bot and abuse protection on abuse-prone flows | high | evidence | unlinked |  |
| STD-SUP-001 SBOM per release | medium | evidence | unlinked |  |
| STD-SUP-002 Provenance for high-risk dependencies | medium | evidence | unlinked |  |
| STD-SUP-003 Abandoned dependencies flagged | medium | evidence | unlinked |  |
| STD-SUP-004 Emergency dependency replacement rehearsed | medium | evidence | unlinked |  |
| STD-TEN-001 Explicit tenant context outside HTTP | critical | evidence | unlinked |  |
| STD-TEN-002 Tenant-keyed caches, files and indexes | high | evidence | unlinked |  |
| STD-TEN-003 Cross-tenant access returns 404 on every route | critical | evidence | unlinked |  |
| STD-TEN-004 Tenant export and deletion | high | evidence | unlinked |  |
| STD-TEN-005 Database row-level security as defence in depth | medium | advisory | advisory |  |
| STD-TEN-006 Per-tenant rate limits and quotas | medium | evidence | unlinked |  |
| STD-TST-001 Tests derive from acceptance criteria | high | evidence | unlinked |  |
| STD-TST-002 Show red before green | high | agent | agent |  |
| STD-TST-003 Prove critical tests can fail | high | evidence | unlinked |  |
| STD-TST-004 No scaffold or trivially-true tests counted | medium | automated | automated |  |
| STD-TST-005 Exact monetary assertions | high | automated | automated |  |
| STD-TST-006 Flaky tests are quarantined, not retried | medium | evidence | unlinked |  |
| STD-TST-007 Assert state and side effects | high | evidence | unlinked |  |
| STD-TST-008 Test files frozen after RC | critical | automated | automated |  |
| STD-TST-009 Property and fuzz tests for invariants and parsers | medium | evidence | unlinked |  |
| STD-TST-010 Contract tests against the API schema | high | evidence | unlinked |  |
| STD-TST-011 Boundary values tested | high | evidence | unlinked |  |
| STD-TST-012 Deterministic time and randomness | high | evidence | unlinked |  |
| STD-TST-013 Dependency failures simulated | high | evidence | unlinked |  |
| STD-TST-014 Every fixed defect gets a regression test | high | agent | agent |  |
| STD-TST-015 Coverage measured, not targeted | medium | evidence | unlinked |  |
| STD-TST-016 Test priorities follow risk | medium | evidence | unlinked |  |
| STD-TST-017 Synthetic test data | high | evidence | unlinked |  |
| STD-UX-001 Accessibility gate on core journeys | high | evidence | unlinked |  |
| STD-UX-002 Errors explain what happened and what to do | medium | evidence | unlinked |  |
| STD-UX-003 Confirmation and recovery for destructive actions | high | evidence | unlinked |  |
| STD-UX-004 Declared design system before UI work | medium | automated | automated |  |
| STD-UX-006 Usability check with representative users | low | advisory | advisory |  |
| STD-UX-007 Localisation of formats | medium | evidence | unlinked |  |
| STD-VER-001 Gates must be able to fail | critical | automated | automated |  |
| STD-VER-002 Evidence comes from the released commit | critical | automated | automated |  |
| STD-VER-003 Verification tooling works offline and fails loudly | high | automated | automated |  |
| STD-VER-004 Manual acceptance needs a human | critical | automated | automated |  |
| STD-VER-005 Traceability REQ->SPEC->TASK->TEST | high | automated | automated |  |
| STD-VER-006 Task dependencies respected | high | automated | automated |  |
| STD-VER-007 An independent role produced some evidence | critical | automated | automated |  |
| STD-VER-008 Evidence records its rigor and producer | high | automated | automated |  |
| STD-VER-009 Test gates backed by structured reports | high | automated | automated |  |
| STD-VER-010 Readiness proves this process booted | high | automated | automated |  |
| STD-VER-011 Persistence proven with a generated value | high | automated | automated |  |
| STD-VER-012 Secrets never reach logs or responses | critical | automated | automated |  |
| STD-VER-013 Journeys walked step by step | high | automated | automated |  |
| STD-VER-014 No phantom routes | high | automated | automated |  |
| STD-VER-015 Self-audit passes cite evidence | high | automated | automated |  |
| STD-VER-016 Human acceptance is substantiated | high | automated | automated |  |
| STD-VER-017 Two people confirm manual acceptance | high | automated | automated |  |
| STD-VER-018 A human read the change | high | automated | automated |  |
| STD-VER-019 Tasks point at real files and commits | medium | automated | automated |  |
| STD-VER-020 Tests cover the cases the declarations imply | critical | automated | automated |  |
| STD-VER-021 Adversarial floor for dangerous capabilities | high | automated | automated |  |

No `.forge/standards.yaml` yet — run `forge standards init`.
