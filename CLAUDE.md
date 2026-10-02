<!-- forge:begin -->
## Forge

This project uses the ai-webapp-forge skill. Artifacts live in `.forge/`.

- Detected stack: unknown / typescript
- Database: unknown
- Run `forge validate` before claiming verification.
- Never claim `VERIFIED` without evidence in `.forge/evidence/`.
<!-- forge:end -->
# Imara ERP — instructions for AI coding agents

<!-- Everything below the Forge block is project-owned. `forge scaffold` only rewrites the
     block between the forge markers above, so edits here survive it. Note the block's
     "unknown / javascript" line: Forge cannot detect Laravel or Vue — the real stack is below. -->

Multi-tenant construction ERP for Kenyan contractors: CRM/Sales, Procurement, Inventory,
Manufacturing, Labour & Plant, Projects (milestones, VOs, defects, retention), Finance on a real
double-entry ledger, Fixed Assets, HR & Payroll (PAYE, NSSF, SHIF, Housing Levy), M-Pesa, KRA eTIMS.

## Stack
Laravel 12 / PHP 8.2 · PostgreSQL (required) · Sanctum bearer tokens · TOTP MFA (pragmarx/google2fa)
· Vue 3 + Vuetify (Materialize template, javascript-version) · unplugin-vue-router · CASL · database queue.

## Sources of truth — read before changing behaviour
- `Imara-ERP-ARCHITECTURE.md` (v21) — domain model, posting rules (§7), state machines, Kenyan compliance. Authoritative for the domain.
- `.forge/` — requirements, specs, tasks, invariants, authorization policies (POLICY-*), threats, tests, gates. Authoritative for what is being built and how it is proven.
- `Imara-ERP-v1.1.0-ARCHITECTURE.md`, `Imara-ERP-v1.1.0-EXECUTION-PLAN.md` — the current release's design and order of work.
- `.forge/views/ai-build-prompt.md` — the generated build handoff. Regenerate with `forge render all`; never edit views.
- Do NOT follow `README.md` until TASK-104 replaces its Vite template boilerplate.

## Commands
```bash
composer install && cp .env.example .env && php artisan key:generate   # set DB_CONNECTION=pgsql + real DB creds
php artisan migrate
npm install && npm run dev            # npm run build for production
php artisan test                      # full suite
php artisan test --filter=ClassName   # one file
vendor/bin/pint --test                # PHP style check (drop --test to fix)
npm run lint                          # ESLint (auto-fixes)
forge validate --strict               # must exit 0 before any PR is ready
```

## Non-negotiable rules
1. **Money is integer cents with bcmath (`App\Support\Money`).** Never a float, anywhere. Money assertions use exact equality on cents — a delta tolerance is verification tampering (ADR-008).
2. **PostgreSQL only.** Correctness depends on `SELECT … FOR UPDATE` and advisory locks; do not make code "database-agnostic".
3. **Tenancy:** every tenant-owned model uses `TenantScope`. Other-tenant access returns **404, never 403**. Code outside HTTP (jobs, scheduler, console) must run in an explicit tenant context (TASK-135). Do not add `withoutGlobalScopes()`.
4. **Ledger:** only `LedgerPostingService` writes journal entries. Posted entries are never edited or deleted — correct with `reverse()`. Document numbers come only from `DocumentSequenceService`.
5. **Locked operations stay locked:** stock check-and-reserve is one locked transaction (`StockAvailabilityService`), never check-then-write. Approvals go through `ApprovalLimitService`, which fails closed when unconfigured; the second approver must be a different user.
6. **Auth:** Sanctum is bearer-only — never call `->statefulApi()`. Finance and Admin mutating routes require the `mfa` middleware. Laravel Policies are the only authorization; CASL only hides or shows UI.
7. **Secrets:** per-tenant provider credentials (Daraja, eTIMS) live encrypted via `IntegrationCredentialService`, entered through Settings — never in `.env`, code, logs or stored raw responses.
8. **Webhooks** are unauthenticated by necessity and must be idempotent; a replayed callback can never create a second financial record. M-Pesa settles only after provider confirmation (ADR-002, once approved).
9. **Audit:** every money-bearing model carries the audit observer; no mass `update()`/`delete()` on financial models.
10. **Migrations on financial tables are additive only** (expand/contract). Ask before any migration that drops, renames or changes a column.
11. **UI:** construction language on screen, never ledger or table names (v21 §9). Follow `.forge/design-system.md`. Status = label + colour, never colour alone.

## Testing traps (each has caused real debugging time)
- **Real-subprocess concurrency tests** (`*ConcurrencyTest.php`) set `$connectionsToTransact = []` and clean up manually in `tearDown()` in foreign-key order. Adding an FK to a table they touch means updating that cleanup, or a later unrelated test fails from leaked rows.
- **Sanctum guard caching:** in a test that authenticates as more than one user, call `$this->app['auth']->forgetGuards();` before each request that needs the new identity — and again after any Eloquent query on a tenant-scoped model in between.
- Never weaken an assertion, skip a test, widen a timeout or mock the thing under test to get green. Fix the code or raise the disagreement.

## Working under Forge
- **Resume each session:** `forge sync` → `forge status --json` → `forge validate` → `forge next`.
- **Every change belongs to a `TASK-*`** in `.forge/tasks.yaml`, linked to a SPEC and REQ. Update the `.forge` artifacts your change affects, then `forge validate --strict` and `forge render all`. Use `forge impact <ID>` before changing a requirement, spec or invariant.
- **Never write** `.forge/state.yaml`, `manifest.yaml`, `ownership.yaml`, `evidence/`, `release-candidates/` or `views/`. `project.yaml` and `.forge/decisions/` are the owner's — propose changes, don't make them.
- **Human gates are the owner's:** PLANNED→SPECIFIED, SPECIFIED→IMPLEMENTED, PRODUCTION_APPROVED→PRODUCTION_DEPLOYED. Never edit state or run `forge state reset-integrity` to pass them.
- **Never run `forge scaffold --merge`** — it overwrites `.gitignore` and `.env.example`. Plain `forge scaffold` is safe.
- **Ask before deciding** anything about payments, auth model, tenancy, destructive operations, compliance or production deployment. Open questions are `requires_confirmation` entries in `.forge/assumptions.yaml`.
- **Report with evidence:** workflow stage and maturity, the command run and its exit code, evidence paths, next action from `forge next`. Never say "it works", "it's secure" or "it's done" without them.

## Git
One branch per task (or tight task group), PR before merge. **Never merge — a human reviews and merges every PR.** Stacked branches target their predecessor until the chain is ready.
