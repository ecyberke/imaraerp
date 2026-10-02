# ADR-009 — Rollback plan: staging
Status: accepted 2026-10-02, approved by Lavyd — completed and rehearsed under TASK-196.
- **Trigger:** any staging-profile gate (build, e2e, runtime) failing after deploy, or a P1 found in UAT/pen test that blocks testing.
- **Command:** redeploy the previous release artifact (tag `v1.1.0-rc.N-1`) through the same pipeline; `php artisan migrate:status` must show no pending down-only migrations.
- **Data handling:** staging data is disposable; reseed from the reference-tenant seeder if a migration's expand step left partial backfill.
- **Owner:** Shine Web release engineer on duty (name recorded at TASK-196).
- **Rehearsal:** performed once on staging before production approval; result linked from the RC.
