# Contractor Portal — Lead Lifecycle & Analytics Implementation Plan

> **For agentic workers:** REQUIRED: Use superpowers:subagent-driven-development (if subagents available) or superpowers:executing-plans to implement this plan. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Upgrade the contractor dashboard with full lead lifecycle management (status updates, deadline countdowns, automatic fallback routing) and an analytics tab (lead volume, response rate, win rate).

**Architecture:** New `frp-leads.php` mu-plugin owns all lifecycle backend logic (status REST endpoint, WP-Cron deadline processor, stats endpoint). `frp-directory.php` gains routing history writes at dispatch time. `frp-dashboard.php` gets enhanced card-based leads UI and a new analytics tab. No external dependencies — vanilla PHP/JS/CSS.

**Tech Stack:** PHP 7.4+, WordPress REST API, WP-Cron, vanilla JS (fetch), vanilla CSS, Node.js test runner (`node:test`)

---

## Chunk 1: Data Model + Backend

### Task 1: Write routing history on lead dispatch (`frp-directory.php`)

**Files:**
- Modify: `wordpress-plugins/frp-directory.php` (around line 1280)

**Context:** When a lead is dispatched, the existing code writes `lead_assigned_pros` (JSON array of pro IDs) and `dispatch_tier` to post meta, then fires the Make.com webhook. After those two `update_post_meta` calls (line 1280), we must write the three new lifecycle meta keys for the first assignee. The `$urgency` variable is already in scope. `frp_find_dispatch_pros()` returns `$dispatch_result['pros']` — use `$dispatch_result['pros'][0]['post_id']` as the first assignee.

---

- [ ] **Step 1: Write the failing test**

Create `tests/leads.test.mjs` with a single test that submits a lead and verifies the three new meta keys exist on the resulting post.

```js
// tests/leads.test.mjs
import test from 'node:test';
import assert from 'node:assert/strict';
import { frpPost, frpGet } from './helpers/wp-client.mjs';
import { resetTestLeads, readMeta } from './helpers/staging.mjs';

test.before(async () => { await resetTestLeads(); });
test.after(async ()  => { await resetTestLeads(); });

test('dispatch writes routing history, current assignee, and deadline', async () => {
  // Submit a real lead via REST — reuses same endpoint as production
  const uid = Date.now();
  const { status, body } = await frpPost('/wp-json/frp/v1/leads', {
    phone:         `555${uid.toString().slice(-7)}`,
    service:       'water-damage',
    urgency:       'now',
    property_type: 'residential',
    has_insurance: 'yes',
    zip:           '90210',
    source:        'test',
  }, { noAuth: true });

  assert.equal(status, 200, `Lead create failed: ${JSON.stringify(body)}`);
  const lead_id = body.lead_id;
  assert.ok(lead_id, 'Response must include lead_id');

  // Tag for cleanup
  await frpPost('/wp-json/frp/v1/admin/set-post-meta',
    { post_id: lead_id, meta: { test_fixture: '1' } }, { auth: true });

  // Read the three new meta keys
  const historyRaw  = await readMeta(lead_id, 'lead_routing_history');
  const assigneeRaw = await readMeta(lead_id, 'lead_current_assignee');
  const deadlineRaw = await readMeta(lead_id, 'lead_response_deadline');

  // Routing history: valid JSON array with at least one entry
  let history;
  try { history = JSON.parse(historyRaw); } catch { assert.fail(`lead_routing_history is not valid JSON: ${historyRaw}`); }
  assert.ok(Array.isArray(history) && history.length > 0, 'lead_routing_history must be non-empty array');

  const entry = history[0];
  assert.equal(typeof entry.pro_id,      'number', 'entry.pro_id must be number');
  assert.equal(typeof entry.assigned_at, 'number', 'entry.assigned_at must be unix timestamp');
  assert.equal(entry.responded_at, null,            'entry.responded_at must be null on creation');
  assert.equal(entry.status,       'pending',       'entry.status must be "pending"');

  // Current assignee: matches pro_id in history
  assert.equal(Number(assigneeRaw), entry.pro_id, 'lead_current_assignee must match first history entry pro_id');

  // Deadline: unix timestamp in the future
  const deadline = Number(deadlineRaw);
  assert.ok(deadline > Math.floor(Date.now() / 1000), 'lead_response_deadline must be in the future');
});
```

- [ ] **Step 2: Run test to verify it fails**

```bash
cd /Users/ninjitsumarketing/Projects/irestorationpros/.claude/worktrees/stoic-jennings
node --test tests/leads.test.mjs 2>&1 | head -30
```

Expected: FAIL — meta keys will be empty/null because the writes don't exist yet.

- [ ] **Step 3: Implement routing history writes in `frp-directory.php`**

Find the block at line ~1277–1298 (after the two `update_post_meta` calls, before the `frp_fire_lead_webhook` call). Insert after line 1280 (`update_post_meta( $post_id, 'dispatch_tier', $dispatch_result['tier'] );`):

```php
    // ── Lead lifecycle init ────────────────────────────────────────────────
    // Write the three lifecycle meta keys required by frp-leads.php.
    // frp-leads.php handles subsequent entries (fallback). This block owns
    // the first entry only.
    if ( ! empty( $dispatch_result['pros'] ) ) {
        $first_pro_id   = (int) $dispatch_result['pros'][0]['post_id'];
        $now_ts         = time();
        $deadline_delta = ( $urgency === 'emergency' ) ? HOUR_IN_SECONDS : DAY_IN_SECONDS;

        $routing_entry = [
            'pro_id'       => $first_pro_id,
            'assigned_at'  => $now_ts,
            'responded_at' => null,
            'status'       => 'pending',
        ];

        update_post_meta( $post_id, 'lead_routing_history',   wp_json_encode( [ $routing_entry ] ) );
        update_post_meta( $post_id, 'lead_current_assignee',  $first_pro_id );
        update_post_meta( $post_id, 'lead_response_deadline', $now_ts + $deadline_delta );
    }
    // ── End lead lifecycle init ────────────────────────────────────────────
```

- [ ] **Step 4: Run test to verify it passes**

```bash
node --test tests/leads.test.mjs 2>&1
```

Expected: PASS — all three meta keys present with correct shape.

- [ ] **Step 5: Commit**

```bash
git add wordpress-plugins/frp-directory.php tests/leads.test.mjs
git commit -m "feat: write lead routing history, assignee, and deadline on dispatch"
```

---

### Task 2: `frp-leads.php` — plugin shell + status endpoint

**Files:**
- Create: `wordpress-plugins/frp-leads.php`

**Context:** This is a new mu-plugin. It loads alphabetically after `frp-dashboard.php` and before `frp-pro-template.php`. It must define `frp_current_pro_id()` only if not already defined (it's also defined in `frp-directory.php`). The status endpoint guards: pro must be `lead_current_assignee`. On `won`, clear the assignee. On any terminal status, clear the deadline.

---

- [ ] **Step 1: Write the failing test**

Add these tests to `tests/leads.test.mjs` (append after the existing test):

```js
import { loginAsPro, readMeta, setPostMeta } from './helpers/staging.mjs';

// ── Status endpoint tests ─────────────────────────────────────────────────

test('status endpoint — happy path: mark contacted', async () => {
  const { pro_id } = await loginAsPro('testpro1');
  assert.ok(pro_id, 'testpro1 must have frp_pro_id configured');

  // Create a test lead and manually assign it to the staging pro
  const uid = Date.now();
  const createRes = await frpPost('/wp-json/frp/v1/leads', {
    phone: `555${uid.toString().slice(-7)}`,
    service: 'water-damage', urgency: 'now',
    property_type: 'residential', has_insurance: 'yes',
    zip: '90210', source: 'test',
  }, { noAuth: true });
  assert.equal(createRes.status, 200);
  const lead_id = createRes.body.lead_id;
  await frpPost('/wp-json/frp/v1/admin/set-post-meta',
    { post_id: lead_id, meta: { test_fixture: '1' } }, { auth: true });

  // Wire up routing history so the staging pro IS the current assignee
  const history = [{ pro_id, assigned_at: Math.floor(Date.now()/1000), responded_at: null, status: 'pending' }];
  await setPostMeta(lead_id, 'lead_routing_history',   JSON.stringify(history));
  await setPostMeta(lead_id, 'lead_current_assignee',  String(pro_id));
  await setPostMeta(lead_id, 'lead_response_deadline', String(Math.floor(Date.now()/1000) + 3600));

  const { status, body } = await frpPost(
    `/wp-json/frp/v1/leads/${lead_id}/status`,
    { status: 'contacted' },
    { auth: 'pro' }
  );
  assert.equal(status, 200, `status endpoint failed: ${JSON.stringify(body)}`);
  assert.equal(body.ok, true);
  assert.equal(body.status, 'contacted');

  // Verify history updated
  const historyRaw = await readMeta(lead_id, 'lead_routing_history');
  const updated    = JSON.parse(historyRaw);
  assert.equal(updated[0].status,       'contacted');
  assert.ok(updated[0].responded_at !== null, 'responded_at should be set');

  // Verify deadline cleared
  const deadline = await readMeta(lead_id, 'lead_response_deadline');
  assert.ok(!deadline || deadline === '', 'deadline should be cleared after response');
});

test('status endpoint — wrong assignee returns 403', async () => {
  const { pro_id } = await loginAsPro('testpro1');

  const uid = Date.now();
  const createRes = await frpPost('/wp-json/frp/v1/leads', {
    phone: `555${uid.toString().slice(-7)}`,
    service: 'water-damage', urgency: 'now',
    property_type: 'residential', has_insurance: 'yes',
    zip: '90210', source: 'test',
  }, { noAuth: true });
  const lead_id = createRes.body.lead_id;
  await frpPost('/wp-json/frp/v1/admin/set-post-meta',
    { post_id: lead_id, meta: { test_fixture: '1' } }, { auth: true });

  // Assign to a DIFFERENT pro (9999999), not testpro1
  const history = [{ pro_id: 9999999, assigned_at: Math.floor(Date.now()/1000), responded_at: null, status: 'pending' }];
  await setPostMeta(lead_id, 'lead_routing_history',  JSON.stringify(history));
  await setPostMeta(lead_id, 'lead_current_assignee', '9999999');

  const { status } = await frpPost(
    `/wp-json/frp/v1/leads/${lead_id}/status`,
    { status: 'contacted' },
    { auth: 'pro' }
  );
  assert.equal(status, 403, 'Wrong assignee must get 403');
});

test('status endpoint — unauthenticated returns 401', async () => {
  const { status } = await frpPost('/wp-json/frp/v1/leads/1/status', { status: 'contacted' }, { noAuth: true });
  assert.equal(status, 401);
});

test('status endpoint — non-existent lead returns 404', async () => {
  const { status } = await frpPost(
    '/wp-json/frp/v1/leads/99999999/status',
    { status: 'contacted' },
    { auth: 'pro' }
  );
  assert.equal(status, 404, 'Non-existent lead must return 404');
});

test('status endpoint — won clears current assignee', async () => {
  const { pro_id } = await loginAsPro('testpro1');
  const uid = Date.now();
  const createRes = await frpPost('/wp-json/frp/v1/leads', {
    phone: `555${uid.toString().slice(-7)}`,
    service: 'water-damage', urgency: 'now',
    property_type: 'residential', has_insurance: 'yes',
    zip: '90210', source: 'test',
  }, { noAuth: true });
  const lead_id = createRes.body.lead_id;
  await frpPost('/wp-json/frp/v1/admin/set-post-meta',
    { post_id: lead_id, meta: { test_fixture: '1' } }, { auth: true });

  const history = [{ pro_id, assigned_at: Math.floor(Date.now()/1000), responded_at: null, status: 'pending' }];
  await setPostMeta(lead_id, 'lead_routing_history',  JSON.stringify(history));
  await setPostMeta(lead_id, 'lead_current_assignee', String(pro_id));
  await setPostMeta(lead_id, 'lead_response_deadline', String(Math.floor(Date.now()/1000) + 3600));

  const { status, body } = await frpPost(
    `/wp-json/frp/v1/leads/${lead_id}/status`,
    { status: 'won' },
    { auth: 'pro' }
  );
  assert.equal(status, 200, `won status failed: ${JSON.stringify(body)}`);
  assert.equal(body.status, 'won');

  // Assignee must be cleared
  const assigneeRaw = await readMeta(lead_id, 'lead_current_assignee');
  assert.ok(!assigneeRaw || assigneeRaw === '', 'lead_current_assignee must be empty after won');
});

test('status endpoint — invalid status returns 400', async () => {
  const { pro_id } = await loginAsPro('testpro1');
  const uid = Date.now();
  const createRes = await frpPost('/wp-json/frp/v1/leads', {
    phone: `555${uid.toString().slice(-7)}`,
    service: 'water-damage', urgency: 'now',
    property_type: 'residential', has_insurance: 'yes',
    zip: '90210', source: 'test',
  }, { noAuth: true });
  const lead_id = createRes.body.lead_id;
  await frpPost('/wp-json/frp/v1/admin/set-post-meta',
    { post_id: lead_id, meta: { test_fixture: '1' } }, { auth: true });

  const history = [{ pro_id, assigned_at: Math.floor(Date.now()/1000), responded_at: null, status: 'pending' }];
  await setPostMeta(lead_id, 'lead_routing_history',  JSON.stringify(history));
  await setPostMeta(lead_id, 'lead_current_assignee', String(pro_id));

  const { status } = await frpPost(
    `/wp-json/frp/v1/leads/${lead_id}/status`,
    { status: 'banana' },
    { auth: 'pro' }
  );
  assert.equal(status, 400, 'Invalid status value must return 400');
});

test('status endpoint — already responded returns 409', async () => {
  const { pro_id } = await loginAsPro('testpro1');
  const uid = Date.now();
  const createRes = await frpPost('/wp-json/frp/v1/leads', {
    phone: `555${uid.toString().slice(-7)}`,
    service: 'water-damage', urgency: 'now',
    property_type: 'residential', has_insurance: 'yes',
    zip: '90210', source: 'test',
  }, { noAuth: true });
  const lead_id = createRes.body.lead_id;
  await frpPost('/wp-json/frp/v1/admin/set-post-meta',
    { post_id: lead_id, meta: { test_fixture: '1' } }, { auth: true });

  // History shows already responded
  const history = [{ pro_id, assigned_at: Math.floor(Date.now()/1000) - 60, responded_at: Math.floor(Date.now()/1000), status: 'contacted' }];
  await setPostMeta(lead_id, 'lead_routing_history',  JSON.stringify(history));
  await setPostMeta(lead_id, 'lead_current_assignee', String(pro_id));

  const { status } = await frpPost(
    `/wp-json/frp/v1/leads/${lead_id}/status`,
    { status: 'won' },
    { auth: 'pro' }
  );
  assert.equal(status, 409, 'Already-actioned lead must return 409');
});
```

- [ ] **Step 2: Run tests to verify they fail**

```bash
node --test tests/leads.test.mjs 2>&1 | grep -E '(not ok|Error|FAIL)'
```

Expected: FAIL on all new tests — endpoint doesn't exist yet (404).

- [ ] **Step 3: Create `frp-leads.php`**

```php
<?php
/**
 * Plugin Name: FRP Lead Lifecycle
 * Description: Lead status REST endpoint, WP-Cron deadline processor, stats endpoint
 * Version: 0.1.0
 */
if ( ! defined( 'ABSPATH' ) ) exit;

// ── Constants ────────────────────────────────────────────────────────────────
define( 'FRP_LEADS_STATUSES', [ 'contacted', 'won', 'lost' ] );

// ── Helpers ──────────────────────────────────────────────────────────────────

/**
 * Returns the restoration_pro post ID bound to the current WP user.
 * Returns 0 if not found. Safe to call before frp-directory.php loads
 * because mu-plugins load in alphabetical order (frp-leads.php < frp-directory.php).
 * Do NOT redeclare — frp-directory.php may already define this.
 */
if ( ! function_exists( 'frp_current_pro_id' ) ) {
    function frp_current_pro_id() {
        $user = wp_get_current_user();
        if ( ! $user->ID ) return 0;
        $pro_id = (int) get_user_meta( $user->ID, 'frp_pro_id', true );
        return $pro_id ?: 0;
    }
}

// ── REST API ─────────────────────────────────────────────────────────────────

add_action( 'rest_api_init', 'frp_leads_register_routes' );

function frp_leads_register_routes() {

    // POST /frp/v1/leads/{id}/status
    register_rest_route( 'frp/v1', '/leads/(?P<id>\d+)/status', [
        'methods'             => 'POST',
        'callback'            => 'frp_leads_update_status',
        'permission_callback' => 'frp_leads_permission_check',
        'args'                => [
            'id'     => [ 'validate_callback' => fn($v) => is_numeric($v) && $v > 0 ],
            'status' => [
                'required'          => true,
                'validate_callback' => fn($v) => in_array( $v, FRP_LEADS_STATUSES, true ),
            ],
        ],
    ] );

    // POST /frp/v1/admin/trigger-deadline-cron  (test/ops helper)
    register_rest_route( 'frp/v1', '/admin/trigger-deadline-cron', [
        'methods'             => 'POST',
        'callback'            => 'frp_leads_trigger_deadline_cron',
        'permission_callback' => fn() => current_user_can( 'manage_options' ),
    ] );
}

function frp_leads_permission_check() {
    if ( ! is_user_logged_in() ) return new WP_Error( 'rest_forbidden', 'Authentication required.', [ 'status' => 401 ] );
    $pro_id = frp_current_pro_id();
    if ( ! $pro_id ) return new WP_Error( 'rest_forbidden', 'No contractor profile found.', [ 'status' => 403 ] );
    return true;
}

function frp_leads_update_status( WP_REST_Request $req ) {
    $lead_id    = (int) $req->get_param( 'id' );
    $new_status = $req->get_param( 'status' );
    $pro_id     = frp_current_pro_id();

    // Lead must exist
    $lead = get_post( $lead_id );
    if ( ! $lead || $lead->post_type !== 'frp_lead' ) {
        return new WP_Error( 'not_found', 'Lead not found.', [ 'status' => 404 ] );
    }

    // Pro must be the current assignee
    $current_assignee = (int) get_post_meta( $lead_id, 'lead_current_assignee', true );
    if ( $current_assignee !== $pro_id ) {
        return new WP_Error( 'rest_forbidden', 'You are not the current assignee for this lead.', [ 'status' => 403 ] );
    }

    // Update routing history
    $history_raw = get_post_meta( $lead_id, 'lead_routing_history', true );
    $history     = $history_raw ? json_decode( $history_raw, true ) : [];

    // Find this pro's entry
    $entry_idx = null;
    foreach ( $history as $i => $entry ) {
        if ( (int) $entry['pro_id'] === $pro_id ) {
            $entry_idx = $i;
            break;
        }
    }

    if ( $entry_idx === null ) {
        return new WP_Error( 'invalid_state', 'Routing history entry not found.', [ 'status' => 422 ] );
    }

    // Already responded check (responded_at already set, status not pending)
    if ( $history[ $entry_idx ]['responded_at'] !== null && $history[ $entry_idx ]['status'] !== 'pending' ) {
        return new WP_Error( 'already_actioned', 'Lead already actioned.', [ 'status' => 409 ] );
    }

    // Apply update
    $history[ $entry_idx ]['status']       = $new_status;
    $history[ $entry_idx ]['responded_at'] = time();

    update_post_meta( $lead_id, 'lead_routing_history',   wp_json_encode( $history ) );
    update_post_meta( $lead_id, 'lead_response_deadline', '' );  // clear deadline

    if ( $new_status === 'won' ) {
        update_post_meta( $lead_id, 'lead_current_assignee', '' );  // lead closed
    }

    return rest_ensure_response( [ 'ok' => true, 'status' => $new_status ] );
}

function frp_leads_trigger_deadline_cron() {
    frp_process_lead_deadlines();
    return rest_ensure_response( [ 'triggered' => true ] );
}
```

- [ ] **Step 4: Run tests to verify they pass**

```bash
node --test tests/leads.test.mjs 2>&1
```

Expected: PASS on all 6 tests (dispatch test + 5 new status tests).

- [ ] **Step 5: Commit**

```bash
git add wordpress-plugins/frp-leads.php tests/leads.test.mjs
git commit -m "feat: frp-leads.php plugin shell + status REST endpoint"
```

---

### Task 3: WP-Cron deadline processor + trigger endpoint test

**Files:**
- Modify: `wordpress-plugins/frp-leads.php` (append cron code)
- Modify: `tests/leads.test.mjs` (append deadline fallback test)

**Context:** The cron runs every 15 minutes, finds leads past their deadline, marks the current assignee as `missed`, then re-runs `frp_find_dispatch_pros()` excluding already-tried pros. The trigger endpoint (`POST /frp/v1/admin/trigger-deadline-cron`) already calls `frp_process_lead_deadlines()` — so we just need that function implemented. The trigger endpoint test uses `setPostMeta` to backdate the deadline, then calls the trigger, then reads the history.

---

- [ ] **Step 1: Write the failing test**

Append to `tests/leads.test.mjs`:

```js
test('deadline fallback: overdue lead marks missed and reassigns or exhausts', async () => {
  const { pro_id } = await loginAsPro('testpro1');
  assert.ok(pro_id, 'testpro1 must have frp_pro_id configured');

  // Create lead and assign to the staging pro with an expired deadline
  const uid = Date.now();
  const createRes = await frpPost('/wp-json/frp/v1/leads', {
    phone: `555${uid.toString().slice(-7)}`,
    service: 'water-damage', urgency: 'now',
    property_type: 'residential', has_insurance: 'yes',
    zip: '90210', source: 'test',
  }, { noAuth: true });
  assert.equal(createRes.status, 200);
  const lead_id = createRes.body.lead_id;
  await frpPost('/wp-json/frp/v1/admin/set-post-meta',
    { post_id: lead_id, meta: { test_fixture: '1' } }, { auth: true });

  const pastDeadline = String(Math.floor(Date.now() / 1000) - 7200); // 2 hours ago
  const history = [{ pro_id, assigned_at: Math.floor(Date.now()/1000) - 7200, responded_at: null, status: 'pending' }];
  await setPostMeta(lead_id, 'lead_routing_history',   JSON.stringify(history));
  await setPostMeta(lead_id, 'lead_current_assignee',  String(pro_id));
  await setPostMeta(lead_id, 'lead_response_deadline', pastDeadline);

  // Trigger the cron job manually
  const { status: triggerStatus, body: triggerBody } = await frpPost(
    '/wp-json/frp/v1/admin/trigger-deadline-cron', {}, { auth: true }
  );
  assert.equal(triggerStatus, 200, `trigger-deadline-cron failed: ${JSON.stringify(triggerBody)}`);
  assert.ok(typeof triggerBody.processed === 'number', 'trigger response must include numeric processed count');
  assert.ok(triggerBody.processed >= 1, 'processed count must be at least 1 (our test lead)');

  // Verify the original pro is now marked missed
  const historyRaw = await readMeta(lead_id, 'lead_routing_history');
  const updatedHistory = JSON.parse(historyRaw);
  const proEntry = updatedHistory.find(e => e.pro_id === pro_id);
  assert.ok(proEntry, 'Original pro entry must still exist in history');
  assert.equal(proEntry.status, 'missed', 'Original pro must be marked "missed"');
  assert.ok(proEntry.responded_at !== null, 'responded_at must be set on missed entry');

  // Assignee must be cleared (null/empty) or updated to next pro
  const assigneeRaw = await readMeta(lead_id, 'lead_current_assignee');
  // Either null/empty (no next pro found) or a different pro ID
  const assignee = Number(assigneeRaw);
  assert.ok(
    !assigneeRaw || assigneeRaw === '' || assignee !== pro_id,
    `Assignee must change from original pro (was ${pro_id}, now ${assigneeRaw})`
  );

  // Idempotency: triggering the cron again on an already-processed lead must not re-process it.
  // If the lead is now exhausted (assignee=''), a second trigger should return processed=0 for this lead.
  if (!assigneeRaw || assigneeRaw === '') {
    const { status: s2, body: b2 } = await frpPost(
      '/wp-json/frp/v1/admin/trigger-deadline-cron', {}, { auth: true }
    );
    assert.equal(s2, 200);
    // processed count should not include our already-exhausted lead again
    const histAfter = JSON.parse(await readMeta(lead_id, 'lead_routing_history'));
    const ourEntries = histAfter.filter(e => e.pro_id === pro_id);
    assert.equal(ourEntries.length, 1, 'Exhausted lead must not accumulate duplicate missed entries on re-trigger');
  }
});
```

- [ ] **Step 2: Run test to verify it fails**

```bash
node --test tests/leads.test.mjs --test-name-pattern="deadline fallback" 2>&1
```

Expected: FAIL — `frp_process_lead_deadlines` function doesn't exist yet.

- [ ] **Step 3: Append cron registration and processor to `frp-leads.php`**

Append to the bottom of `wordpress-plugins/frp-leads.php`:

```php
// ── WP-Cron: Lead Deadline Processor ─────────────────────────────────────────

// Register custom 15-minute interval
add_filter( 'cron_schedules', 'frp_leads_add_cron_interval' );
function frp_leads_add_cron_interval( $schedules ) {
    if ( ! isset( $schedules['frp_quarter_hour'] ) ) {
        $schedules['frp_quarter_hour'] = [
            'interval' => 15 * MINUTE_IN_SECONDS,
            'display'  => __( 'Every 15 Minutes' ),
        ];
    }
    return $schedules;
}

// Schedule on plugin load (guard against duplicate registration)
add_action( 'init', 'frp_leads_schedule_cron' );
function frp_leads_schedule_cron() {
    if ( ! wp_next_scheduled( 'frp_process_lead_deadlines' ) ) {
        wp_schedule_event( time(), 'frp_quarter_hour', 'frp_process_lead_deadlines' );
    }
}

add_action( 'frp_process_lead_deadlines', 'frp_process_lead_deadlines' );

/**
 * Process overdue leads: mark missed, attempt fallback routing.
 * Called by WP-Cron and by the admin trigger endpoint.
 *
 * @return int Number of leads processed.
 */
function frp_process_lead_deadlines() {
    $now     = time();
    $count   = 0;

    $overdue = new WP_Query( [
        'post_type'      => 'frp_lead',
        'post_status'    => 'publish',
        'posts_per_page' => 100,
        'meta_query'     => [
            'relation' => 'AND',
            [
                'key'     => 'lead_response_deadline',
                'value'   => $now,
                'compare' => '<',
                'type'    => 'NUMERIC',
            ],
            [
                'key'     => 'lead_current_assignee',
                'value'   => '',
                'compare' => '!=',
            ],
        ],
    ] );

    foreach ( $overdue->posts as $lead ) {
        $lead_id = $lead->ID;

        try {
            $history_raw      = get_post_meta( $lead_id, 'lead_routing_history', true );
            $history          = $history_raw ? json_decode( $history_raw, true ) : [];
            $current_assignee = (int) get_post_meta( $lead_id, 'lead_current_assignee', true );

            // Find the current assignee's entry and mark missed
            foreach ( $history as &$entry ) {
                if ( (int) $entry['pro_id'] === $current_assignee && $entry['status'] === 'pending' ) {
                    $entry['status']       = 'missed';
                    $entry['responded_at'] = $now;
                    break;
                }
            }
            unset( $entry );

            // Collect all tried pro IDs for exclusion
            $tried_ids = array_map( fn($e) => (int) $e['pro_id'], $history );

            // Attempt fallback routing
            $zip     = get_post_meta( $lead_id, 'lead_zip',     true );
            $service = get_post_meta( $lead_id, 'lead_service', true );
            $urgency = get_post_meta( $lead_id, 'lead_urgency', true );

            $next_pro_id = 0;
            if ( $zip && $service && function_exists( 'frp_find_dispatch_pros' ) ) {
                $result = frp_find_dispatch_pros( $zip, $service );
                foreach ( $result['pros'] ?? [] as $candidate ) {
                    $cid = (int) $candidate['post_id'];
                    if ( ! in_array( $cid, $tried_ids, true ) ) {
                        $next_pro_id = $cid;
                        break;
                    }
                }
            }

            if ( $next_pro_id ) {
                // Route to next pro
                $deadline_delta = ( $urgency === 'emergency' ) ? HOUR_IN_SECONDS : DAY_IN_SECONDS;
                $history[] = [
                    'pro_id'       => $next_pro_id,
                    'assigned_at'  => $now,
                    'responded_at' => null,
                    'status'       => 'pending',
                ];
                update_post_meta( $lead_id, 'lead_routing_history',   wp_json_encode( $history ) );
                update_post_meta( $lead_id, 'lead_current_assignee',  $next_pro_id );
                update_post_meta( $lead_id, 'lead_response_deadline', $now + $deadline_delta );

                // Trigger notification email to next pro
                do_action( 'frp_lead_created', $lead_id );
            } else {
                // No more pros — lead exhausted
                update_post_meta( $lead_id, 'lead_routing_history',   wp_json_encode( $history ) );
                update_post_meta( $lead_id, 'lead_current_assignee',  '' );
                update_post_meta( $lead_id, 'lead_response_deadline', '' );
            }

            $count++;
        } catch ( Throwable $e ) {
            error_log( "[frp_process_lead_deadlines] Error on lead {$lead_id}: " . $e->getMessage() );
            // Continue to next lead — don't halt the batch
        }
    }

    return $count;
}
```

Also update the trigger endpoint callback in `frp_leads_trigger_deadline_cron` to return the count:

```php
function frp_leads_trigger_deadline_cron() {
    $processed = frp_process_lead_deadlines();
    return rest_ensure_response( [ 'processed' => $processed ] );
}
```

- [ ] **Step 4: Run all leads tests**

```bash
node --test tests/leads.test.mjs 2>&1
```

Expected: PASS on all tests including the new deadline fallback test.

- [ ] **Step 5: Commit**

```bash
git add wordpress-plugins/frp-leads.php tests/leads.test.mjs
git commit -m "feat: WP-Cron deadline processor with missed-mark and fallback routing"
```

---

## Chunk 2: Stats + Dashboard UI

### Task 4: `GET /frp/v1/me/stats` endpoint

**Files:**
- Modify: `wordpress-plugins/frp-leads.php` (append stats route)
- Modify: `tests/leads.test.mjs` (append stats test)

**Context:** The stats endpoint queries all `frp_lead` posts where `lead_routing_history` contains the requesting pro's ID (LIKE query on the JSON blob). It aggregates on-demand — no caching. For `leads_by_week`, generate the last 8 ISO week strings and count leads assigned in each week.

---

- [ ] **Step 1: Write the failing test**

Append to `tests/leads.test.mjs`:

```js
test('stats endpoint — returns correct shape', async () => {
  const { status, body } = await frpGet('/wp-json/frp/v1/me/stats', { auth: 'pro' });
  assert.equal(status, 200, `stats endpoint failed: ${JSON.stringify(body)}`);

  // Required fields
  assert.ok('leads_received_total' in body, 'missing leads_received_total');
  assert.ok('leads_by_week'        in body, 'missing leads_by_week');
  assert.ok('response_rate'        in body, 'missing response_rate');
  assert.ok('win_rate'             in body, 'missing win_rate');
  assert.ok('recent_leads'         in body, 'missing recent_leads');

  assert.ok(typeof body.leads_received_total === 'number', 'leads_received_total must be number');
  assert.ok(Array.isArray(body.leads_by_week),             'leads_by_week must be array');
  assert.ok(Array.isArray(body.recent_leads),              'recent_leads must be array');

  // leads_by_week entries must have week and count
  if (body.leads_by_week.length > 0) {
    const entry = body.leads_by_week[0];
    assert.ok('week'  in entry, 'leads_by_week entry missing week');
    assert.ok('count' in entry, 'leads_by_week entry missing count');
  }

  // recent_leads entries shape
  if (body.recent_leads.length > 0) {
    const lead = body.recent_leads[0];
    assert.ok('lead_id'     in lead, 'recent_leads entry missing lead_id');
    assert.ok('service'     in lead, 'recent_leads entry missing service');
    assert.ok('city'        in lead, 'recent_leads entry missing city');
    assert.ok('urgency'     in lead, 'recent_leads entry missing urgency');
    assert.ok('status'      in lead, 'recent_leads entry missing status');
    assert.ok('assigned_at' in lead, 'recent_leads entry missing assigned_at');
  }
});

test('stats endpoint — unauthenticated returns 401', async () => {
  const { status } = await frpGet('/wp-json/frp/v1/me/stats');
  assert.equal(status, 401);
});

test('stats endpoint — zero-lead pro returns empty-state shape', async () => {
  // Create a fresh pro with no lead history and call stats as them.
  // We can't easily create a second WP user mid-test, so instead verify
  // the shape contract via the PHP: when leads_received_total is 0,
  // response_rate and win_rate must be null (not 0 or NaN).
  // Use the staging pro whose lead history we'll read; if they have leads,
  // we still verify the null rules hold programmatically by constructing the
  // expected values from the actual counts returned.
  const { status, body } = await frpGet('/wp-json/frp/v1/me/stats', { auth: 'pro' });
  assert.equal(status, 200);
  if (body.leads_received_total === 0) {
    assert.equal(body.response_rate, null, 'response_rate must be null when no leads');
    assert.equal(body.win_rate,      null, 'win_rate must be null when no leads');
    assert.deepEqual(body.recent_leads, [], 'recent_leads must be empty array when no leads');
  } else {
    // Leads exist — verify nulls are used for win_rate when responded=0
    // (unlikely but possible: all leads missed/pending)
    // At minimum confirm neither field is NaN or undefined
    const rr = body.response_rate;
    const wr = body.win_rate;
    assert.ok(rr === null || (typeof rr === 'number' && !isNaN(rr)), 'response_rate must be null or a valid number');
    assert.ok(wr === null || (typeof wr === 'number' && !isNaN(wr)), 'win_rate must be null or a valid number');
  }
});
```

- [ ] **Step 2: Run tests to verify they fail**

```bash
node --test tests/leads.test.mjs --test-name-pattern="stats endpoint" 2>&1
```

Expected: FAIL — 404, endpoint doesn't exist yet.

- [ ] **Step 3: Append stats route to `frp-leads.php`**

In the `frp_leads_register_routes` function, add inside `rest_api_init`:

```php
    // GET /frp/v1/me/stats
    register_rest_route( 'frp/v1', '/me/stats', [
        'methods'             => 'GET',
        'callback'            => 'frp_leads_get_stats',
        'permission_callback' => 'frp_leads_permission_check',
    ] );
```

Then append the callback function to `frp-leads.php`:

```php
// ── Stats Endpoint ────────────────────────────────────────────────────────────

function frp_leads_get_stats() {
    $pro_id = frp_current_pro_id();

    // Query leads that contain this pro in routing history
    $leads_query = new WP_Query( [
        'post_type'      => 'frp_lead',
        'post_status'    => 'publish',
        'posts_per_page' => -1,
        'meta_query'     => [[
            'key'     => 'lead_routing_history',
            'value'   => '"pro_id":' . $pro_id,
            'compare' => 'LIKE',
        ]],
        'orderby'        => 'date',
        'order'          => 'DESC',
    ] );

    $all_leads            = $leads_query->posts;
    $leads_received_total = 0;
    $responded            = 0;
    $won                  = 0;
    $recent_leads         = [];

    // Build ISO week buckets for last 8 weeks
    $week_map = [];
    for ( $i = 7; $i >= 0; $i-- ) {
        $ts   = strtotime( "-{$i} weeks" );
        $wkey = gmdate( 'o-\WW', $ts );  // e.g. "2026-W17"
        $week_map[ $wkey ] = 0;
    }

    foreach ( $all_leads as $lead ) {
        $history_raw = get_post_meta( $lead->ID, 'lead_routing_history', true );
        $history     = $history_raw ? json_decode( $history_raw, true ) : [];

        foreach ( $history as $entry ) {
            if ( (int) $entry['pro_id'] !== $pro_id ) continue;

            $leads_received_total++;

            $status = $entry['status'] ?? 'pending';
            if ( ! in_array( $status, [ 'pending', 'missed' ], true ) ) $responded++;
            if ( $status === 'won' ) $won++;

            // Week bucket
            $assigned_ts = (int) ( $entry['assigned_at'] ?? 0 );
            if ( $assigned_ts ) {
                $wkey = gmdate( 'o-\WW', $assigned_ts );
                if ( isset( $week_map[ $wkey ] ) ) $week_map[ $wkey ]++;
            }

            // Recent leads (last 20 for this pro)
            if ( count( $recent_leads ) < 20 ) {
                $recent_leads[] = [
                    'lead_id'     => $lead->ID,
                    'service'     => get_post_meta( $lead->ID, 'lead_service', true ),
                    'city'        => get_post_meta( $lead->ID, 'lead_city',    true ),
                    'urgency'     => get_post_meta( $lead->ID, 'lead_urgency', true ),
                    'status'      => $status,
                    'assigned_at' => $assigned_ts,
                ];
            }

            break;  // Only count this pro's entry once per lead
        }
    }

    $leads_by_week = [];
    foreach ( $week_map as $wkey => $cnt ) {
        $leads_by_week[] = [ 'week' => $wkey, 'count' => $cnt ];
    }

    return rest_ensure_response( [
        'leads_received_total' => $leads_received_total,
        'leads_by_week'        => $leads_by_week,
        'response_rate'        => $leads_received_total > 0 ? round( $responded / $leads_received_total, 4 ) : null,
        'win_rate'             => $responded > 0 ? round( $won / $responded, 4 ) : null,
        'recent_leads'         => $recent_leads,
    ] );
}
```

- [ ] **Step 4: Run all leads tests**

```bash
node --test tests/leads.test.mjs 2>&1
```

Expected: PASS on all tests.

- [ ] **Step 5: Commit**

```bash
git add wordpress-plugins/frp-leads.php tests/leads.test.mjs
git commit -m "feat: GET /frp/v1/me/stats endpoint with aggregated analytics"
```

---

### Task 5: Enhanced `frp-dashboard.php` — leads UI + analytics tab

**Files:**
- Modify: `wordpress-plugins/frp-dashboard.php` (full rewrite)
- Modify: `tests/dashboard.test.mjs` (add analytics tab test)

**Context:** Current `frp-dashboard.php` is 113 lines — a minimal shell. The new version replaces `frp_dashboard_leads_html()` with card-based rendering using `lead_routing_history` to determine the pro's status on each lead, adds countdown timers for pending leads, adds action buttons, a missed leads section, and a full analytics tab. The Profile and Billing tab functions remain unchanged. No external CSS dependencies — all styles inline in the plugin.

---

- [ ] **Step 1: Write the failing test**

Append to `tests/dashboard.test.mjs`:

```js
test('authenticated pro dashboard has analytics tab', async () => {
  // Fetch the contractor dashboard page HTML
  const { status, body } = await frpGet('/contractor/dashboard/', { auth: 'pro', acceptHtml: true });
  assert.equal(status, 200, `Dashboard page failed: ${status}`);
  // Check for key identifiers added in the new implementation
  assert.ok(body.includes('frp-dashboard'),      'Page must include frp-dashboard class');
  assert.ok(body.includes('frp-analytics-tab'),  'Page must include frp-analytics-tab element');
});
```

Note: the `frpGet` helper needs `acceptHtml: true` support. If `wp-client.mjs` doesn't support it yet, add this as a direct fetch:

```js
test('authenticated pro dashboard has analytics tab', async () => {
  const { STAGING_URL, STAGING_PRO_USERNAME, STAGING_PRO_APP_PASSWORD } = process.env;
  const url  = `${STAGING_URL}/contractor/dashboard/`;
  const cred = Buffer.from(`${STAGING_PRO_USERNAME}:${STAGING_PRO_APP_PASSWORD}`).toString('base64');
  const res  = await fetch(url, {
    headers: { 'Authorization': `Basic ${cred}`, 'Accept': 'text/html' },
    redirect: 'follow',
  });
  const html = await res.text();
  assert.equal(res.status, 200, `Dashboard page failed: ${res.status}`);
  assert.ok(html.includes('frp-dashboard'),     'Page must include frp-dashboard class');
  assert.ok(html.includes('frp-analytics-tab'), 'Page must include frp-analytics-tab element');
});
```

- [ ] **Step 2: Run test to verify it fails**

```bash
node --test tests/dashboard.test.mjs --test-name-pattern="analytics tab" 2>&1
```

Expected: FAIL — `frp-analytics-tab` doesn't exist in the current HTML.

- [ ] **Step 3: Rewrite `frp-dashboard.php`**

Replace the entire file with the new implementation below. The Profile and Billing functions are preserved exactly. The Leads tab and CSS are new.

```php
<?php
/**
 * Plugin Name: FRP Contractor Dashboard
 * Description: Authenticated contractor self-service (leads, profile, billing)
 * Version: 0.2.0
 */
if ( ! defined( 'ABSPATH' ) ) exit;

add_shortcode( 'frp_contractor_dashboard', 'frp_dashboard_render' );

function frp_dashboard_render() {
    if ( ! is_user_logged_in() ) {
        return frp_dashboard_login_form();
    }
    $user = wp_get_current_user();
    if ( ! in_array( 'restoration_pro', (array) $user->roles, true ) ) {
        return '<div class="frp-dashboard-error">Your account does not have contractor access. <a href="/join/">Apply to join</a>.</div>';
    }
    $pro_id = frp_current_pro_id();
    if ( ! $pro_id ) {
        return '<div class="frp-dashboard-error">Your account is not linked to a profile yet. Contact support.</div>';
    }
    ob_start();
    frp_dashboard_styles();
    ?>
    <div class="frp-dashboard">
      <nav class="frp-dash-tabs" role="tablist">
        <button class="frp-tab-btn active" data-tab="leads"     role="tab" aria-selected="true">Leads</button>
        <button class="frp-tab-btn"        data-tab="analytics" role="tab" aria-selected="false">Analytics</button>
        <button class="frp-tab-btn"        data-tab="profile"   role="tab" aria-selected="false">Profile</button>
        <button class="frp-tab-btn"        data-tab="billing"   role="tab" aria-selected="false">Billing</button>
      </nav>

      <section id="frp-tab-leads"     class="frp-tab-pane active"><?php echo frp_dashboard_leads_html( $pro_id ); ?></section>
      <section id="frp-tab-analytics" class="frp-tab-pane frp-analytics-tab"></section>
      <section id="frp-tab-profile"   class="frp-tab-pane"><?php echo frp_dashboard_profile_html( $pro_id ); ?></section>
      <section id="frp-tab-billing"   class="frp-tab-pane"><?php echo frp_dashboard_billing_html( $pro_id ); ?></section>
    </div>
    <script>
    (function () {
      // ── Tab switching ──────────────────────────────────────────────────
      var analyticsLoaded = false;
      document.querySelectorAll('.frp-tab-btn').forEach(function (btn) {
        btn.addEventListener('click', function () {
          document.querySelectorAll('.frp-tab-btn').forEach(function(b){ b.classList.remove('active'); b.setAttribute('aria-selected','false'); });
          document.querySelectorAll('.frp-tab-pane').forEach(function(p){ p.classList.remove('active'); });
          btn.classList.add('active');
          btn.setAttribute('aria-selected','true');
          var tab = btn.dataset.tab;
          document.getElementById('frp-tab-' + tab).classList.add('active');
          if (tab === 'analytics' && !analyticsLoaded) {
            analyticsLoaded = true;
            frpLoadAnalytics();
          }
        });
      });

      // ── Lead card expand/collapse ─────────────────────────────────────
      document.addEventListener('click', function (e) {
        var header = e.target.closest('.frp-lead-header');
        if (!header) return;
        var card = header.closest('.frp-lead-card');
        if (!card) return;
        card.classList.toggle('frp-expanded');
      });

      // ── Status action buttons ─────────────────────────────────────────
      document.addEventListener('click', function (e) {
        var btn = e.target.closest('.frp-action-btn');
        if (!btn) return;
        var card   = btn.closest('.frp-lead-card');
        var leadId = card ? card.dataset.leadId : null;
        var status = btn.dataset.status;
        if (!leadId || !status) return;

        btn.disabled = true;
        btn.textContent = 'Saving…';

        fetch('/wp-json/frp/v1/leads/' + leadId + '/status', {
          method:  'POST',
          headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': frpDashNonce },
          body:    JSON.stringify({ status: status }),
        })
        .then(function (r) { return r.json().then(function(b){ return { ok: r.ok, body: b }; }); })
        .then(function (res) {
          if (res.ok) {
            frpUpdateCardStatus(card, status);
          } else {
            var msg = (res.body && res.body.message) ? res.body.message : 'Error updating lead.';
            alert(msg);
            btn.disabled = false;
            btn.textContent = btn.dataset.label;
          }
        })
        .catch(function () {
          alert('Network error. Please try again.');
          btn.disabled = false;
          btn.textContent = btn.dataset.label;
        });
      });

      function frpUpdateCardStatus(card, status) {
        var statusEl = card.querySelector('.frp-status-pill');
        if (statusEl) {
          statusEl.className = 'frp-status-pill frp-status-' + status;
          statusEl.textContent = frpStatusLabel(status);
        }
        // Hide action buttons once responded
        var actions = card.querySelector('.frp-lead-actions');
        if (actions) actions.style.display = 'none';
        // Hide countdown
        var countdown = card.querySelector('.frp-countdown');
        if (countdown) countdown.style.display = 'none';
      }

      function frpStatusLabel(s) {
        return { contacted:'Contacted', won:'Won', lost:'Lost', pending:'Pending', missed:'Missed' }[s] || s;
      }

      // ── Countdown timers ──────────────────────────────────────────────
      function frpTickCountdowns() {
        document.querySelectorAll('[data-deadline]').forEach(function (el) {
          var deadline = parseInt(el.dataset.deadline, 10);
          var now      = Math.floor(Date.now() / 1000);
          var diff     = deadline - now;
          if (diff <= 0) {
            el.textContent = 'Overdue';
            el.classList.add('frp-countdown-overdue');
          } else {
            var h = Math.floor(diff / 3600);
            var m = Math.floor((diff % 3600) / 60);
            var s = diff % 60;
            el.textContent = (h > 0 ? h + 'h ' : '') + m + 'm ' + s + 's';
          }
        });
      }
      setInterval(frpTickCountdowns, 1000);
      frpTickCountdowns();

      // ── Analytics loader ──────────────────────────────────────────────
      function frpLoadAnalytics() {
        var container = document.getElementById('frp-tab-analytics');
        container.innerHTML = '<div class="frp-analytics-loading">Loading analytics…</div>';

        fetch('/wp-json/frp/v1/me/stats', {
          headers: { 'X-WP-Nonce': frpDashNonce }
        })
        .then(function(r){ return r.json(); })
        .then(function(data){ frpRenderAnalytics(container, data); })
        .catch(function(){ container.innerHTML = '<p class="frp-error">Failed to load analytics.</p>'; });
      }

      function frpRenderAnalytics(container, d) {
        var rateColor = d.response_rate === null ? '' :
          d.response_rate >= 0.8 ? 'frp-indicator-green' :
          d.response_rate >= 0.5 ? 'frp-indicator-amber' : 'frp-indicator-red';

        var sparkBars = (d.leads_by_week || []).map(function(w){
          return '<span class="frp-spark-bar" style="height:' + Math.max(4, (w.count * 16)) + 'px" title="' + w.week + ': ' + w.count + '"></span>';
        }).join('');

        container.innerHTML =
          '<div class="frp-analytics-cards">' +
            '<div class="frp-stat-card">' +
              '<div class="frp-stat-value">' + (d.leads_received_total || 0) + '</div>' +
              '<div class="frp-stat-label">Leads Received</div>' +
              '<div class="frp-sparkline">' + sparkBars + '</div>' +
            '</div>' +
            '<div class="frp-stat-card">' +
              '<div class="frp-stat-value ' + rateColor + '">' + (d.response_rate !== null ? Math.round(d.response_rate * 100) + '%' : '—') + '</div>' +
              '<div class="frp-stat-label">Response Rate</div>' +
            '</div>' +
            '<div class="frp-stat-card">' +
              '<div class="frp-stat-value">' + (d.win_rate !== null ? Math.round(d.win_rate * 100) + '%' : '—') + '</div>' +
              '<div class="frp-stat-label">Win Rate</div>' +
            '</div>' +
          '</div>' +
          '<div class="frp-recent-leads">' +
            '<h3 class="frp-section-title">Recent Leads</h3>' +
            (d.recent_leads && d.recent_leads.length ?
              frpBuildLeadsTable(d.recent_leads) :
              '<p class="frp-empty-state">No leads yet.</p>'
            ) +
          '</div>';

        // Wire up table sort after rendering
        frpWireSortableTable(container, d.recent_leads || []);
      }

      // Build sortable leads table HTML (default sort: date desc)
      function frpBuildLeadsTable(leads, sortKey, sortDir) {
        sortKey = sortKey || 'assigned_at';
        sortDir = sortDir || 'desc';
        var sorted = leads.slice().sort(function(a, b) {
          var av = a[sortKey] || '', bv = b[sortKey] || '';
          if (av < bv) return sortDir === 'asc' ? -1 :  1;
          if (av > bv) return sortDir === 'asc' ?  1 : -1;
          return 0;
        });
        var rows = sorted.map(function(l) {
          var date = l.assigned_at ? new Date(l.assigned_at * 1000).toLocaleDateString() : '—';
          return '<tr>' +
            '<td>' + frpEsc(l.service) + '</td>' +
            '<td>' + frpEsc(l.city) + '</td>' +
            '<td><span class="frp-urgency-pill frp-urgency-' + frpEsc(l.urgency) + '">' + frpEsc(l.urgency) + '</span></td>' +
            '<td><span class="frp-status-pill frp-status-' + frpEsc(l.status) + '">' + frpStatusLabel(l.status) + '</span></td>' +
            '<td data-ts="' + (l.assigned_at || 0) + '">' + date + '</td>' +
          '</tr>';
        }).join('');
        var arrowDate   = sortKey === 'assigned_at' ? (sortDir === 'asc' ? ' ↑' : ' ↓') : '';
        var arrowStatus = sortKey === 'status'      ? (sortDir === 'asc' ? ' ↑' : ' ↓') : '';
        return '<table class="frp-leads-table" data-sort-key="' + sortKey + '" data-sort-dir="' + sortDir + '">' +
          '<thead><tr>' +
            '<th>Service</th>' +
            '<th>City</th>' +
            '<th>Urgency</th>' +
            '<th class="frp-sortable" data-sort="status">Status' + arrowStatus + '</th>' +
            '<th class="frp-sortable" data-sort="assigned_at">Date' + arrowDate + '</th>' +
          '</tr></thead>' +
          '<tbody>' + rows + '</tbody>' +
        '</table>';
      }

      function frpWireSortableTable(container, leads) {
        container.querySelectorAll('.frp-sortable').forEach(function(th) {
          th.style.cursor = 'pointer';
          th.addEventListener('click', function() {
            var table   = th.closest('table');
            var sortKey = th.dataset.sort;
            var prevKey = table.dataset.sortKey;
            var prevDir = table.dataset.sortDir || 'desc';
            var newDir  = (sortKey === prevKey && prevDir === 'desc') ? 'asc' : 'desc';
            var tbody   = table.querySelector('tbody');
            var parent  = table.parentNode;
            parent.innerHTML = frpBuildLeadsTable(leads, sortKey, newDir);
            frpWireSortableTable(container, leads);
          });
        });
      }

      function frpEsc(s) {
        if (!s) return '';
        return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
      }
    })();
    </script>
    <?php
    // Inline the nonce directly — wp_localize_script requires an array and
    // wp-api-request is not guaranteed to be enqueued on all themes.
    echo '<script>var frpDashNonce = "' . esc_js( wp_create_nonce( 'wp_rest' ) ) . '";</script>';
    return ob_get_clean();
}

// ── Lead Cards ────────────────────────────────────────────────────────────────

function frp_dashboard_leads_html( $pro_id ) {
    $q = new WP_Query( [
        'post_type'      => 'frp_lead',
        'posts_per_page' => 50,
        'post_status'    => 'publish',
        'meta_query'     => [[
            'key'     => 'lead_routing_history',
            'value'   => '"pro_id":' . $pro_id,
            'compare' => 'LIKE',
        ]],
        'orderby' => 'date',
        'order'   => 'DESC',
    ] );

    if ( ! $q->have_posts() ) {
        return '<div class="frp-empty-state"><p>No leads yet. Hang tight.</p></div>';
    }

    $active_html = '';
    $missed_html = '';
    $now         = time();

    foreach ( $q->posts as $lead ) {
        $id      = $lead->ID;
        $history = json_decode( get_post_meta( $id, 'lead_routing_history', true ) ?: '[]', true );

        // Find this pro's entry
        $my_entry = null;
        foreach ( $history as $entry ) {
            if ( (int) $entry['pro_id'] === $pro_id ) { $my_entry = $entry; break; }
        }
        if ( ! $my_entry ) continue;

        $status    = $my_entry['status'] ?? 'pending';
        $city      = esc_html( get_post_meta( $id, 'lead_city',          true ) );
        $svc       = esc_html( get_post_meta( $id, 'lead_service',       true ) );
        $urgency   = get_post_meta( $id, 'lead_urgency',     true );
        $score     = (int) get_post_meta( $id, 'lead_score',       true );
        $phone_raw = get_post_meta( $id, 'lead_phone',       true );
        $prop_type = esc_html( get_post_meta( $id, 'lead_property_type', true ) );
        $insurance = esc_html( get_post_meta( $id, 'lead_has_insurance', true ) );
        $scope     = esc_html( get_post_meta( $id, 'lead_scope',         true ) );
        $zip       = esc_html( get_post_meta( $id, 'lead_zip',           true ) );
        $notes     = esc_html( get_post_meta( $id, 'lead_notes',         true ) );
        $deadline  = (int) get_post_meta( $id, 'lead_response_deadline', true );

        $urgency_class = $urgency === 'emergency' ? 'frp-urgency-emergency' : ( $urgency === 'urgent' ? 'frp-urgency-urgent' : 'frp-urgency-standard' );
        $urgency_label = esc_html( ucfirst( $urgency ?: 'Standard' ) );
        $status_label  = ucfirst( $status );

        // Countdown (only for pending with a future deadline)
        $countdown_html = '';
        if ( $status === 'pending' && $deadline && $deadline > $now ) {
            $countdown_html = '<span class="frp-countdown" data-deadline="' . esc_attr( $deadline ) . '">…</span>';
        }

        // Action buttons (only for pending, current assignee)
        $current_assignee = (int) get_post_meta( $id, 'lead_current_assignee', true );
        $actions_html     = '';
        if ( $status === 'pending' && $current_assignee === $pro_id ) {
            $actions_html =
                '<div class="frp-lead-actions">' .
                '<button class="frp-action-btn frp-btn-primary"  data-status="contacted" data-label="Mark Contacted">Mark Contacted</button>' .
                '<button class="frp-action-btn frp-btn-outline"  data-status="won"       data-label="Mark Won">Mark Won</button>' .
                '<button class="frp-action-btn frp-btn-outline frp-btn-danger" data-status="lost" data-label="Mark Lost">Mark Lost</button>' .
                '</div>';
        }

        $phone_href    = esc_url( 'tel:' . $phone_raw );
        $phone_display = esc_html( $phone_raw );

        $card_html =
            '<div class="frp-lead-card' . ( $status === 'missed' ? ' frp-lead-missed' : '' ) . '" data-lead-id="' . esc_attr( $id ) . '">' .
              '<div class="frp-lead-header">' .
                '<span class="frp-service-badge">' . esc_html( $svc ) . '</span>' .
                '<span class="frp-city">' . $city . '</span>' .
                '<span class="frp-urgency-pill ' . esc_attr( $urgency_class ) . '">' . $urgency_label . '</span>' .
                '<span class="frp-score-badge">Score ' . $score . '</span>' .
                ( $status === 'pending' ? $countdown_html : '<span class="frp-status-pill frp-status-' . esc_attr( $status ) . '">' . esc_html( $status_label ) . '</span>' ) .
                '<span class="frp-expand-icon" aria-hidden="true">›</span>' .
              '</div>' .
              '<div class="frp-lead-details">' .
                '<dl class="frp-lead-meta">' .
                  ( $prop_type ? '<dt>Property</dt><dd>' . $prop_type . '</dd>' : '' ) .
                  ( $insurance ? '<dt>Insurance</dt><dd>' . $insurance . '</dd>' : '' ) .
                  ( $scope     ? '<dt>Scope</dt><dd>' . $scope . '</dd>'         : '' ) .
                  ( $zip       ? '<dt>ZIP</dt><dd>' . $zip . '</dd>'             : '' ) .
                  ( $notes     ? '<dt>Notes</dt><dd>' . $notes . '</dd>'         : '' ) .
                '</dl>' .
                ( $status !== 'missed'
                    ? '<a class="frp-phone-link" href="' . $phone_href . '">' . $phone_display . '</a>' . $actions_html
                    : '<p class="frp-missed-notice">You missed this lead — it was reassigned.</p>'
                ) .
              '</div>' .
            '</div>';

        if ( $status === 'missed' ) {
            $missed_html .= $card_html;
        } else {
            $active_html .= $card_html;
        }
    }

    $out = '<div class="frp-leads-list">';
    $out .= $active_html ?: '<p class="frp-empty-state">No active leads.</p>';
    if ( $missed_html ) {
        $out .= '<h3 class="frp-section-title frp-missed-title">Missed Leads</h3>' . $missed_html;
    }
    $out .= '</div>';
    return $out;
}

// ── Profile + Billing (unchanged) ────────────────────────────────────────────

function frp_dashboard_profile_html( $pro_id ) {
    $pro = get_post( $pro_id );
    if ( ! $pro ) {
        return '<p class="frp-dashboard-error">Profile not found. Contact support.</p>';
    }
    return '<h3>' . esc_html( $pro->post_title ) . '</h3>' .
           '<p>Listing tier: ' . esc_html( get_post_meta( $pro_id, 'listing_tier', true ) ?: 'free' ) . '</p>';
}

function frp_dashboard_billing_html( $pro_id ) {
    $cust = get_post_meta( $pro_id, 'frp_stripe_customer_id', true );
    if ( ! $cust ) {
        return '<p>No subscription. <a href="#" onclick="frpStartCheckout(\'paid\')">Start Paid Listing</a></p>';
    }
    return '<p><a href="' . esc_url( rest_url( 'frp/v1/billing/portal' ) ) . '">Manage subscription →</a></p>';
}

function frp_dashboard_login_form() {
    ob_start();
    ?>
    <div class="frp-dashboard-login">
      <h2>Contractor sign in</h2>
      <?php echo wp_login_form( [ 'echo' => false, 'redirect' => home_url( '/contractor/dashboard/' ) ] ); ?>
      <p><a href="<?php echo esc_url( wp_lostpassword_url() ); ?>">Forgot password?</a></p>
    </div>
    <?php
    return ob_get_clean();
}

// ── REST endpoint: leads-html (keep for backward compat) ─────────────────────

add_action( 'rest_api_init', function () {
    register_rest_route( 'frp/v1', '/me/leads-html', [
        'methods'             => 'GET',
        'callback'            => function () {
            $pro_id = frp_current_pro_id();
            return rest_ensure_response( [ 'html' => frp_dashboard_leads_html( $pro_id ) ] );
        },
        'permission_callback' => function () {
            return is_user_logged_in() && frp_current_pro_id();
        },
    ] );
} );

// ── CSS ───────────────────────────────────────────────────────────────────────

function frp_dashboard_styles() {
    ?>
    <style>
    /* ── Reset & Base ─────────────────────────────────────────── */
    .frp-dashboard { font-family: 'Inter', system-ui, sans-serif; color: #191c1d; max-width: 900px; margin: 0 auto; padding: 0 0 48px; }

    /* ── Tabs ─────────────────────────────────────────────────── */
    .frp-dash-tabs { display: flex; gap: 2px; background: #edeeef; padding: 4px; border-radius: 10px; margin-bottom: 24px; }
    .frp-tab-btn { flex: 1; padding: 8px 16px; border: none; background: transparent; border-radius: 8px; font-size: 14px; font-weight: 600; color: #43474f; cursor: pointer; transition: background 0.15s, color 0.15s; }
    .frp-tab-btn.active { background: #fff; color: #001e40; box-shadow: 0 1px 4px rgba(0,0,0,.08); }
    .frp-tab-pane { display: none; }
    .frp-tab-pane.active { display: block; }

    /* ── Lead Cards ───────────────────────────────────────────── */
    .frp-lead-card { background: #fff; border: 1px solid #e1e3e4; border-radius: 12px; margin-bottom: 12px; box-shadow: 0 1px 4px rgba(0,0,0,.05); overflow: hidden; transition: box-shadow 0.15s; }
    .frp-lead-card:hover { box-shadow: 0 4px 12px rgba(0,0,0,.09); }
    .frp-lead-header { display: flex; align-items: center; gap: 10px; padding: 14px 16px; cursor: pointer; flex-wrap: wrap; }
    .frp-expand-icon { margin-left: auto; color: #737780; font-size: 18px; transition: transform 0.2s; line-height: 1; }
    .frp-lead-card.frp-expanded .frp-expand-icon { transform: rotate(90deg); }
    .frp-lead-details { display: none; padding: 0 16px 16px; border-top: 1px solid #f3f4f5; }
    .frp-lead-card.frp-expanded .frp-lead-details { display: block; }
    .frp-lead-meta { display: grid; grid-template-columns: max-content 1fr; gap: 6px 12px; margin: 14px 0; font-size: 13px; }
    .frp-lead-meta dt { color: #737780; font-weight: 600; }
    .frp-lead-meta dd { margin: 0; }

    /* Missed cards */
    .frp-lead-missed { opacity: .65; }
    .frp-missed-title { font-size: 12px; text-transform: uppercase; letter-spacing: .06em; color: #737780; margin: 24px 0 10px; }
    .frp-missed-notice { font-size: 13px; color: #737780; font-style: italic; margin: 6px 0 0; }

    /* ── Pills & Badges ───────────────────────────────────────── */
    .frp-service-badge { background: #d5e3ff; color: #001b3c; font-size: 12px; font-weight: 700; padding: 3px 8px; border-radius: 6px; }
    .frp-city { font-size: 14px; font-weight: 500; }
    .frp-score-badge { font-size: 12px; color: #43474f; background: #f3f4f5; padding: 3px 8px; border-radius: 6px; font-weight: 600; }

    .frp-urgency-pill { font-size: 11px; font-weight: 700; text-transform: uppercase; padding: 3px 8px; border-radius: 20px; letter-spacing: .04em; }
    .frp-urgency-emergency { background: #ffdad6; color: #93000a; }
    .frp-urgency-urgent    { background: #ffe0b2; color: #7a3500; }
    .frp-urgency-standard  { background: #e1e3e4; color: #43474f; }

    .frp-status-pill { font-size: 11px; font-weight: 700; text-transform: uppercase; padding: 3px 8px; border-radius: 20px; }
    .frp-status-won       { background: #c8f0c8; color: #1a5c1a; }
    .frp-status-contacted { background: #dce8ff; color: #0c3a7a; }
    .frp-status-lost      { background: #e1e3e4; color: #43474f; }
    .frp-status-missed    { background: #ffdad6; color: #93000a; }
    .frp-status-pending   { background: #ffe0b2; color: #7a3500; }

    /* Countdown */
    .frp-countdown { font-family: 'Courier New', monospace; font-size: 12px; background: #001e40; color: #a7c8ff; padding: 3px 8px; border-radius: 6px; font-weight: 700; }
    .frp-countdown-overdue { background: #ba1a1a; color: #fff; }

    /* Phone link */
    .frp-phone-link { display: inline-block; margin-top: 8px; font-size: 16px; font-weight: 700; color: #001e40; text-decoration: none; border-bottom: 2px solid #a7c8ff; }
    .frp-phone-link:hover { color: #003366; }

    /* Action buttons */
    .frp-lead-actions { display: flex; gap: 8px; margin-top: 14px; flex-wrap: wrap; }
    .frp-action-btn { padding: 9px 18px; border-radius: 8px; font-size: 13px; font-weight: 700; cursor: pointer; transition: opacity 0.15s, transform 0.1s; border: 2px solid transparent; }
    .frp-action-btn:active { transform: scale(.97); }
    .frp-action-btn:disabled { opacity: .5; cursor: not-allowed; }
    .frp-btn-primary { background: #001e40; color: #fff; border-color: #001e40; }
    .frp-btn-primary:hover { background: #003366; }
    .frp-btn-outline { background: transparent; color: #001e40; border-color: #001e40; }
    .frp-btn-outline:hover { background: #f0f4ff; }
    .frp-btn-danger { color: #ba1a1a; border-color: #ba1a1a; }
    .frp-btn-danger:hover { background: #fff0f0; }

    /* ── Analytics ────────────────────────────────────────────── */
    .frp-analytics-cards { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 16px; margin-bottom: 32px; }
    .frp-stat-card { background: #fff; border: 1px solid #e1e3e4; border-radius: 12px; padding: 20px 24px; box-shadow: 0 1px 4px rgba(0,0,0,.05); }
    .frp-stat-value { font-size: 36px; font-weight: 800; color: #001e40; line-height: 1.1; }
    .frp-stat-label { font-size: 12px; font-weight: 600; text-transform: uppercase; letter-spacing: .06em; color: #737780; margin-top: 4px; }
    .frp-indicator-green { color: #1a5c1a; }
    .frp-indicator-amber { color: #7a3500; }
    .frp-indicator-red   { color: #ba1a1a; }
    .frp-sparkline { display: flex; align-items: flex-end; gap: 3px; height: 32px; margin-top: 12px; }
    .frp-spark-bar { flex: 1; background: #a7c8ff; border-radius: 2px 2px 0 0; min-height: 4px; }

    /* Analytics table */
    .frp-leads-table { width: 100%; border-collapse: collapse; font-size: 13px; }
    .frp-leads-table th { text-align: left; padding: 8px 12px; font-weight: 600; color: #43474f; border-bottom: 2px solid #e1e3e4; font-size: 11px; text-transform: uppercase; letter-spacing: .06em; }
    .frp-leads-table th.frp-sortable { cursor: pointer; user-select: none; }
    .frp-leads-table th.frp-sortable:hover { color: #001e40; }
    .frp-leads-table td { padding: 10px 12px; border-bottom: 1px solid #f3f4f5; }
    .frp-leads-table tr:last-child td { border-bottom: none; }
    .frp-leads-table tr:hover td { background: #f8f9fa; }

    .frp-section-title { font-size: 13px; font-weight: 700; text-transform: uppercase; letter-spacing: .06em; color: #43474f; margin: 0 0 14px; }
    .frp-analytics-loading { padding: 32px; text-align: center; color: #737780; }
    .frp-empty-state { color: #737780; font-size: 14px; padding: 16px 0; }
    .frp-dashboard-error { color: #93000a; padding: 12px; background: #ffdad6; border-radius: 8px; }
    </style>
    <?php
}
```

- [ ] **Step 4: Run dashboard tests**

```bash
node --test tests/dashboard.test.mjs 2>&1
```

Expected: PASS on all tests (existing + new analytics tab test).

- [ ] **Step 5: Run all tests**

```bash
node --test tests/leads.test.mjs tests/dashboard.test.mjs 2>&1
```

Expected: All tests pass.

- [ ] **Step 6: Commit**

```bash
git add wordpress-plugins/frp-dashboard.php tests/dashboard.test.mjs
git commit -m "feat: enhanced dashboard with lead cards, countdown, status actions, and analytics tab"
```

---

## Final Step: Finish the branch

After all tasks pass, run `superpowers:finishing-a-development-branch` to complete the work.
