# IRSI Billing Core v1 — Domain Foundation

## Overview

Billing Core v1 is the domain foundation for the ИРСИ payment system. It establishes canonical data models for subscriptions, billing cycles, payment attempts, payment methods, and provider events — alongside the existing legacy `subscriptions` table.

This is **not** a replacement for the current product runtime. It is a shadow/read-only projection that will be switched over in a future cutover phase.

---

## Architectural Decisions

### Billing Ownership

**Workspace-owned.** A billing account belongs to a `Workspace`, not a `User`. The user/owner initiates billing management, but the canonical billing entity is the workspace.

### Money Convention

All monetary values are stored as **integer rubles** (not kopecks). Example: `490` = 490₽. This matches the existing `tariff_plans.price_monthly` and `subscriptions.amount_paid` convention.

### Legacy Projection

Legacy `subscriptions` rows are projected by billing period. Multiple rows for the same period become `PaymentAttempt` records of one `BillingCycle`.

---

## Schema

### Tables Created (A1.1)

| Table | Purpose |
|---|---|
| `plan_prices` | Versioned price catalog per plan × period |
| `billing_subscriptions` | One canonical subscription per workspace + paid plan |
| `billing_cycles` | Billing periods within a subscription |
| `payment_attempts` | Individual payment attempts within a cycle |
| `payment_methods` | Stored payment instruments per workspace |
| `provider_events` | Inbound webhook events from providers |

### Enums

| Enum | Values |
|---|---|
| `BillingSubscriptionStatus` | `pending_initial`, `active`, `past_due`, `expired`, `canceled` |
| `BillingCycleStatus` | `pending`, `paid`, `failed`, `expired`, `canceled`, `refunded` |
| `BillingCycleOrigin` | `payment`, `renewal`, `admin_grant`, `legacy_grant` |
| `PaymentAttemptStatus` | `created`, `processing`, `succeeded`, `failed_retryable`, `failed_terminal`, `unknown`, `refunded`, `partially_refunded` |

---

## Key Relationships

```
Workspace
  └── BillingSubscription (one per paid plan)
        └── BillingCycle (one per billing period)
              └── PaymentAttempt (one per payment try)
              └── PlanPrice (versioned price snapshot)
```

---

## Legacy Projection Algorithm

The `billing:project-legacy` Artisan command projects legacy `subscriptions` rows into the new domain models.

### Grouping

Legacy rows are grouped by:
- `workspace_id`
- `tariff_plan_id`
- `starts_at`
- `expires_at`

Each group becomes one `BillingCycle`.

### Canonical Subscription

One `BillingSubscription` is created per workspace + paid plan combination. The Start (free) plan is excluded.

### Payment Attempts

Each legacy row with a `payment_id` becomes a `PaymentAttempt` within its corresponding `BillingCycle`.

**Numbering**: Within each `BillingCycle`, payment attempts are numbered `1..N` deterministically:
1. Filter to legacy rows with `payment_id`
2. Sort by `created_at ASC`, then `id ASC` as stable tie-breaker
3. Assign sequential `attempt_number = 1..N`

**Failure mapping**: Legacy `failed` status maps to `PaymentAttemptStatus::Unknown` (not `failed_terminal`), because legacy data lacks `failure_code`/`failure_category`/`failure_message` needed to classify retryability. Metadata includes `legacy: true` and `failure_source: legacy_unknown`.

**Idempotency key**: `internal_order_id = legacy payment_id`. The `attempt_number` is a sequence within the cycle, not an idempotency key. Re-projection with renumbering does not create duplicates.

**Sequence invariant**: `UNIQUE(billing_cycle_id, attempt_number)` enforced at the database level. One `BillingCycle` cannot have two `PaymentAttempt` records with the same number. Cross-cycle numbers are independent (cycle A #1 and cycle B #1 are both allowed). The two-phase repair numbering (temp negatives → final `1..N`) is compatible with this constraint.

### Legacy Grant Semantics

Zero-amount admin grants (`amount_paid=0`, `payment_id=null`) project as:
- `BillingCycle.origin = legacy_grant`
- `BillingCycle.status = paid` (meaning entitlement-providing, not payment proven)
- `BillingCycle.amount = 0`
- Zero `PaymentAttempt` records

### Failed Billing Cycle

A `BillingCycle` with only failed attempts has `BillingCycleStatus::Failed`. This is **not** a terminal state — it means no attempt succeeded in this period. Future retries are not blocked; A1.2 will define explicit retry transitions.

### Entitlement Horizon

The canonical subscription's `current_period_end` is calculated from the continuous chain of successful/grant periods. Failed periods do not extend the entitlement.

### Idempotency

The projection uses `firstOrCreate` for all entities. Running the command twice produces no duplicates. Attempt numbering is recomputed from legacy rows each run.

---

## Production Data Projection

### Expected Legacy Rows (example)

| Period | amount_paid | status | payment_id | Projected |
|---|---|---|---|---|
| 2026-07-21 → 2027-07-21 | 0 | active | null | 1 BillingCycle (legacy_grant), 0 PaymentAttempts |
| 2027-07-21 → 2027-08-21 | 490 | active | mock_... | 1 BillingCycle (payment), 1 PaymentAttempt (succeeded) |
| 2027-08-21 → 2027-09-21 | 490 | failed | mock_... | 1 BillingCycle (failed), 3 PaymentAttempts (unknown, numbered 1/2/3) |

### Entitlement Horizon

For the example above, `current_period_end` = `2027-08-21` (the end of the last successful period). The failed period does not extend entitlement.

---

## PlanAccessService

A thin facade over the existing entitlement system:

- `currentPlanCode(Workspace)` → `'start'` or `'pro'`
- `hasPro(Workspace)` → `bool`
- `hasFeature(Workspace, string)` → `bool`
- `getMonthlyLimit(Workspace)` → `?int`
- `getMaxMasters(Workspace)` → `int`

In A1.1, this delegates to `Workspace::activeSubscription()`. Future phases will switch it to read from `billing_subscriptions`.

---

## What A1.1 Does NOT Change

- Legacy `subscriptions` table (untouched)
- `Workspace::activeSubscription()` (untouched)
- Current entitlement system
- Frontend billing UX
- Admin extend subscription
- Appointment/Client/Booking models
- TariffLimitService
- CheckSubscriptionExpirations command
- CleanupPendingSubscriptions command
- PaymentWebhookController
- No provider integrations (T-Bank, YooKassa)

---

## Migration Path

1. **A1.1** (current): Domain foundation + shadow projection
2. **A1.1.1a**: Legacy projection fix-up — true dry-run, deterministic numbering, failure mapping, repair command
3. **A1.1.1b**: Unique constraint `(billing_cycle_id, attempt_number)` migration (after production repair)
4. **A1.2**: Wire new billing into select controllers/middleware
5. **A1.3**: Cutover — switch product runtime to new models
6. **A2**: Provider integration (T-Bank, YooKassa)

---

## A1.1.1a — Legacy Projection Fix-up

### True Dry-Run Guarantee

`billing:project-legacy --dry-run` performs **zero DB writes**. It computes the projection plan via read-only existence checks. No `create()`, `firstOrCreate()`, `update()`, `delete()`, or any other mutation. No transaction/rollback trickery.

### Projection Metrics

Output is split into three sections:

- **FOUND**: What exists in legacy (workspaces, period groups, payment rows, grants)
- **TO CREATE**: What's missing in billing core (canonical subs, cycles, attempts)
- **ALREADY PROJECTED**: What already exists in billing core

After a completed projection, `TO CREATE` shows zeros while `FOUND` still shows legacy counts.

### Ambiguity Detection

Groups are flagged as ambiguous (and skipped) when:
- More than one `active`/successful legacy row for the same period
- Different non-zero `amount_paid` values within one period group
- Invalid period boundaries

`failed + succeeded` in one period is **not** ambiguous (normal retry→success). Multiple `failed` rows in one period is **not** ambiguous (multiple payment attempts).

### Repair Command

`billing:repair-legacy-projection` fixes already-projected production rows:

- Renumbers payment attempts to sequential `1..N` within each cycle
- Maps `failed_terminal` legacy attempts to `unknown` (when no failure classification exists)
- Normalizes metadata for legacy attempts
- Supports `--dry-run`
- Operates only on rows with `metadata.legacy = true`
- Two-phase renumbering: uses temp numbers to avoid conflicts with future unique constraint
- Idempotent: second run shows 0 changes

### `billing_cycles.legacy_subscription_id`

This singular field exists but is **not** sufficient for one-to-many lineage (one `BillingCycle` may correspond to multiple legacy `subscriptions` rows). Do not rely on it for A1.2. Not deleted in A1.1.1a.

---

## A1.2 Entitlement Parity Semantics

### Entitlement Reader

`EntitlementService` is a **read-only** Core entitlement reader. It determines workspace entitlement from `BillingCycle` + `PaymentAttempt` facts, NOT from `BillingSubscription.status` or `current_period_start/end`.

### Granting Rules

| Origin | Granting Condition |
|---|---|
| `legacy_grant`, `admin_grant` | `cycle.status = paid` |
| `payment`, `renewal` | At least one `PaymentAttempt.status = succeeded` |
| refunded | Never grants |

### Timing

- `period_start` is **intentionally NOT gated** during parity cutover (matches legacy behavior).
- `period_end > $at` is **strict** (at exactly `period_end`, entitlement does NOT hold).
- `BillingSubscription.status` and `current_period_start/end` are NOT entitlement authority.

### Runtime

Runtime entitlement (`Workspace::activeSubscription()`, `hasFeature()`, `maxMasters()`, `PlanAccessService`) remains legacy-authoritative. `EntitlementService` is available for parity verification only. Flip `BILLING_CORE_ENTITLEMENT=true` after parity passes.

### Parity Verifier

`billing:verify-entitlement` — read-only Artisan command comparing legacy vs Core entitlement per workspace. Zero writes. Exit code 1 on mismatch.
