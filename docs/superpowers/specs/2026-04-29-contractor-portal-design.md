# Contractor Portal — Lead Lifecycle & Analytics Design

**Status:** Draft  
**Date:** 2026-04-29

---

## Goal

Upgrade the contractor dashboard from a bare-bones lead list into a functional self-service portal. Contractors need to act on leads (mark status, see full details), understand their response obligations (deadline countdown), and track their performance over time (analytics).

---

## Scope

Two capabilities built together because they share the same data model:

1. **Lead lifecycle management** — status tracking, full lead details, urgency-based response deadlines, automatic fallback routing with missed-lead marks
2. **Analytics** — lead volume, response rate, win rate

---

## Architecture

**Option chosen: Option B** — new `frp-leads.php` plugin owns all lead lifecycle backend logic; `frp-dashboard.php` gets enhanced UI.

### Files

| File | Status | Responsibility |
|---|---|---|
| `wordpress-plugins/frp-leads.php` | Create | Lead status REST endpoint, WP-Cron deadline job, fallback routing logic |
| `wordpress-plugins/frp-dashboard.php` | Modify | Enhanced leads tab UI, analytics tab, modern CSS |
| `tests/leads.test.mjs` | Create | Status endpoint, deadline fallback, analytics endpoint |
| `tests/dashboard.test.mjs` | Modify | Analytics endpoint smoke test |

---

## Data Model

Three new meta keys on `frp_lead` posts:

### `lead_response_deadline`
Unix timestamp. Set when a lead is assigned to a pro. Cleared and reset when fallback fires and the lead moves to the next pro.

- Urgency `emergency` → now + 1 hour
- All other urgency values → now + 24 hours

### `lead_routing_history`
JSON array. Full audit trail of every pro the lead touched. Each entry:

```json
{
  "pro_id": 123,
  "assigned_at": 1714000000,
  "responded_at": 1714003600,
  "status": "contacted"
}
```

Statuses: `pending` | `contacted` | `won` | `lost` | `missed`

`responded_at` is null until the pro takes action or the deadline fires.

### `lead_current_assignee`
Integer pro ID. The pro currently on the hook for this lead. Updated to the next pro when fallback fires. Null when lead has been won or exhausted all matches.

### Initial entry responsibility

When a lead is first dispatched in `frp-directory.php`, the dispatching code is responsible for writing the first `pending` entry to `lead_routing_history`, setting `lead_current_assignee`, and setting `lead_response_deadline`. `frp-leads.php` only adds subsequent entries (on fallback). This contract between the two files must be implemented in `frp-directory.php` as part of this feature.

---

## Backend: `frp-leads.php` (new file)

### REST Endpoint: `POST /frp/v1/leads/{id}/status`

**Auth:** `restoration_pro` role, must be the `lead_current_assignee`.

**Body:** `{ "status": "contacted" | "won" | "lost" }`

**Behavior:**
1. Validate requesting pro is `lead_current_assignee` — return 403 otherwise.
2. Find the pro's entry in `lead_routing_history` and update `status` + `responded_at`.
3. Clear `lead_response_deadline` (fallback clock stops).
4. If status is `won`, set `lead_current_assignee` to null (lead is closed).
5. Return `{ "ok": true, "status": "<new_status>" }`.

### REST Endpoint: `GET /frp/v1/me/stats`

**Auth:** `restoration_pro` role.

**Behavior:** Queries all `frp_lead` posts where `lead_routing_history` contains the requesting pro's ID. Aggregates:

- `leads_received_total` — all time
- `leads_by_week` — array of `{ week: "2026-W17", count: N }` for last 8 weeks
- `response_rate` — `responded / total` (responded = any status other than `pending` or `missed`)
- `win_rate` — `won / responded`
- `recent_leads` — last 20 leads with `{ lead_id, service, city, urgency, status, assigned_at }`

Returns a single JSON object. Computed on demand, no caching.

### REST Endpoint: `POST /frp/v1/admin/trigger-deadline-cron` (test/ops helper)

**Auth:** `manage_options` capability only.

Manually triggers `frp_process_lead_deadlines` — used by integration tests to fire the cron job on demand without waiting for the scheduler. Also useful for ops debugging. Returns `{ "processed": N }` count of leads acted on.

### WP-Cron Job: `frp_process_lead_deadlines`

Registered on plugin load with `wp_schedule_event` using the standard duplicate-registration guard:
```php
if ( ! wp_next_scheduled( 'frp_process_lead_deadlines' ) ) {
    wp_schedule_event( time(), 'frp_quarter_hour', 'frp_process_lead_deadlines' );
}
```
Runs every 15 minutes using a custom `frp_quarter_hour` interval (registered via `cron_schedules` filter).

**On each run:**
1. Query all `frp_lead` posts where `lead_response_deadline` is in the past and `lead_current_assignee` is not null.
2. For each overdue lead:
   a. Find the current assignee's entry in `lead_routing_history`, set `status = missed`, set `responded_at = now`.
   b. Re-run `frp_find_dispatch_pros( $lead_zip, $lead_service )` — exclude all pro IDs already in `lead_routing_history`.
   c. If a next pro is found: set `lead_current_assignee` to next pro, add new `pending` entry to `lead_routing_history`, set new `lead_response_deadline` based on urgency, fire `frp_lead_created` action (triggers existing notification email).
   d. If no next pro found: set `lead_current_assignee` to null (lead exhausted — no further routing).

**Server cron note (ops task, not in code scope):** For time-sensitive emergency leads (1-hour window), configure a real server cron on SiteGround to call `wp-cron.php` every 5–10 minutes. WP-Cron alone only fires on page loads. Setup: SiteGround → Cron Jobs → `wget -q -O - https://[site]/wp-cron.php?doing_wp_cron`.

---

## Dashboard UI: `frp-dashboard.php` (modified)

### Leads Tab

Each lead renders as a card with two states:

**Collapsed (default):** Service type badge, city, urgency pill (color-coded: red = emergency, amber = urgent, grey = standard), lead score, deadline countdown for `pending` leads ("Respond within 3h 22m"), status label for responded leads.

**Expanded (click to open):** Full details — property type, homeowner notes, phone number (as a tappable `tel:` link), service area ZIP, lead score breakdown. Action buttons: **Mark Contacted**, **Mark Won**, **Mark Lost**. Buttons call `POST /frp/v1/leads/{id}/status` via fetch, then re-render the card in place.

**Missed leads section:** Shown below active leads, visually muted. Reads "You missed this lead — it was reassigned." No action buttons.

### Analytics Tab

Three metric cards at the top:

| Card | Value | Indicator |
|---|---|---|
| Leads Received | Count this month | Sparkline (8 weeks) |
| Response Rate | Percentage | Green ≥80% · Amber 50–79% · Red <50% |
| Win Rate | Percentage | No color threshold |

Below cards: a table of the last 20 leads showing service, city, urgency, status, and date assigned. Sortable by date (default) or status.

Data loaded via `GET /frp/v1/me/stats` on tab activation (lazy-loaded, not on page load).

### CSS Approach

Modern SaaS aesthetic — consistent with the join form and pro template:
- Card-based layout with subtle box-shadow and border-radius
- Color-coded status pills (green = won, blue = contacted, red = missed, amber = pending)
- Urgency indicators: red pill for emergency, amber for urgent, grey for standard
- Countdown timer rendered in a monospace badge
- Action buttons: filled primary color for "Mark Contacted", outline variants for Won/Lost
- Smooth expand/collapse transition on lead cards
- Stats cards: white background, large number, small label, colored indicator dot
- No external dependencies — vanilla CSS, no Tailwind (mu-plugin constraint)

---

## Testing

### `tests/leads.test.mjs` (new)

1. **Status endpoint — happy path:** Assign a test lead to staging pro, call `POST /frp/v1/leads/{id}/status` with `contacted`, assert response `ok: true`, assert `lead_routing_history` updated.
2. **Status endpoint — wrong assignee:** Call status endpoint as a pro who is not `lead_current_assignee`, assert 403.
3. **Status endpoint — unauthenticated:** Call without auth, assert 401.
4. **Stats endpoint:** Call `GET /frp/v1/me/stats`, assert response shape includes `leads_received_total`, `response_rate`, `win_rate`, `recent_leads`.
5. **Deadline fallback (unit-style):** Set `lead_response_deadline` to a past timestamp on a test lead, manually trigger `frp_process_lead_deadlines` via a test admin endpoint, assert original pro has `missed` status in history, assert `lead_current_assignee` updated or null.

### `tests/dashboard.test.mjs` (modify)

Add one test: authenticated pro hits `/contractor/dashboard/`, assert page contains `frp-dashboard` and `frp-analytics-tab`.

---

## Error Handling

- `POST /frp/v1/leads/{id}/status`: invalid status value → 400; lead not found → 404; not current assignee → 403; already responded → 409 with `{ message: "Lead already actioned." }`
- `GET /frp/v1/me/stats`: no leads found → returns empty aggregates (not an error), `{ leads_received_total: 0, response_rate: null, win_rate: null, recent_leads: [] }`
- WP-Cron: if `frp_find_dispatch_pros` throws, log via `error_log` and continue to next lead (don't halt the batch)

---

## Out of Scope

- Changing existing dispatch/matching weights or distance caps
- Profile editing from dashboard (future spec)
- Push/SMS notifications for deadline warnings (future spec)
- Lead decline/rejection before phone is revealed (future spec)
- Multi-assignment leads (current design: one assignee at a time)
