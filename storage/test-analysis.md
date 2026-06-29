# Test Run Analysis — 2026-06-28

**Total: 88 tests — 59 passed, 29 failed (165 assertions)**

---

## ALL 29 FAILURES — Test Name + Root Cause + Tag

### AuthenticationTest (2 failed)

| # | Test | Error | Tag |
|---|------|-------|-----|
| 1 | `login screen can be rendered` | Expected 200, got **302** (login route redirects to auth.choose) | [LOGIN] |
| 2 | `users can authenticate using the login screen` | **Route [dashboard] not defined** | [DASHBOARD] |

### EmailVerificationTest (4 failed)

| # | Test | Error | Tag |
|---|------|-------|-----|
| 3 | `email verification screen can be rendered` | **ViteException: Unable to locate file in Vite manifest: auth/verify-email.tsx** | [LOGIN] |
| 4 | `email can be verified` | **Route [dashboard] not defined** | [DASHBOARD] |
| 5 | `verified user is redirected to dashboard from verification prompt` | **Route [dashboard] not defined** | [DASHBOARD] |
| 6 | `already verified user visiting verification link is redirected without firing event again` | **Route [dashboard] not defined** | [DASHBOARD] |

### PasswordConfirmationTest (1 failed)

| # | Test | Error | Tag |
|---|------|-------|-----|
| 7 | `confirm password screen can be rendered` | **ViteException: Unable to locate file in Vite manifest: auth/confirm-password.tsx** | [LOGIN] |

### PasswordResetTest (2 failed)

| # | Test | Error | Tag |
|---|------|-------|-----|
| 8 | `reset password link screen can be rendered` | **ViteException: Unable to locate file in Vite manifest: auth/forgot-password.tsx** | [LOGIN] |
| 9 | `reset password screen can be rendered` | **ViteException: Unable to locate file in Vite manifest: auth/reset-password.tsx** | [LOGIN] |

### RegistrationTest (2 failed)

| # | Test | Error | Tag |
|---|------|-------|-----|
| 10 | `registration screen can be rendered` | **ViteException: Unable to locate file in Vite manifest: auth/register.tsx** | [LOGIN] |
| 11 | `new users can register` | **Route [dashboard] not defined** | [DASHBOARD] |

### TwoFactorChallengeTest (1 failed)

| # | Test | Error | Tag |
|---|------|-------|-----|
| 12 | `two factor challenge can be rendered` | **ViteException: Unable to locate file in Vite manifest: auth/two-factor-challenge.tsx** | [LOGIN] |

### VerificationNotificationTest (1 failed)

| # | Test | Error | Tag |
|---|------|-------|-----|
| 13 | `does not send verification notification if email is verified` | **Route [dashboard] not defined** | [DASHBOARD] |

### DashboardTest (2 failed)

| # | Test | Error | Tag |
|---|------|-------|-----|
| 14 | `guests are redirected to the login page` | **Route [dashboard] not defined** | [DASHBOARD] |
| 15 | `authenticated users can visit the dashboard` | **Route [dashboard] not defined** | [DASHBOARD] |

### PaymentWebhookTest (3 failed)

| # | Test | Error | Tag |
|---|------|-------|-----|
| 16 | `successful payment activates subscription` | assertEquals **'pro'** expected, got **'free'** | [OTHER] |
| 17 | `failed payment does not change tariff` | assertEquals **'failed'** expected, got **'pending'** | [OTHER] |
| 18 | `yearly subscription sets correct expiry` | assertEquals **'studio'** expected, got **'free'** | [OTHER] |

### ProfileUpdateTest (5 failed)

| # | Test | Error | Tag |
|---|------|-------|-----|
| 19 | `profile page is displayed` | **Route [profile.edit] not defined** | [OTHER] |
| 20 | `profile information can be updated` | **Route [profile.update] not defined** | [OTHER] |
| 21 | `email verification status is unchanged when the email address is unchanged` | **Route [profile.update] not defined** | [OTHER] |
| 22 | `user can delete their account` | **Route [profile.destroy] not defined** | [OTHER] |
| 23 | `correct password must be provided to delete account` | **Route [profile.edit] not defined** | [OTHER] |

### SecurityTest (5 failed)

| # | Test | Error | Tag |
|---|------|-------|-----|
| 24 | `security page is displayed` | **Route [security.edit] not defined** | [OTHER] |
| 25 | `security page requires password confirmation when enabled` | **Route [security.edit] not defined** | [OTHER] |
| 26 | `security page renders without two factor when feature is disabled` | **Route [security.edit] not defined** | [OTHER] |
| 27 | `password can be updated` | **Route [security.edit] not defined** | [OTHER] |
| 28 | `correct password must be provided to update password` | **Route [security.edit] not defined** | [OTHER] |

### SuperAdminAccessTest (1 failed)

| # | Test | Error | Tag |
|---|------|-------|-----|
| 29 | `super admin can access dashboard` | **ViteException: Unable to locate file in Vite manifest: SuperAdmin/Dashboard.tsx** | [OTHER] |

---

## TAG SUMMARY

| Tag | Count | Explanation |
|-----|-------|-------------|
| **[LOGIN]** | 8 | Missing Fortify React pages: login, register, forgot-password, reset-password, verify-email, confirm-password, two-factor-challenge |
| **[DASHBOARD]** | 11 | Missing route `dashboard` — Fortify default redirect target not defined |
| **[OTHER]** | 10 | Real issues needing investigation |

### [OTHER] breakdown — the important ones:

- **PaymentWebhookTest (3):** Tests use old column names (`amount`, `period`) but Subscription model was refactored to `amount_paid`, `period_months`, `tariff_plan_id`. Tests create Subscription without `tariff_plan_id`, so `activateSubscription()` can't find the plan and skips tariff update. Root cause: **model schema changed, tests not updated.**
- **ProfileUpdateTest (5):** Missing routes `profile.edit`, `profile.update`, `profile.destroy` — ProfileController exists but routes never registered in web.php. Root cause: **controllers exist, routes missing.**
- **SecurityTest (5):** Missing route `security.edit` — SecurityController exists but routes never registered in web.php. Root cause: **controllers exist, routes missing.**
- **SuperAdminAccessTest (1):** ViteException for `SuperAdmin/Dashboard.tsx` — file exists but Vite manifest not built for test environment. Root cause: **need `npm run build` before tests, or Vite manifest stale.**

---

## 3. INDIVIDUAL TEST FILE RESULTS

| File | Result | Details |
|------|--------|---------|
| `BillingTest.php` | **PASS (7/7)** | All billing calculations, subscription creation, webhook payment — green |
| `SuperAdminAccessTest.php` | **FAIL (1/4)** | 1 fail: `super admin can access dashboard` — ViteException: `SuperAdmin/Dashboard.tsx` not in manifest |
| `SecurityFixesTest.php` | **PASS (13/13)** | Policies, webhook signatures, rate limiting — all green |
| `TimezoneTest.php` | **PASS (5/5)** | Timezone CRUD, validation — all green |
| `SecurityAndLogicBreakTest.php` | **PASS (6/6)** | Authorization, N+1, state machine, webhook security — all green |

---

## 4. PaymentWebhookTest — Exact Failures

```
FAILED: successful payment activates subscription
  Expected: 'pro'
  Actual:   'free'
  (Subscription created without tariff_plan_id → activateSubscription() skips tariff update)

FAILED: failed payment does not change tariff
  Expected: 'failed'
  Actual:   'pending'
  (parseWebhookStatus returns 'failed' but update doesn't apply — Subscription fields mismatch)

FAILED: yearly subscription sets correct expiry
  Expected: 'studio'
  Actual:   'free'
  (Same root cause — no tariff_plan_id → no tariff activation)
```
