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

### Entitlement Horizon

The canonical subscription's `current_period_end` is calculated from the continuous chain of successful/grant periods. Failed periods do not extend the entitlement.

### Idempotency

The projection uses `firstOrCreate` for all entities. Running the command twice produces no duplicates.

---

## Production Data Projection

### Expected Legacy Rows (example)

| Period | amount_paid | status | payment_id | Projected |
|---|---|---|---|---|
| 2026-07-21 → 2027-07-21 | 0 | active | null | 1 BillingCycle (legacy_grant), 0 PaymentAttempts |
| 2027-07-21 → 2027-08-21 | 490 | active | mock_... | 1 BillingCycle (payment), 1 PaymentAttempt (succeeded) |
| 2027-08-21 → 2027-09-21 | 490 | failed | mock_... | 1 BillingCycle (failed), 3 PaymentAttempts (failed_terminal) |

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
2. **A1.2**: Wire new billing into select controllers/middleware
3. **A1.3**: Cutover — switch product runtime to new models
4. **A2**: Provider integration (T-Bank, YooKassa)
