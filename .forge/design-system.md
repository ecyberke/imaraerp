<!-- forge:begin -->
# Imara ERP — Design System (declared for v1.1.0)

**Source:** supplied, not invented. Imara's UI is built on the Materialize Vue/Vuetify admin template
(javascript-version) per architecture §1 and §9. v1.1.0 does **not** repaint the product; it declares what
exists so new UI work (TASK-174, TASK-182, TASK-183, TASK-187) follows it instead of drifting.
Values marked *verify* must be checked against `themeConfig.js` / the Vuetify theme plugin during TASK-102
and corrected here if the repo differs.

## Principles
1. **Construction language, never ledger language.** No `JournalLine`, `analytic_account_id`, raw enum values or
   table names on any screen (architecture §9). "Bill from BOQ", "Certified quantity", "Retention held", not
   "post AR".
2. **Dense, scannable, desk-first for office roles; thumb-first for Site Supervisors.** Finance and Procurement live
   in tables and forms all day; site staff use a phone in sunlight on a slow connection.
3. **Money and status are always unambiguous.** Amounts right-aligned, tabular figures, `KES 1,234,567.00`;
   negative amounts in parentheses, never colour alone.

## Palette (template defaults — *verify*)
| Role | Token | Hex |
| --- | --- | --- |
| Primary action / active nav | `primary` | `#666CFF` |
| Secondary text, neutral chips | `secondary` | `#6D788D` |
| Success / settled / approved | `success` | `#72E128` |
| Warning / pending approval / near expiry | `warning` | `#FDB528` |
| Error / blocked / overdue | `error` | `#FF4D49` |
| Info / informational banners | `info` | `#26C6F9` |
Status is always conveyed by **label + colour**, never colour alone (accessibility, REQ-043). Text on these
backgrounds must pass WCAG AA (4.5:1); `success`/`warning`/`info` fills use dark text.

## Type
Template typeface (Inter, *verify*). Scale: page title 24/32 600; section 18/28 600; body 14/22 400;
table 13/20 400 with `font-variant-numeric: tabular-nums`; caption 12/16 400. No additional families.

## Layout
Vuetify 12-column grid; spacing scale 4/8/12/16/24/32 px only. Forms single-column below 960 px. Detail pages:
header (document number, status chip, primary actions) → summary card → line table → related documents
breadcrumb trail (source document and what it generated, §9). Multi-step forms never lose state on server
validation errors.

## Motion
Only Vuetify's built-in transitions for dialogs, menus and navigation drawer. No decorative animation, no
animated counters on dashboards. `prefers-reduced-motion` disables transitions.

## Voice
Verb-first buttons; the same verb everywhere and its past tense in the confirmation: **Approve → Approved**,
**Raise invoice → Invoice raised**, **Send STK request → Payment request sent**, **Confirm import → Import
confirmed**. Errors say what happened and what to do next ("Credit limit would be exceeded by KES 120,000.
Ask Finance to approve an override."). Empty states name the first action.

## Quality floor
Responsive to 360 px; visible keyboard focus on every control; labelled inputs; AA contrast; disable-on-submit
plus idempotency token on every financial mutation; dates shown in Africa/Nairobi.
<!-- forge:end -->
