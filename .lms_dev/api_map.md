# API Map — enrol_mentorsubscription

> All endpoints described below belong to the `enrol_mentorsubscription` plugin.
> **Base path**: `/enrol/mentorsubscription/`

---

## 1. Entry-Point PHP Files

| File | Method | Auth | Purpose |
|------|--------|------|---------|
| `dashboard.php` | GET | `require_login` + `enrol/mentorsubscription:subscribe` | Mentor self-service UI — view plans, active subscription, mentee list |
| `subscribe.php` | GET/POST | `require_login` + capability | Initiates Stripe Checkout session; redirects to Stripe |
| `admin.php` | GET/POST | `require_login` + `enrol/mentorsubscription:manage` | Admin panel — sub_types CRUD, mentor overrides, payment history |
| `webhook.php` | POST | `NO_MOODLE_COOKIES` + Stripe HMAC | Receives Stripe webhook events; updates subscription status |

---

## 2. AJAX External API (Moodle Web Services)

Defined in `db/services.php` and implemented in `classes/external.php`.

### 2.1 `enrol_mentorsubscription_add_mentee`

| Property | Value |
|---------- |-------|
| **Type** | `write` |
| **Capability required** | `enrol/mentorsubscription:subscribe` |
| **AJAX enabled** | Yes |

**Parameters**  
```json
{ "menteeid": "int" }
```

**Returns**  
```json
{
  "success":  "bool",
  "reason":   "string",   // "added" | "limitreached" | "notfound" | "duplicate" | "nosubscription"
  "mentee_count": "int"   // current active mentee count after operation
}
```

**Errors**  
| Code | Meaning |
|------|---------|
| `error_no_active_subscription` | Calling user has no active subscription |
| `error_limit_reached` | Active mentee count >= billed_max_mentees (and no override) |
| `error_mentee_not_found` | `menteeid` does not exist in `{user}` |
| `error_mentee_already_assigned` | Mentee is already active under any mentor |

---

### 2.2 `enrol_mentorsubscription_toggle_mentee`

| Property | Value |
|----------|-------|
| **Type** | `write` |
| **Capability required** | `enrol/mentorsubscription:subscribe` |
| **AJAX enabled** | Yes |

**Parameters**  
```json
{ "menteeid": "int", "activate": "int" }  // activate: 1 = activate, 0 = deactivate
```

**Returns**  
```json
{
  "success":  "bool",
  "reason":   "string",   // "activated" | "deactivated" | "limitreached" | "notfound"
  "is_active": "int"
}
```

---

### 2.3 `enrol_mentorsubscription_get_dashboard_data`

| Property | Value |
|----------|-------|
| **Type** | `read` |
| **Capability required** | `enrol/mentorsubscription:subscribe` |
| **AJAX enabled** | Yes |

**Parameters** — none

**Returns**  
```json
{
  "subscription": {
    "id": "int",
    "plan_name": "string",
    "billing_cycle": "string",
    "status": "string",
    "period_end": "int",
    "billed_max_mentees": "int",
    "admin_max_mentees_override": "int|null"
  },
  "mentees": [
    {
      "menteeid": "int",
      "fullname": "string",
      "email": "string",
      "is_active": "int"
    }
  ],
  "active_count": "int",
  "effective_limit": "int"
}
```

---

## 3. Webhook Events (Stripe → `webhook.php`)

| Stripe Event | Handler Action |
|-------------|----------------|
| `checkout.session.completed` | `subscription_manager::create_active_subscription()` |
| `invoice.payment_succeeded` | `subscription_manager::process_renewal()` |
| `invoice.payment_failed` | Set `status = past_due`, update `timemodified` |
| `customer.subscription.deleted` | `subscription_manager::expire_subscription()` |
| `customer.subscription.updated` | Update `period_start`, `period_end`, `status` |

All events are verified via `\Stripe\Webhook::constructEvent()` before processing.

---

## 4. Moodle Scheduled Tasks

Defined in `db/tasks.php`.

| Task class | Default schedule | Purpose |
|-----------|-----------------|---------|
| `\enrol_mentorsubscription\task\sync_stripe_subscriptions` | Hourly (`0 * * * *`) | Cross-check local status vs Stripe API; apply M-5.7 grace-period expiry |

---

## 5. Admin UI Routes (admin.php `?formaction=` parameterised)

| `formaction` value | Additional params | Behavior |
|-------------------|------------------|----------|
| *(none)* | — | Render default admin panel (`admin_subscription_panel`) |
| `editsubtype` | `?subtypeid=N` (optional) | Show `sub_type_form` (create if no id, edit if id provided) |
| `togglesubtype` | `?subtypeid=N` + sesskey | Toggle `is_active` on sub_type; redirect back |
| `viewhistory` | `?userid=N` | Show `payment_history_panel` for chosen mentor |
| `override` | — | Show `override_form` for selected mentor |

---

## 6. Capabilities (db/access.php)

| Capability | Default roles | Purpose |
|-----------|--------------|---------|
| `enrol/mentorsubscription:subscribe` | `user` (authenticated) | Can subscribe to a plan and manage own mentees |
| `enrol/mentorsubscription:manage` | `manager`, `admin` | Can access admin panel, manage all subscriptions and overrides |
| `enrol/mentorsubscription:config` | `admin` | Can configure plugin settings (courses, Stripe keys, grace days) |

---

## 7. Event Classes (db/events.php)

| Event class | `crud` | Dispatched by | Notable data |
|------------|--------|--------------|-------------|
| `\enrol_mentorsubscription\event\mentee_added` | `c` | `mentorship_manager::add_mentee()` | `mentorid`, `menteeid`, `subscriptionid` |
| `\enrol_mentorsubscription\event\mentee_removed` | `d` | `toggle_mentee_status(0)` | `mentorid`, `menteeid` |
| `\enrol_mentorsubscription\event\subscription_expired` | `u` | `subscription_manager::expire_subscription()` | `userid`, `subscriptionid` |

All events extend `\core\event\base`, include `$DB` write and `\context_system` context.

---

## 8. GDPR API (privacy/provider.php)

Implements `\core_privacy\local\metadata\provider` and `\core_privacy\local\request\plugin\provider`.

| Table | Personal data fields | Export | Delete |
|-------|---------------------|--------|--------|
| `enrol_mentorsub_subscriptions` | `userid`, `stripe_cus_id`, `price_charged` | ✅ | ✅ |
| `enrol_mentorsub_mentees` | `mentorid`, `menteeid` | ✅ (for both users) | ✅ |
