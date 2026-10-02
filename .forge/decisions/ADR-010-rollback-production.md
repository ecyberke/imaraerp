# ADR-010 — Rollback plan: production
Status: accepted 2026-10-02, approved by Lavyd — depends on the hosting decision (ASM-005, TASK-159); completed and rehearsed on staging under TASK-196.
- **Trigger:** production smoke check fails (GET /up, DB connectivity, auth smoke, critical-journey smoke), error rate above 1% for 10 minutes, or any ledger imbalance alert.
- **Command:** redeploy the previous artifact. v1.1.0 migrations are expand-only (TASK-156 lint), so the previous artifact runs on the new schema; contract steps ship in a later release.
- **Data handling:** postings made after deploy stay valid (append-only ledger); no restore. Restore-from-backup is reserved for data loss and follows the reconcile-and-repost procedure (ACC-001), never used as rollback.
- **Feature flags:** M-Pesa/eTIMS can be disabled per tenant instantly (ADR-006) without a redeploy.
- **Owner:** named at TASK-196; deployment remains the user's action, not Forge's.
- **Rehearsal:** the staging rehearsal in ADR-009 exercises the same command.
