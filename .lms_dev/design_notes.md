# Architecture & Design Notes — enrol_mentorsubscription

> **Plugin**: `enrol_mentorsubscription`
> **Moodle version**: 4.5+ (`requires = 2024100700`)
> **PHP**: 8.1+
> **Payment provider**: Stripe v19.3 (via Composer)
> **Last updated**: 2026-01

---

## 1. Plugin Type Choice

### Decision: `enrol` plugin over `local` plugin
| Option | Pros | Cons |
|--------|------|------|
| `enrol` plugin | Native course enrolment API, clean lifecycle hooks, enrol instance per course | Slightly more complex setup |
| `local` plugin | Simpler scaffold | Manual enrolment via `enrol_manual`; no native enrol lifecycle |

**Choice**: `enrol` plugin. The product core value is controlling "who can access courses", which maps perfectly to Moodle's enrolment subsystem. Using `enrol` gives us `enrol_user()` / `unenrol_user()` lifecycle for free, audit logs through Moodle's events system, and clean integration with gradebook visibility.

---

## 2. Database Schema Philosophy

### Decision: Separate `sub_types` + `subscriptions` tables (not a config-only approach)
A flat config approach (storing plan details in `config_plugins`) would couple plan changes to code deployments. Decoupling into `enrol_mentorsub_sub_types` allows admins to create/edit/deactivate plans at runtime without code changes.

### Decision: Immutable snapshot on `subscriptions`
Fields `price_charged`, `billed_max_mentees`, `billing_cycle` are **copied from the sub_type at subscription creation time** and never updated again. This ensures:
- Historical billing accuracy regardless of plan edits.
- Mentee limit is tied to what the mentor paid, not current plan defaults.

### Decision: `is_active` flag on mentees (soft delete)
Mentees are never hard-deleted from `enrol_mentorsub_mentees`. Instead `is_active` is toggled. This preserves history for the admin panel's audit log.

---

## 3. Class Namespace Structure

```
enrol_mentorsubscription\
  subscription\
    subscription_manager    — CRUD for subscriptions table
  mentorship\
    mentorship_manager      — CRUD for mentees table + limit enforcement
    enrolment_sync          — bridges mentee activation to Moodle enrol API
    role_manager            — manages parent/child context roles
  form\
    override_form           — Moodleform: admin max_mentees override
    sub_type_form           — Moodleform: CRUD subscription type
  output\
    subscription_dashboard_panel    — renderable: mentor dashboard
    admin_subscription_panel        — renderable: admin panel
    payment_history_panel           — renderable: per-mentor billing ledger
  task\
    sync_stripe_subscriptions       — scheduled task: Stripe sync + grace period
  privacy\
    provider                        — GDPR API implementation
  event\
    mentee_added                    — Moodle event
    mentee_removed                  — Moodle event
    subscription_expired            — Moodle event
  observer\
    subscription_observer           — listens to Stripe webhook events
```

---

## 4. Security Architecture

### Input Validation
- All user-supplied input enters via `required_param()` / `optional_param()` with explicit PARAM_* types.
- Moodleforms add a second layer via `definition()` type constraints and `validation()`.
- External API functions use `validate_parameters()` with explicit type specs.

### Authentication & Authorisation
| Entry point | Auth mechanism |
|-------------|----------------|
| `dashboard.php` | `require_login()` + `require_capability('enrol/mentorsubscription:subscribe', $sitecontext)` |
| `admin.php` | `require_login()` + `require_capability('enrol/mentorsubscription:manage', $sitecontext)` |
| `subscribe.php` | `require_login()` + capability check |
| `webhook.php` | `NO_MOODLE_COOKIES` (Stripe signature verification) |

### CSRF Protection
- Moodleforms include sesskey automatically.
- Non-form state-changing URLs (togglesubtype) use `require_sesskey()`.
- AJAX calls go through Moodle's external API token system — no custom sesskey needed.

### Output Encoding
- All user-originated strings passed through `format_string()` or `s()`.
- Mustache templates auto-escape `{{variable}}` (triple `{{{variable}}}` is only used for pre-sanitised renderer output).
- `html_writer` is used for any inline HTML construction.

### Stripe Webhook
- Raw payload is read once with `file_get_contents('php://input')`.
- `\Stripe\Webhook::constructEvent()` verifies HMAC-SHA256 signature using `STRIPE_WEBHOOK_SECRET`.
- Event type is validated against an explicit allowlist before any DB writes.

---

## 5. Stripe Integration Flow

```
Mentor clicks "Subscribe"
  → subscribe.php redirects to Stripe Checkout session
  → Stripe processes payment
  → Stripe sends webhook to webhook.php
    ├── checkout.session.completed → subscription_manager::create_active_subscription()
    ├── invoice.payment_succeeded  → subscription_manager::process_renewal()
    ├── invoice.payment_failed     → set status = past_due
    └── customer.subscription.deleted → subscription_manager::expire_subscription()
  → sync_stripe_subscriptions task (hourly) cross-checks Stripe API state
    └── past_due > grace_days → expire_subscription()
```

---

## 6. Grace Period Logic (M-5.7)

Setting: `pastdue_grace_days` (default: 3, configurable in plugin settings).

The scheduled task `sync_stripe_subscriptions` evaluates:
```
if status == past_due
  AND stripe API status NOT IN [canceled, unpaid]
  AND (now - subscription.timemodified) > grace_days * 86400
  THEN expire_subscription()
```

`timemodified` is updated every time the status changes, so the clock starts from the moment the webhook first flipped the subscription to `past_due`.

---

## 7. MUC (Moodle Universal Cache) Usage

Currently no MUC caches are declared. Candidates for a future cache:
- `get_active_subscription($userid)` — per-request cache to avoid repeated DB hits on pages that call it multiple times.
- `count_active_mentees($mentorid)` — used in toggle validation and dashboard display.

Cache definition location would be `db/caches.php`.

---

## 8. Event / Observer Architecture

Events dispatched by the plugin (defined in `db/events.php`):
| Event class | Triggered by | Observer |
|------------|--------------|----------|
| `mentee_added` | `mentorship_manager::add_mentee()` | — (external systems may subscribe) |
| `mentee_removed` | `toggle_mentee_status(deactivate)` / `expire_subscription()` | — |
| `subscription_expired` | `subscription_manager::expire_subscription()` | `subscription_observer` → batch unenrol |

---

## 9. Key Design Constraints

1. **No direct SQL** — `$DB` API everywhere. Raw SQL only in `$DB->execute()` for bulk operations inside Behat step definitions (test context only).
2. **No `echo`** — all output via `$OUTPUT->render()` or renderer methods.
3. **Moodle coding style** — spaces (not tabs), `snake_case` for variables/functions, PHPDoc on every class/method.
4. **Stripe SDK pinned** to `stripe/stripe-php:^19.3` — breaking changes in v20+ require validation before upgrade.
