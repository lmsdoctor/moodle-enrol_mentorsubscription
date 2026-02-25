# enrol_mentorsubscription

> **Mentor Subscription** — A Stripe-powered enrolment plugin for Moodle 4.5+ that lets mentors purchase subscription plans and manage cohorts of mentees who are automatically enrolled into a configurable set of courses.

---

## Table of Contents

1. [Requirements](#1-requirements)
2. [Installation](#2-installation)
3. [Configuration](#3-configuration)
4. [Stripe Setup](#4-stripe-setup)
5. [Admin Panel Usage](#5-admin-panel-usage)
6. [Development Guide](#6-development-guide)
7. [Testing](#7-testing)
8. [Coding Rules & Standards](#8-coding-rules--standards)
9. [Project Structure](#9-project-structure)
10. [License](#10-license)

---

## 1. Requirements

| Dependency | Version |
|-----------|---------|
| Moodle | 4.5+ (`2024100700`) |
| PHP | 8.1+ |
| Composer | 2.x |
| Stripe PHP SDK | `^19.3` (installed via Composer) |
| IOMAD | Compatible with 4.5 branch |

---

## 2. Installation

### 2.1 Copy the plugin

```bash
# From the Moodle root
cp -r /path/to/enrol_mentorsubscription enrol/mentorsubscription
```

### 2.2 Install Composer dependencies

```bash
cd enrol/mentorsubscription
composer install --no-dev   # production
# or
composer install            # includes PHPUnit
```

### 2.3 Run Moodle upgrade

1. Log in as **Site Administrator**.
2. Navigate to **Site Administration → Notifications**.
3. Follow the upgrade wizard — the plugin will create its database tables automatically from `db/install.xml`.

### 2.4 Database tables created

| Table | Purpose |
|-------|---------|
| `mdl_enrol_mentorsub_sub_types` | Subscription plan definitions (name, price, cycle, limits) |
| `mdl_enrol_mentorsub_subscriptions` | Per-mentor active/historical subscription records |
| `mdl_enrol_mentorsub_mentees` | Mentor → mentee relationships with `is_active` flag |
| `mdl_enrol_mentorsub_courses` | Courses included in any subscription (managed via admin UI) |

---

## 3. Configuration

Go to **Site Administration → Plugins → Enrolments → Mentor Subscription**.

### Stripe

| Setting key | Description | Default |
|-------------|-------------|---------|
| `stripe_secret_key` | Stripe secret key (`sk_live_…` or `sk_test_…`) | _(empty)_ |
| `stripe_publishable_key` | Stripe publishable key (`pk_live_…` or `pk_test_…`) | _(empty)_ |
| `stripe_webhook_secret` | Webhook signing secret from the Stripe dashboard (`whsec_…`) | _(empty)_ |

### Subscription defaults

| Setting key | Description | Default |
|-------------|-------------|---------|
| `default_max_mentees` | Global fallback mentee limit if not set per plan | `10` |
| `expiry_warning_days` | Comma-separated days before expiry to send warning emails | `14,7,3` |
| `pastdue_grace_days` | Days a `past_due` subscription stays active before being expired | `3` |

### Enrolment

| Setting key | Description | Default |
|-------------|-------------|---------|
| `studentroleid` | Moodle role assigned to mentees when enrolled in courses | _(site default)_ |
| `included_course_ids` | Fallback comma-separated course IDs (runtime management via admin panel is preferred) | _(empty)_ |

### Notifications

| Setting key | Description | Default |
|-------------|-------------|---------|
| `send_expiry_warnings` | Enable/disable expiry warning messages to mentors | `1` (enabled) |

---

## 4. Stripe Setup

### 4.1 Create a webhook endpoint

In your [Stripe Dashboard](https://dashboard.stripe.com/webhooks), add an endpoint pointing to:

```
https://your-moodle-site.com/enrol/mentorsubscription/webhook.php
```

### 4.2 Subscribe to these events

| Stripe event | Plugin action |
|-------------|--------------|
| `checkout.session.completed` | Creates an active subscription record |
| `invoice.payment_succeeded` | Processes subscription renewal (new period) |
| `invoice.payment_failed` | Sets subscription status to `past_due` |
| `customer.subscription.deleted` | Expires the subscription and unenrols all mentees |
| `customer.subscription.updated` | Syncs period dates and status |

### 4.3 Create subscription plans (Products)

1. In the Stripe Dashboard, create a **Product** for each plan.
2. Add a **Price** with the desired billing interval.
3. Copy the `price_XXXX` ID into the plugin's admin panel when creating a **Subscription Type**.

### 4.4 Verify webhook signature

The plugin verifies every incoming webhook using `\Stripe\Webhook::constructEvent()` with the `stripe_webhook_secret` setting. Requests with invalid signatures are rejected with HTTP 400.

---

## 5. Admin Panel Usage

Navigate to **Site Administration → Plugins → Enrolments → Mentor Subscription → Admin Panel** or visit `/enrol/mentorsubscription/admin.php`.

### Subscription Types

- **Add** a new plan: click **+**, fill name, billing cycle, price, mentee limit, and the Stripe Price ID.
- **Edit** an existing plan: click the edit icon on any row.
- **Activate / Deactivate** a plan: click the toggle link (uses `require_sesskey()` protection).

### Mentors

- **Override mentee limit**: click **Override** on a mentor row to temporarily raise the cap without changing their plan.
- **View payment history**: click **History** to see the full billing ledger for that mentor.

### Managed Courses

Add or remove courses from the subscription offer via the Courses panel. Mentees are enrolled in all listed courses when activated.

---

## 6. Development Guide

### 6.1 Namespace structure

```
enrol_mentorsubscription\
  subscription\
    subscription_manager     CRUD for subscriptions table + snapshot logic
    stripe_handler           Stripe API calls (create session, fetch sub)
    pricing_manager          Price resolution including admin overrides
  mentorship\
    mentorship_manager       Mentee CRUD + limit enforcement
    enrolment_sync           Bridges mentee activation to Moodle enrol API
    role_manager             Moodle parent/child context roles
  form\
    sub_type_form            Moodleform: create / edit subscription types
    add_mentee_form          Moodleform: mentor adds a mentee
    admin_subscription_form  Moodleform: admin override per mentor
  output\
    mentor_dashboard         Renderable: mentor self-service dashboard
    admin_subscription_panel Renderable: admin all-mentors overview
    payment_history_panel    Renderable: per-mentor billing ledger
  task\
    sync_stripe_subscriptions Scheduled: hourly Stripe sync + grace-period expiry
    check_expiring_subscriptions Scheduled: daily expiry warnings
  event\
    mentee_enrolled          Fired on add_mentee success
    mentee_status_changed    Fired on toggle_mentee_status
    mentee_unenrolled        Fired on unenrol
  privacy\
    provider                 GDPR export + deletion for subscriptions and mentees
```

### 6.2 Key entry points

| File | Auth | Purpose |
|------|------|---------|
| `dashboard.php` | `require_login` + `viewdashboard` | Mentor self-service UI |
| `subscribe.php` | `require_login` + `managesubscription` | Initiates Stripe Checkout |
| `admin.php` | `require_login` + `manageall` | Full admin panel |
| `webhook.php` | `NO_MOODLE_COOKIES` + Stripe HMAC | Stripe event receiver |

### 6.3 AMD JavaScript

JavaScript modules live in `amd/src/`. Build with:

```bash
# From Moodle root
grunt amd --grep="enrol_mentorsubscription"
```

### 6.4 Mustache templates

Templates are in `templates/`. Render via a `renderable` class and `$OUTPUT->render($renderable)`. Never call `echo` directly.

### 6.5 Adding a new setting

1. Add the `admin_setting_*` call to `settings.php`.
2. Add the language string to `lang/en/enrol_mentorsubscription.php`.
3. Read it in code with `get_config('enrol_mentorsubscription', 'key_name')`.

### 6.6 Adding a new AJAX service

1. Define the function class in `classes/external.php` (extend `\core_external\external_api`).
2. Register it in `db/services.php` with `ajax => true`.
3. Call from JS via `core/ajax` module.

---

## 7. Testing

### 7.1 PHPUnit

The plugin ships four test suites in `tests/`, covering all core managers.

```bash
# From Moodle root — run all plugin tests
php admin/tool/phpunit/cli/init.php
vendor/bin/phpunit --testsuite enrol_mentorsubscription
```

Or run a single suite:

```bash
vendor/bin/phpunit tests/subscription_manager_test.php
vendor/bin/phpunit tests/mentorship_manager_test.php
vendor/bin/phpunit tests/role_manager_test.php
vendor/bin/phpunit tests/enrolment_sync_test.php
```

All test classes extend `advanced_testcase` with `resetAfterTest()`.

### 7.2 Behat

Feature files are in `tests/behat/`. Custom step definitions are in `tests/behat/behat_enrol_mentorsubscription.php`.

```bash
# From Moodle root
php admin/tool/behat/cli/init.php
vendor/bin/behat --tags @enrol_mentorsubscription
```

| Feature file | Covers |
|-------------|--------|
| `enrol_mentorsubscription_subscription.feature` | Plan CRUD, mentor subscribes |
| `enrol_mentorsubscription_mentee.feature` | Add mentee, auto-enrol, deactivate |
| `enrol_mentorsubscription_limit.feature` | Hard limit enforcement, admin override |
| `enrol_mentorsubscription_expiry.feature` | Expiry block, grace period, payment history |

---

## 8. Coding Rules & Standards

These rules are **non-negotiable** for all contributions.

### PHP

- **Moodle Coding Style** — follow [Moodle coding style](https://moodledev.io/general/development/policies/codingstyle) strictly: 4-space indentation (no tabs), `snake_case` variables and functions, `CamelCase` classes.
- **PHP 8.1+** — use typed properties, `match` expressions, named arguments, and enums where appropriate. No deprecated PHP 7.x patterns.
- **No `echo` / direct output** — all output must go through `$OUTPUT->render()`, a renderer method, or a Mustache template. Never `echo` user-facing strings directly.
- **No raw SQL** — use `$DB->get_record()`, `$DB->get_records()`, `$DB->execute()`, etc. at all times. Direct SQL queries are prohibited outside test step definitions.
- **No superglobals** — never access `$_GET`, `$_POST`, `$_REQUEST` directly. Always use `required_param()` / `optional_param()` with an explicit `PARAM_*` type.

### Security

- **`require_login()`** must be called at the top of every user-facing entry point.
- **`require_capability()`** must be called after `require_login()` with the appropriate capability.
- **Moodle forms** use `sesskey` automatically. Any non-form state-changing URL must call `require_sesskey()`.
- **All output** of user-supplied data must be passed through `s()` or `format_string()` / `format_text()`. Mustache `{{variable}}` auto-escapes; use `{{{variable}}}` only for pre-sanitised renderer output.
- **Stripe webhook** payloads must be verified with `\Stripe\Webhook::constructEvent()` before processing.

### Architecture

- **Snapshot principle** — when a subscription is created, `price_charged`, `billed_max_mentees`, and `billing_cycle` are copied from the plan at that moment and must never be overwritten. This ensures billing history integrity regardless of future plan edits.
- **Soft deletes only** — mentee records use `is_active` flag. No hard `DELETE` on `enrol_mentorsub_mentees`.
- **Renderables over arrays** — data for templates must be encapsulated in a `renderable` class with an `export_for_template()` method. Never pass raw associative arrays directly to `$OUTPUT->render_from_template()`.
- **Events for side effects** — cross-cutting concerns (notifications, audit logs) must be triggered via Moodle events (`\core\event\base` subclasses), not direct calls inside business logic classes.

### Tests

- Every business logic method must have a corresponding PHPUnit test.
- Tests use `advanced_testcase` with `$this->resetAfterTest()`.
- Behat scenarios must use the provided custom step definitions for data seeding — never raw DB calls in `.feature` files.
- Test class names follow `{manager_name}_test` and are placed in `tests/`.

### Commits

Follow [Conventional Commits](https://www.conventionalcommits.org/):

```
feat(M-3): add mentee limit enforcement
fix(M-5): correct grace period calculation
docs(roadmap): mark M-4 complete
test(M-6): add enrolment_sync PHPUnit suite
```

---

## 9. Project Structure

```
enrol/mentorsubscription/
├── admin.php                  Admin panel entry point
├── dashboard.php              Mentor self-service dashboard
├── subscribe.php              Stripe Checkout initiator
├── webhook.php                Stripe webhook receiver
├── lib.php                    Moodle enrol plugin hooks
├── settings.php               Admin settings page
├── version.php                Plugin version metadata
├── composer.json              Stripe SDK + PHPUnit dependencies
│
├── amd/src/                   AMD JavaScript modules
├── classes/
│   ├── subscription/          subscription_manager, stripe_handler, pricing_manager
│   ├── mentorship/            mentorship_manager, enrolment_sync, role_manager
│   ├── form/                  Moodleforms (sub_type, add_mentee, admin override)
│   ├── output/                Renderables (dashboard, admin panel, payment history)
│   ├── task/                  Scheduled tasks (Stripe sync, expiry check)
│   ├── event/                 Moodle events (enrolled, status_changed, unenrolled)
│   ├── external.php           AJAX external API functions
│   ├── observer.php           Event observer registration
│   └── privacy/provider.php  GDPR export + deletion
│
├── db/
│   ├── install.xml            Database schema
│   ├── access.php             Capabilities
│   ├── services.php           AJAX service definitions
│   ├── tasks.php              Scheduled task definitions
│   ├── events.php             Event definitions
│   └── messages.php          Message provider definitions
│
├── lang/en/
│   └── enrol_mentorsubscription.php   All language strings
│
├── templates/
│   ├── mentor_dashboard.mustache
│   ├── admin_panel.mustache
│   ├── payment_history.mustache
│   ├── mentee_card.mustache
│   └── limit_reached_card.mustache
│
├── tests/
│   ├── subscription_manager_test.php
│   ├── mentorship_manager_test.php
│   ├── role_manager_test.php
│   ├── enrolment_sync_test.php
│   └── behat/
│       ├── behat_enrol_mentorsubscription.php   Step definitions
│       ├── enrol_mentorsubscription_subscription.feature
│       ├── enrol_mentorsubscription_mentee.feature
│       ├── enrol_mentorsubscription_limit.feature
│       └── enrol_mentorsubscription_expiry.feature
│
└── .lms_dev/
    ├── design_notes.md        Architecture decisions + security model
    └── api_map.md             Full AJAX, webhook, and GDPR API reference
```

---

## 10. License

This plugin is licensed under the [GNU General Public License v3.0](LICENSE) or later.

> © 2026 LMS Doctor — info@lmsdoctor.com
