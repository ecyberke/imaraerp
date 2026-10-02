# Imara ERP v1.1.0 — Claude Code prompts

Paste these into **Claude Code in VS Code**, opened at the Imara repo root. Prerequisites:
- the `ai-webapp-forge` skill is installed;
- the `forge` alias works;
- `CLAUDE.md` and `AGENTS.md` are at the repo root.

See "Local setup" in `Imara-ERP-v1.1.0-EXECUTION-PLAN.md` for all three.

There are three prompts:
- **Prompt 1** runs once, to adopt Forge and complete the baseline (Phase 0).
- **Prompt 2** is the repeatable one-task loop for everything after.
- **Prompt 3** is a status check you can run any time.

> **Why not just use `.forge/views/ai-build-prompt.md`?** That is Forge's generated build prompt (PROMPT-001), and it stays the authoritative list of rules and source artifacts. But in Forge 2.1.0 its "Implementation order" is the task IDs **sorted by number**. It ignores `depends_on`, status and the plan's phases, and it lists the 18 finished baseline tasks as if they were still to do. These prompts follow `execution-plan.yaml` instead, which `forge validate` has checked for dependency order.

---

## Prompt 1 — Phase 0: adopt Forge and complete the brownfield baseline

Use after PRs #18 → #19 → #20 are merged to `main`.

```text
Use the ai-webapp-forge skill. This is Imara ERP, an existing Laravel 12 + Vue 3 construction ERP
(brownfield). Read CLAUDE.md first and follow it throughout.

Context:
- Domain source of truth: Imara-ERP-ARCHITECTURE.md (v21). Do not change domain behaviour.
- Release plan: Imara-ERP-v1.1.0-ARCHITECTURE.md and Imara-ERP-v1.1.0-EXECUTION-PLAN.md.
- Prepared Forge artifacts: the bundle extracted at /tmp/imara-forge-v1.1.0/ (adjust path if different).

Goal: Phase 0 of PLAN-001, up to the TASK-103 human decision — nothing beyond it.

Do, in order, reporting each command and its exit code:
1. Confirm `main` contains PRs #18, #19 and #20. If not, stop and tell me.
2. Create branch `forge/baseline-v1.1.0` from main.
3. `forge init --mode brownfield`.
4. Copy /tmp/imara-forge-v1.1.0/.forge/. into .forge/ (authored sources only — never state.yaml,
   manifest.yaml, ownership.yaml, evidence/ or views/).
5. `forge capabilities resolve`, then `forge validate --strict`. This is the first run with the
   real jsonschema library. For each error, fix the SOURCE artifact so it matches the schema and the
   facts — never delete a requirement, policy, invariant or test entry to make an error go away. If
   a fix would change meaning, stop and ask me.
6. TASK-102 — complete the baseline from a real code read:
   a. `php artisan route:list --json` → record the real HTTP surface in .forge/baselines/inventory.yaml
      (including the exact M-Pesa callback path).
   b. Read the migrations → add the existing entities to .forge/domain.yaml `entities` as the schema
      actually is (fields, types, FKs, indexes). Do not invent fields.
   c. List every test file → reconcile .forge/verification/tests.yaml TEST-001..026 with the real
      paths and counts; register anything missing.
   d. Compare the POLICY-001..015 role matrix in domain.yaml against the real Policy classes.
      Do NOT edit either side. List every disagreement for me as a decision.
   e. Check each known issue KI-001..010 against the code; mark confirmed / not reproduced, with evidence.
   Then `forge validate --strict` must exit 0.
7. `forge workflow advance` until stage is PLAN, then `forge render all`.
8. `forge scaffold` — plain, NEVER --merge. Confirm CLAUDE.md and AGENTS.md still contain the Imara
   section below the Forge block.
9. Commit with message "TASK-102: Forge brownfield baseline for v1.1.0" and push the branch.
   Open a PR to main. Do NOT merge it.

Then STOP and give me:
- `forge status` and `forge next` output;
- the policy-matrix disagreements from step 6d;
- every `requires_confirmation: true` entry in .forge/assumptions.yaml, as a numbered list of
  questions with your recommendation for each. These are my TASK-103 decisions.

Rules:
- Never write .forge/state.yaml or any Forge-owned file.
- Never run `forge state reset-integrity` — human gates are mine.
- Never edit project.yaml or .forge/decisions/ — propose changes instead.
- Never modify application code or tests in this session.
- Never claim anything works without the command, exit code and evidence path.
```

---

## Prompt 2 — one task (repeat for every task after TASK-103)

Replace `TASK-XXX`. Take tasks in the order of `.forge/execution-plan.yaml`, phase by phase; within a phase, any task whose `depends_on` are all `done`.

```text
Use the ai-webapp-forge skill and follow CLAUDE.md. Resume the project:
`forge sync` → `forge status --json` → `forge validate` → `forge next`. Report the results.

Work on TASK-XXX only.
1. Read its entry in .forge/tasks.yaml, its SPEC in specs.yaml, its REQ in requirements.yaml, and
   every INV-*, POLICY-*, THR-*, ADR and test entry that references them. Run `forge impact TASK-XXX`.
2. Check every depends_on task is `done`. If not, stop and tell me which.
3. If the task is blocked by a `requires_confirmation` assumption I haven't answered, stop and ask.
   Never decide payments, auth, tenancy, destructive operations, compliance or deployment yourself.
4. Create branch `task/XXX-<short-slug>` from main. Set the task status to `in_progress`.
5. Write the test(s) first, at the paths registered in .forge/verification/tests.yaml, and show
   them failing for the right reason. Concurrency tests use real subprocesses and manual tearDown;
   multi-user tests call forgetGuards() — see CLAUDE.md.
6. Implement the smallest change that makes them pass, within the SPEC. Money in integer cents;
   PostgreSQL features allowed; ledger writes only through LedgerPostingService; financial-table
   migrations additive only.
7. Run the task's self_test, then `php artisan test`, `vendor/bin/pint --test`, `npm run lint`.
   Never weaken, skip or delete a test, widen a timeout, or mock what the test exists to exercise —
   if a test seems wrong, stop and explain why.
8. Update any .forge artifact the change affects (tests.yaml paths, domain.yaml entities, inventory
   known issues), set the task to `review`, then `forge validate --strict` (must be 0) and
   `forge render all`.
9. Commit referencing TASK-XXX, push, open a PR. Do NOT merge.

Report:
- what changed, with files;
- tests added vs modified (modified needs a reason);
- each command run with its exit code;
- anything found but not fixed, added as a known issue;
- the next eligible task(s) according to execution-plan.yaml.
Never say "done", "works" or "secure" without the evidence behind it.
```

---

## Prompt 3 — status check (any time)

```text
Use the ai-webapp-forge skill. Run `forge sync`, `forge status`, `forge next`, `forge deliverables`
and `forge validate --strict`, and report:
- workflow stage and maturity;
- tasks by status per phase of PLAN-001;
- the next eligible tasks (all depends_on done, not blocked);
- open requires_confirmation assumptions;
- any validation errors with the artifact to fix.
Change nothing.
```

---

## What these prompts deliberately leave to you

- **Merging every PR.**
- **The human maturity gates** (PLANNED→SPECIFIED, SPECIFIED→IMPLEMENTED, PRODUCTION_APPROVED→PRODUCTION_DEPLOYED). You pass each by editing `state.yaml` and running `forge state reset-integrity`; see "Passing a human gate" in the execution plan.
- **`forge accept`** for manual acceptance checks (restore drill, migration rehearsal, sandboxes, UAT, residency, pen test).
- **The TASK-103 decisions** and any later `requires_confirmation` assumption.
- **Deploying** to staging and production.
