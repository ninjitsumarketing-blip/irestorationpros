# Pro Profile CTA & Lead Capture Implementation Plan

> **For agentic workers:** REQUIRED: Use superpowers:subagent-driven-development (if subagents available) or superpowers:executing-plans to implement this plan. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Implement tier-gated CTAs on restoration_pro profile pages — hiding the phone number from gated tiers and capturing every contact attempt as a lead via an 8-field modal form, while giving paid/featured pros a visible phone + tracked call link.

**Architecture:** Two mu-plugin files. `frp-directory.php` (existing) gains a new `profile_form` lead source with its own rate-limit bucket, required email validation, `preferred_pro_id` direct dispatch bypassing the matcher, and two new meta fields. `frp-pro-template.php` (new) intercepts `restoration_pro` single post views via `template_include` and renders the tier-gated profile page entirely in PHP + vanilla JS fetch. No theme changes. No build step.

**Tech Stack:** PHP 7.4+, WordPress mu-plugins, WP REST API, vanilla JS (`fetch`), Node.js integration tests (`node:test`) against staging, SiteGround File Manager for deployment.

---

## File Map

| File | Action | Responsibility |
|---|---|---|
| `wordpress-plugins/frp-directory.php` | **Modify** | New `profile_form` source: rate bucket, meta registration, handler validation, dispatch, webhook payload, admin label |
| `wordpress-plugins/frp-pro-template.php` | **Create** | Profile page template via `template_include`: tier gate, sidebar, 8-field modal form, JS fetch, sticky bar, JSON-LD |
| `tests/profile-form-lead.test.mjs` | **Create** | Integration tests for all backend changes |

---

## Chunk 1: Backend — frp-directory.php + tests

### Task 1: Write integration test file (failing — proves what's missing)

**Files:**
- Create: `tests/profile-form-lead.test.mjs`

- [ ] **Step 1: Create the test file**

```javascript
import test from 'node:test';
import assert from 'node:assert/strict';
import { frpPost, frpPostLead } from './helpers/wp-client.mjs';
import { seedPro, resetTestPros, resetTestLeads, readMeta } from './helpers/staging.mjs';

let proId = 0;

// Factory — overrides replace any key
function profilePayload(overrides = {}) {
  return {
    phone: `555${Date.now().toString().slice(-7)}`,
    lead_contact_email: `lead${Date.now()}@example.com`,
    zip: '90210',
    service: 'water-damage',
    urgency: 'now',
    property_type: 'residential',
    has_insurance: 'not-sure',
    source: 'profile_form',
    preferred_pro_id: proId,
    ...overrides,
  };
}

// Tag a lead post_id as test_fixture=1 so resetTestLeads() cleans it up.
// Required for every lead created directly via frpPostLead (not via createTestLead).
async function tagLead(leadId) {
  await frpPost('/wp-json/frp/v1/admin/set-post-meta',
    { post_id: leadId, meta: { test_fixture: '1' } }, { auth: true });
}

test.before(async () => {
  await resetTestLeads();
  await resetTestPros();
  const result = await seedPro({ business_name: 'Profile Form Test Pro', listing_tier: 'free' });
  proId = result.pro_id;
});

test.after(async () => {
  await resetTestLeads();
  await resetTestPros();
});

// ── source allowlist ────────────────────────────────────────────────

test('profile_form source accepted — returns 200', async () => {
  const { status, body } = await frpPostLead('/wp-json/frp/v1/leads', profilePayload());
  assert.equal(status, 200, JSON.stringify(body));
  assert.ok(body.lead_id, 'response must include lead_id');
  await tagLead(body.lead_id);
});

// ── lead_contact_email validation ───────────────────────────────────

test('profile_form missing email returns 400', async () => {
  const p = profilePayload();
  delete p.lead_contact_email;
  const { status } = await frpPostLead('/wp-json/frp/v1/leads', p);
  assert.equal(status, 400);
});

test('profile_form invalid email format returns 400', async () => {
  const { status } = await frpPostLead('/wp-json/frp/v1/leads',
    profilePayload({ lead_contact_email: 'notanemail' })
  );
  assert.equal(status, 400);
});

// ── preferred_pro_id validation ─────────────────────────────────────

test('profile_form missing preferred_pro_id returns 400', async () => {
  const p = profilePayload();
  delete p.preferred_pro_id;
  const { status } = await frpPostLead('/wp-json/frp/v1/leads', p);
  assert.equal(status, 400);
});

test('profile_form preferred_pro_id zero returns 400', async () => {
  const { status } = await frpPostLead('/wp-json/frp/v1/leads',
    profilePayload({ preferred_pro_id: 0 })
  );
  assert.equal(status, 400);
});

test('profile_form nonexistent preferred_pro_id returns 400', async () => {
  const { status } = await frpPostLead('/wp-json/frp/v1/leads',
    profilePayload({ preferred_pro_id: 999999999 })
  );
  assert.equal(status, 400);
});

// ── meta stored correctly ───────────────────────────────────────────

test('profile_form lead stores lead_contact_email and property_address', async () => {
  const email = `stored${Date.now()}@example.com`;
  const addr = '123 Main St';
  const { status, body } = await frpPostLead('/wp-json/frp/v1/leads',
    profilePayload({ lead_contact_email: email, property_address: addr })
  );
  assert.equal(status, 200, JSON.stringify(body));
  await tagLead(body.lead_id);
  assert.equal(await readMeta(body.lead_id, 'lead_contact_email'), email);
  assert.equal(await readMeta(body.lead_id, 'property_address'), addr);
  assert.equal(await readMeta(body.lead_id, 'lead_source'), 'profile_form');
  // lead_email must also carry the homeowner email (dual-write via $email variable overwrite)
  assert.equal(await readMeta(body.lead_id, 'lead_email'), email);
});

test('profile_form lead stores preferred_pro_id', async () => {
  const { status, body } = await frpPostLead('/wp-json/frp/v1/leads', profilePayload());
  assert.equal(status, 200, JSON.stringify(body));
  await tagLead(body.lead_id);
  assert.equal(await readMeta(body.lead_id, 'preferred_pro_id'), String(proId));
});

// ── duplicate bypass ────────────────────────────────────────────────

test('profile_form bypasses duplicate detection — same phone produces two leads', async () => {
  const phone = `555${Date.now().toString().slice(-7)}`;
  const { body: b1 } = await frpPostLead('/wp-json/frp/v1/leads', profilePayload({ phone }));
  const { body: b2 } = await frpPostLead('/wp-json/frp/v1/leads', profilePayload({ phone }));
  assert.ok(b1.lead_id, 'first lead created');
  assert.ok(b2.lead_id, 'second lead created');
  assert.notEqual(b1.lead_id, b2.lead_id, 'must be two distinct leads');
  // Tag both for cleanup
  if (b1.lead_id) await tagLead(b1.lead_id);
  if (b2.lead_id) await tagLead(b2.lead_id);
});

// ── backwards compatibility ─────────────────────────────────────────

test('guided_flow still accepts missing email without 400', async () => {
  const { status, body } = await frpPostLead('/wp-json/frp/v1/leads', {
    phone: `555${Date.now().toString().slice(-7)}`,
    zip: '90210',
    service: 'water-damage',
    urgency: 'now',
    property_type: 'residential',
    has_insurance: 'yes',
    source: 'guided_flow',
  });
  assert.equal(status, 200, `guided_flow without email failed: ${JSON.stringify(body)}`);
  if (body.lead_id) await tagLead(body.lead_id);
});
```

- [ ] **Step 2: Run tests — confirm they fail before code changes**

```bash
NODE_ENV=test node --test tests/profile-form-lead.test.mjs
```

Expected: most tests fail. "profile_form source accepted" may return 200 but with wrong behavior (coerced to `guided_flow`). "missing email returns 400" fails because `guided_flow` doesn't require email. All meta-storage tests fail. Duplicate-bypass test fails (duplicate detection fires, same lead_id returned both times). Backwards-compat test passes (it works today).

- [ ] **Step 3: Commit test file**

```bash
git add tests/profile-form-lead.test.mjs
git commit -m "test(profile-form): add failing integration tests for profile_form lead source"
```

---

### Task 2: frp-directory.php — source allowlist, meta registration, admin label

**Files:**
- Modify: `wordpress-plugins/frp-directory.php`

These are three independent additions: the `$valid_sources` array, the meta registration function, and the admin meta box label string. None touch the handler flow.

- [ ] **Step 1: Add `profile_form` to `$valid_sources` (line 1081)**

Find this line:
```php
$valid_sources  = [ 'guided_flow', 'followup_modal', 'emergency_flow' ];
```
Replace with:
```php
$valid_sources  = [ 'guided_flow', 'followup_modal', 'emergency_flow', 'profile_form' ];
```

- [ ] **Step 2: Register new meta keys in `frp_register_lead_meta()`**

The function is at line 169. Find `$string_fields = [` and add two new keys to the array:
```php
'lead_contact_email', 'property_address',
```
Add them to the end of `$string_fields`, before the closing `]`. The array currently ends with `'lead_customer_notes'` — add after it:
```php
$string_fields = [
    'lead_name', 'lead_email', 'lead_phone', 'lead_zip',
    'lead_address', 'lead_city', 'lead_service', 'lead_urgency',
    'lead_property_type', 'lead_has_insurance', 'lead_scope',
    'lead_status', 'lead_source', 'lead_assigned_pros',
    'lead_update_token', 'lead_update_token_expiry',
    'lead_page_url', 'dispatch_tier', 'date_submitted',
    'lead_customer_notes',
    'lead_contact_email', 'property_address',   // new — profile_form fields
];
```

After the `foreach` loop that registers string fields (after line ~186), add the integer meta registration for `preferred_pro_id`:
```php
register_post_meta( 'frp_lead', 'preferred_pro_id', [
    'show_in_rest'  => false,
    'single'        => true,
    'type'          => 'integer',
    'auth_callback' => function() { return current_user_can( 'manage_options' ); },
] );
```

- [ ] **Step 3: Update admin meta box label (line ~2161)**

Find this line in `frp_render_meta_box()`:
```php
'listing_tier'        => [ 'Tier — free / featured / premium', 'text' ],
```
Replace with:
```php
'listing_tier'        => [ 'Tier — free / basic / paid / featured / premium', 'text' ],
```
> ⚠️ **Em-dash warning:** The dash in `'Tier —'` is U+2014 (em-dash), not a hyphen. A search for `'Tier - free'` will find nothing. Use copy-paste from the existing source file to locate the line, not a typed search string.

- [ ] **Step 4: Commit**

```bash
git add wordpress-plugins/frp-directory.php
git commit -m "feat(leads): add profile_form source, register new lead meta keys, fix tier label"
```

---

### Task 3: frp-directory.php — handler changes (rate-limit, validation, dispatch, webhook)

**Files:**
- Modify: `wordpress-plugins/frp-directory.php`

This task makes all the handler flow changes in `frp_lead_create_handler` and extends `frp_fire_lead_webhook`. Work through these changes in order — each modifies a different section of the function.

- [ ] **Step 1: Replace the rate-limit block**

Delete lines 1071–1074:
```php
    // 2. Rate limiting — 3 submissions per IP per 15 minutes
    if ( ! frp_check_rate_limit( 'lead_create', 3, 15 * MINUTE_IN_SECONDS ) ) {
        return new WP_Error( 'rate_limited', 'Too many requests. Please try again shortly.', [ 'status' => 429 ] );
    }
```

After the `$source` assignment block (after line 1084, before the `$is_emergency` line), insert:
```php
    // 2. Rate limiting — profile_form gets its own higher-limit bucket (homeowners browse multiple pros)
    $rl_bucket = ( $source === 'profile_form' ) ? 'lead_create_profile' : 'lead_create';
    $rl_limit  = ( $source === 'profile_form' ) ? 10 : 3;
    if ( ! frp_check_rate_limit( $rl_bucket, $rl_limit, 15 * MINUTE_IN_SECONDS ) ) {
        return new WP_Error( 'rate_limited', 'Too many requests. Please try again shortly.', [ 'status' => 429 ] );
    }
```

The result should look like:
```php
    $source   = in_array( $request->get_param( 'source' ), $valid_sources, true )
                    ? $request->get_param( 'source' ) : 'guided_flow';

    // 2. Rate limiting — profile_form gets its own higher-limit bucket (homeowners browse multiple pros)
    $rl_bucket = ( $source === 'profile_form' ) ? 'lead_create_profile' : 'lead_create';
    $rl_limit  = ( $source === 'profile_form' ) ? 10 : 3;
    if ( ! frp_check_rate_limit( $rl_bucket, $rl_limit, 15 * MINUTE_IN_SECONDS ) ) {
        return new WP_Error( 'rate_limited', 'Too many requests. Please try again shortly.', [ 'status' => 429 ] );
    }

    // For emergency_flow, use defaults for scoring fields if not provided
    $is_emergency = ( $source === 'emergency_flow' );
```

- [ ] **Step 2: Read new params after `$assigned_pro` (line 1105)**

After line 1105:
```php
    $assigned_pro = (int) ( $request->get_param( 'assigned_pro' ) ?? 0 );
```
Add:
```php
    // New params for profile_form
    $preferred_pro_id = absint( $request->get_param( 'preferred_pro_id' ) ?? 0 );
    $property_address = sanitize_text_field( $request->get_param( 'property_address' ) ?? '' );
```

- [ ] **Step 3: Add profile_form email override + preferred_pro_id validation**

After the existing silent-clear block (after line 1120):
```php
    if ( $email && ! is_email( $email ) ) {
        $email = ''; // Silently clear invalid email rather than reject
    }
```
Add:
```php
    // profile_form: overwrite $email from lead_contact_email param; validate strictly.
    // IMPORTANT: After this block, $email holds the validated homeowner email for ALL
    // downstream code — the lead_email meta write (step 10), the webhook $lead_data['email'],
    // and the duplicate-detection call (which is skipped anyway for profile_form).
    // Do NOT introduce a separate variable — reusing $email keeps all downstream consistent.
    if ( $source === 'profile_form' ) {
        $email = sanitize_email( $request->get_param( 'lead_contact_email' ) ?? '' );
        if ( ! $email || ! is_email( $email ) ) {
            return new WP_Error( 'bad_request', 'A valid email address is required.', [ 'status' => 400 ] );
        }
        // preferred_pro_id is required for profile_form; 0 means absent (absint returns 0 for null/'')
        if ( ! $preferred_pro_id || get_post_type( $preferred_pro_id ) !== 'restoration_pro' ) {
            return new WP_Error( 'bad_request', 'A valid pro ID is required.', [ 'status' => 400 ] );
        }
    }
```

- [ ] **Step 4: Wrap duplicate detection in source guard**

Wrap the entire duplicate detection block (lines 1144–1164) in a source check. The new outer `if` closing brace falls directly after the closing `}` of the existing `if ($dup_id)` block at line 1164:

```php
    // 5. Duplicate detection: same phone OR email, status=new, within 24 hours
    // Skipped for profile_form — each pro-specific submission is an independent intent signal
    if ( $source !== 'profile_form' ) {
        $dup_id = frp_find_duplicate_lead( $phone, $email );
        if ( $dup_id ) {
            // Refresh token if needed
            $existing_expiry = get_post_meta( $dup_id, 'lead_update_token_expiry', true );
            if ( ! $existing_expiry || strtotime( $existing_expiry ) < time() ) {
                $tok = frp_generate_lead_token();
                update_post_meta( $dup_id, 'lead_update_token', $tok['token'] );
                update_post_meta( $dup_id, 'lead_update_token_expiry', $tok['expiry'] );
                $return_token = $tok['token'];
            } else {
                $return_token = get_post_meta( $dup_id, 'lead_update_token', true );
            }
            $has_coverage = ! empty( json_decode( get_post_meta( $dup_id, 'lead_assigned_pros', true ), true ) );
            return rest_ensure_response( [
                'success'           => true,
                'lead_id'           => $dup_id,
                'lead_update_token' => $return_token,
                'has_coverage'      => $has_coverage,
            ] );
        }
    }   // ← new outer closing brace; falls after the existing if($dup_id) closing brace at line 1164
```

- [ ] **Step 5: Add new fields to `$meta_map` in step 10**

The `$meta_map` array is at line 1194. After the existing `if ( $assigned_pro )` block (lines 1212–1214), add new field writes before the `foreach` loop:
```php
    if ( $assigned_pro ) {
        $meta_map['lead_assigned_pros'] = wp_json_encode( [ $assigned_pro ] );
    }
    // New fields — written unconditionally.
    // property_address: empty string for submissions that don't include it (acceptable).
    // lead_contact_email: for profile_form $email was already overwritten above with the
    //   homeowner email; for other sources $email holds whatever the 'email' param was
    //   (may be empty string) — storing it in lead_contact_email is harmless per spec.
    $meta_map['property_address']    = $property_address;
    $meta_map['lead_contact_email']  = $email;
    if ( $preferred_pro_id ) {
        $meta_map['preferred_pro_id'] = $preferred_pro_id;
    }
    foreach ( $meta_map as $key => $value ) {
        update_post_meta( $post_id, $key, $value );
    }
```

- [ ] **Step 6: Update step-11 dispatch block**

Find step 11 (around line 1219):
```php
    // 11. Cascading dispatch (skip for followup_modal — pro already known)
    $dispatch_result = [ 'pros' => [], 'tier' => 'no_coverage', 'has_coverage' => false ];
    if ( $zip && $source !== 'followup_modal' ) {
        $dispatch_result = frp_find_dispatch_pros( $zip, $service );
    }
```
Replace with:
```php
    // 11. Cascading dispatch (skip for followup_modal and profile_form — pro already known)
    $dispatch_result = [ 'pros' => [], 'tier' => 'no_coverage', 'has_coverage' => false ];
    if ( $zip && $source !== 'followup_modal' && $source !== 'profile_form' ) {
        $dispatch_result = frp_find_dispatch_pros( $zip, $service );
    }
    // For profile_form: build dispatch result from the preferred pro's meta
    // so frp_fire_lead_webhook() can populate dispatched_pros with real contact info.
    // The unconditional update_post_meta( $post_id, 'lead_assigned_pros', ... ) at line 1225
    // runs AFTER this block. With $dispatch_result['pros'] set to the preferred pro,
    // line 1225 correctly writes wp_json_encode([$preferred_pro_id]). No change to line 1225.
    if ( $source === 'profile_form' && $preferred_pro_id ) {
        $dispatch_result = [
            'has_coverage' => true,
            'tier'         => 'direct',
            'pros'         => [ [
                'post_id'        => $preferred_pro_id,
                'name'           => get_post_meta( $preferred_pro_id, 'business_name', true ) ?: get_the_title( $preferred_pro_id ),
                'dispatch_phone' => get_post_meta( $preferred_pro_id, 'phone', true ) ?: '',
                'dispatch_email' => get_post_meta( $preferred_pro_id, 'contact_email', true ) ?: '',
            ] ],
        ];
    }
```

- [ ] **Step 7: Extend the `frp_fire_lead_webhook` call site**

Find the `frp_fire_lead_webhook` call (around line 1231). Replace the `$lead_data` array argument to add two new keys:
```php
    frp_fire_lead_webhook( $post_id, $score, $dispatch_result, [
        'phone'            => $phone,
        'email'            => $email,
        'zip'              => $zip,
        'service'          => $service,
        'urgency'          => $urgency,
        'property'         => $property,
        'insurance'        => $insurance,
        'source'           => $source,
        'date'             => $now,
        'preferred_pro_id' => $preferred_pro_id,  // new
        'property_address' => $property_address,  // new
    ] );
```

- [ ] **Step 8: Extend the `frp_fire_lead_webhook` payload body**

Inside `frp_fire_lead_webhook()` (around line 1309), find the `$payload = [` array and add two keys after `'site_url'`:
```php
        'site_url'         => get_site_url(),
        'preferred_pro_id' => $lead_data['preferred_pro_id'] ?? 0,
        'property_address' => $lead_data['property_address'] ?? '',
    ];
```

- [ ] **Step 9: Commit all handler changes**

```bash
git add wordpress-plugins/frp-directory.php
git commit -m "feat(leads): profile_form handler — rate bucket, email/pro validation, dup bypass, direct dispatch, webhook payload"
```

- [ ] **Step 10: Deploy to staging and run tests**

Upload `wordpress-plugins/frp-directory.php` to `wp-content/mu-plugins/frp-directory.php` on staging via SiteGround File Manager.

Then run the full test suite:
```bash
NODE_ENV=test node --test tests/profile-form-lead.test.mjs
```

Expected: All tests PASS. If any fail, check the error response body — common issues are incorrect Edit placements (double-check the line numbers match after each prior edit).

---

## Chunk 2: Frontend — frp-pro-template.php

### Task 4: Create `frp-pro-template.php` — plugin shell + tier gate + sidebar

**Files:**
- Create: `wordpress-plugins/frp-pro-template.php`

- [ ] **Step 1: Create the file with the plugin header, template hook, and tier-gate rendering**

Create `wordpress-plugins/frp-pro-template.php` with this complete content:

```php
<?php
/**
 * Plugin Name: FRP Pro Template
 * Description: Profile page template for restoration_pro single posts. Implements tier-gated CTA hierarchy for lead capture.
 * Version: 1.0.0
 */
if ( ! defined( 'ABSPATH' ) ) exit;

// Register this file as the template for restoration_pro single posts.
// The file acts as both plugin (registers the filter) and template (renders the page).
// __FILE__ (not a separate view file) keeps deployment to a single file upload via SiteGround
// File Manager; the did_action('wp') guard below prevents the rendering code from running
// during mu-plugin boot — it only executes when WordPress loads the file as a template.
add_filter( 'template_include', function( $template ) {
    if ( is_singular( 'restoration_pro' ) ) {
        return __FILE__;
    }
    return $template;
} );

// Guard: only run template rendering when WordPress loads this file as a template.
// When loaded as a mu-plugin (step 1 of WP boot), did_action('wp') returns 0.
// When loaded as a template (after wp() runs), it returns 1.
if ( ! did_action( 'wp' ) ) {
    return;
}

// ── Data ─────────────────────────────────────────────────────────────────────

$pro_id   = get_queried_object_id();
$pro_name = get_the_title( $pro_id );

// Tier gate — whitelist approach; anything not on the whitelist is gated
$tier          = get_post_meta( $pro_id, 'listing_tier', true ) ?: 'free';
$is_accessible = in_array( $tier, [ 'paid', 'featured', 'premium' ], true );

// Pro contact & display fields
$phone   = get_post_meta( $pro_id, 'phone', true ) ?: '';
$address = get_post_meta( $pro_id, 'business_address', true ) ?: '';
$website = get_post_meta( $pro_id, 'website', true ) ?: '';
$city    = get_post_meta( $pro_id, 'city', true ) ?: '';
$state   = get_post_meta( $pro_id, 'state', true ) ?: '';
$bio     = get_post_meta( $pro_id, 'bio', true ) ?: '';

// Services — comma-separated string → array of trimmed slugs
$services_raw  = get_post_meta( $pro_id, 'services', true ) ?: '';
$services      = $services_raw
    ? array_filter( array_map( 'trim', explode( ',', $services_raw ) ) )
    : [];

$service_labels = [
    'water-damage'      => 'Water Damage Restoration',
    'fire-damage'       => 'Fire Damage Restoration',
    'mold-remediation'  => 'Mold Remediation',
    'storm-damage'      => 'Storm Damage Repair',
    'sewage-cleanup'    => 'Sewage Cleanup',
    'biohazard-cleanup' => 'Biohazard Cleanup',
    'structural'        => 'Structural Restoration',
];
$all_service_slugs = array_keys( $service_labels );

// Call Now URL — use rest_url() not hardcoded /wp-json/
$call_url = esc_url( rest_url( 'frp/v1/call' ) ) . '?company=' . $pro_id . '&source=profile&path=profile';

// JSON-LD schema — telephone only when accessible (spec: phone never in HTML for gated pros)
$schema = [
    '@context' => 'https://schema.org',
    '@type'    => 'LocalBusiness',
    'name'     => $pro_name,
    'url'      => get_permalink( $pro_id ),
];
if ( $is_accessible && $phone ) {
    $schema['telephone'] = $phone;
}

// ── Page output ───────────────────────────────────────────────────────────────

get_header();
?>

<style>
/* ── Profile layout ── */
.frp-profile-wrap {
    max-width: 1100px;
    margin: 2rem auto;
    padding: 0 1rem;
    display: flex;
    gap: 2rem;
    align-items: flex-start;
}
.frp-profile-main { flex: 1; min-width: 0; }
.frp-profile-sidebar {
    width: 280px;
    flex-shrink: 0;
    border: 1px solid #e2e8f0;
    border-radius: 8px;
    padding: 1.25rem;
    background: #fff;
}
@media (max-width: 767px) {
    .frp-profile-wrap { flex-direction: column; }
    .frp-profile-sidebar { width: 100%; }
}

/* ── Sidebar CTA ── */
.frp-sidebar-title { font-size: 1rem; font-weight: 600; margin: 0 0 .5rem; }
.frp-sidebar-tagline { font-size: .875rem; color: #64748b; margin: 0 0 1rem; }
.frp-btn-primary {
    display: block;
    width: 100%;
    padding: .75rem 1rem;
    background: #2563eb;
    color: #fff;
    border: none;
    border-radius: 6px;
    font-size: 1rem;
    font-weight: 600;
    cursor: pointer;
    text-align: center;
    text-decoration: none;
    margin-bottom: .75rem;
}
.frp-btn-primary:hover { background: #1d4ed8; color: #fff; }
.frp-btn-secondary {
    display: block;
    width: 100%;
    padding: .75rem 1rem;
    background: transparent;
    color: #2563eb;
    border: 1.5px solid #2563eb;
    border-radius: 6px;
    font-size: 1rem;
    font-weight: 600;
    cursor: pointer;
    text-align: center;
    text-decoration: none;
    margin-bottom: .75rem;
}
.frp-upsell-note { font-size: .8rem; color: #94a3b8; margin-top: .5rem; }
.frp-upsell-note a { color: #2563eb; }
.frp-phone-row { display: flex; align-items: center; gap: .5rem; margin-bottom: .75rem; font-size: 1.1rem; font-weight: 600; }
.frp-meta-row { font-size: .875rem; color: #475569; margin-bottom: .4rem; }
.frp-confirm-msg { font-size: .9rem; color: #16a34a; font-weight: 500; padding: .5rem 0; }

/* ── Sticky bar (mobile only) ── */
#frp-profile-sticky-bar {
    display: none;
    position: fixed;
    bottom: 0; left: 0; right: 0;
    z-index: 999;
    background: #fff;
    padding: 12px 16px;
    box-shadow: 0 -2px 8px rgba(0,0,0,.12);
}
@media (max-width: 767px) {
    #frp-profile-sticky-bar { display: block; }
    .frp-profile-wrap { padding-bottom: 80px; } /* clear sticky bar */
}
#frp-profile-sticky-bar .frp-btn-primary { margin-bottom: 0; }
#frp-profile-sticky-bar a.frp-btn-primary { display: block; }

/* ── Modal ── */
#frp-profile-modal {
    display: none;
    position: fixed;
    inset: 0;
    z-index: 1000;
    background: rgba(0,0,0,.5);
    align-items: center;
    justify-content: center;
    padding: 1rem;
}
#frp-profile-modal.is-open { display: flex; }
.frp-modal-box {
    background: #fff;
    border-radius: 8px;
    width: 100%;
    max-width: 520px;
    max-height: 90vh;
    overflow-y: auto;
    padding: 1.5rem;
    position: relative;
}
.frp-modal-close {
    position: absolute;
    top: 1rem; right: 1rem;
    background: none; border: none;
    font-size: 1.5rem; cursor: pointer; color: #94a3b8;
}
.frp-modal-title { font-size: 1.2rem; font-weight: 700; margin: 0 0 1.25rem; }
.frp-form-group { margin-bottom: 1rem; }
.frp-form-group label { display: block; font-size: .875rem; font-weight: 500; margin-bottom: .25rem; }
.frp-form-group input,
.frp-form-group select { width: 100%; padding: .5rem .75rem; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 1rem; }
.frp-form-group input[type=text][readonly] { background: #f1f5f9; color: #475569; cursor: default; }
.frp-form-row { display: flex; gap: 1rem; }
.frp-form-row .frp-form-group { flex: 1; }
.frp-form-error { color: #dc2626; font-size: .875rem; margin-top: .5rem; display: none; }
.frp-form-error.is-visible { display: block; }
</style>

<div class="frp-profile-wrap">

    <!-- ── Main content ── -->
    <div class="frp-profile-main">
        <h1><?php echo esc_html( $pro_name ); ?></h1>
        <?php if ( $bio ) : ?>
            <p><?php echo esc_html( $bio ); ?></p>
        <?php endif; ?>
    </div>

    <!-- ── Sidebar ── -->
    <aside class="frp-profile-sidebar">
        <div id="frp-profile-cta-sidebar">
        <?php if ( $is_accessible ) : ?>

            <p class="frp-sidebar-title">Contact &amp; Coverage</p>
            <?php if ( $phone ) : ?>
                <div class="frp-phone-row">
                    📞 <?php echo esc_html( $phone ); ?>
                </div>
                <a href="<?php echo esc_url( $call_url ); ?>" class="frp-btn-primary">Call Now</a>
            <?php endif; ?>
            <button type="button" class="frp-btn-secondary frp-open-modal">Request Quote</button>
            <?php if ( $address ) : ?>
                <p class="frp-meta-row">📍 <?php echo esc_html( $address ); ?></p>
            <?php endif; ?>
            <?php if ( $website ) : ?>
                <p class="frp-meta-row">🌐 <a href="<?php echo esc_url( $website ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( $website ); ?></a></p>
            <?php endif; ?>

        <?php else : ?>

            <p class="frp-sidebar-title">Request Service</p>
            <p class="frp-sidebar-tagline"><?php echo esc_html( $pro_name ); ?> is ready to help</p>
            <button type="button" class="frp-btn-primary frp-open-modal">Request Service →</button>
            <p class="frp-upsell-note">
                <a href="<?php echo esc_url( home_url( '/pricing/' ) ); ?>">Upgrade your listing</a>
                so customers can reach you directly
            </p>

        <?php endif; ?>
        </div><!-- #frp-profile-cta-sidebar -->
    </aside>

</div><!-- .frp-profile-wrap -->
```

- [ ] **Step 2: Verify the file renders without PHP errors on staging (before modal/JS)**

Upload `wordpress-plugins/frp-pro-template.php` to `wp-content/mu-plugins/frp-pro-template.php` on staging.

Navigate to any `restoration_pro` profile page on staging. Expected:
- The page loads without blank screen or PHP warnings
- Pro name appears as `<h1>`
- Sidebar renders: gated pros see "Request Service" button + upsell note; accessible pros see phone + "Call Now" + "Request Quote"
- The modal buttons exist but clicking them does nothing yet (JS not added)

---

### Task 5: frp-pro-template.php — modal HTML + JS + sticky bar

**Files:**
- Modify: `wordpress-plugins/frp-pro-template.php`

- [ ] **Step 1: Append modal HTML, JavaScript, sticky bar, JSON-LD, and footer to the file**

After the closing `</div><!-- .frp-profile-wrap -->` line, append:

```php
<!-- ── JSON-LD schema (telephone only for accessible tier — never leak phone for gated pros) ── -->
<script type="application/ld+json"><?php echo wp_json_encode( $schema ); ?></script>

<!-- ── Mobile sticky bar ── -->
<div id="frp-profile-sticky-bar">
<?php if ( $is_accessible ) : ?>
    <a href="<?php echo esc_url( $call_url ); ?>" class="frp-btn-primary">Call Now</a>
<?php else : ?>
    <button type="button" class="frp-btn-primary frp-open-modal">Request Service</button>
<?php endif; ?>
</div>

<!-- ── Modal (shared by both "Request Service" and "Request Quote" triggers) ── -->
<div id="frp-profile-modal" role="dialog" aria-modal="true" aria-labelledby="frp-modal-heading">
    <div class="frp-modal-box">
        <button type="button" class="frp-modal-close frp-close-modal" aria-label="Close">&times;</button>
        <h2 class="frp-modal-title" id="frp-modal-heading">
            <?php echo $is_accessible ? 'Request a Quote' : 'Request Service'; ?>
        </h2>

        <form id="frp-profile-form" novalidate>

            <div class="frp-form-row">
                <div class="frp-form-group">
                    <label for="frp-phone">Phone *</label>
                    <input type="tel" id="frp-phone" name="phone" required placeholder="(555) 555-5555">
                </div>
                <div class="frp-form-group">
                    <label for="frp-email">Email *</label>
                    <input type="email" id="frp-email" name="lead_contact_email" required placeholder="you@example.com">
                </div>
            </div>

            <div class="frp-form-row">
                <div class="frp-form-group">
                    <label for="frp-zip">ZIP Code *</label>
                    <input type="text" id="frp-zip" name="zip" required maxlength="5" placeholder="90210">
                </div>
                <div class="frp-form-group">
                    <label for="frp-address">Street Address</label>
                    <input type="text" id="frp-address" name="property_address" placeholder="123 Main St">
                </div>
            </div>

            <div class="frp-form-group">
                <label for="frp-service">Service Type *</label>
                <?php
                $svc_count = count( $services );
                if ( $svc_count === 1 ) :
                    $single_slug  = reset( $services );
                    $single_label = $service_labels[ $single_slug ] ?? $single_slug;
                ?>
                    <!-- Single service: read-only display + hidden input -->
                    <input type="text" value="<?php echo esc_attr( $single_label ); ?>" readonly>
                    <input type="hidden" name="service" value="<?php echo esc_attr( $single_slug ); ?>">
                <?php else : ?>
                    <!-- Multiple services or empty: dropdown -->
                    <select id="frp-service" name="service" required>
                        <option value="">Select a service…</option>
                        <?php
                        $dropdown_slugs = $svc_count > 1 ? $services : $all_service_slugs;
                        foreach ( $dropdown_slugs as $slug ) :
                            $label = $service_labels[ $slug ] ?? $slug;
                        ?>
                            <option value="<?php echo esc_attr( $slug ); ?>"><?php echo esc_html( $label ); ?></option>
                        <?php endforeach; ?>
                    </select>
                <?php endif; ?>
            </div>

            <div class="frp-form-group">
                <label for="frp-urgency">Urgency *</label>
                <select id="frp-urgency" name="urgency" required>
                    <option value="">Select…</option>
                    <option value="now">Right now</option>
                    <option value="24hrs">Within 24 hours</option>
                    <option value="older">Within a week</option>
                </select>
            </div>

            <div class="frp-form-row">
                <div class="frp-form-group">
                    <label for="frp-property-type">Property Type *</label>
                    <select id="frp-property-type" name="property_type" required>
                        <option value="">Select…</option>
                        <option value="residential">Residential</option>
                        <option value="commercial">Commercial</option>
                    </select>
                </div>
                <div class="frp-form-group">
                    <label for="frp-insurance">Has Insurance? *</label>
                    <select id="frp-insurance" name="has_insurance" required>
                        <option value="">Select…</option>
                        <option value="yes">Yes</option>
                        <option value="no">No</option>
                        <option value="not-sure">Not sure</option>
                    </select>
                </div>
            </div>

            <!-- Hidden fields -->
            <input type="hidden" name="source" value="profile_form">
            <input type="hidden" name="preferred_pro_id" value="<?php echo esc_attr( $pro_id ); ?>">

            <div class="frp-form-error" id="frp-form-error"></div>

            <button type="submit" class="frp-btn-primary" id="frp-submit-btn" style="margin-top:.5rem;">
                Send Request
            </button>

        </form>
    </div>
</div><!-- #frp-profile-modal -->

<script>
(function () {
    'use strict';

    // ── Sidebar confirmation message (injected on success) ──
    var PRO_NAME = <?php echo wp_json_encode( $pro_name ); ?>;

    // ── Modal open/close ──
    var modal    = document.getElementById('frp-profile-modal');
    var errorEl  = document.getElementById('frp-form-error');
    var submitBtn = document.getElementById('frp-submit-btn');

    function openModal() { modal.classList.add('is-open'); }
    function closeModal() { modal.classList.remove('is-open'); }

    // All elements that open the modal
    document.querySelectorAll('.frp-open-modal').forEach(function (btn) {
        btn.addEventListener('click', openModal);
    });
    // Close button and backdrop click
    document.querySelectorAll('.frp-close-modal').forEach(function (btn) {
        btn.addEventListener('click', closeModal);
    });
    modal.addEventListener('click', function (e) {
        if (e.target === modal) closeModal();
    });

    // ── Form submission ──
    document.getElementById('frp-profile-form').addEventListener('submit', function (e) {
        e.preventDefault();

        // Disable button immediately (prevents double-submit)
        submitBtn.disabled = true;
        errorEl.classList.remove('is-visible');
        errorEl.textContent = '';

        // Collect form data as plain object
        var fd = new FormData(e.target);
        var body = {};
        fd.forEach(function (val, key) { body[key] = val; });

        fetch('/wp-json/frp/v1/leads', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-FRP-Lead-Token': window.FRP_LEAD_TOKEN || '',
            },
            body: JSON.stringify(body),
        })
        .then(function (res) {
            return res.json().then(function (data) {
                return { ok: res.ok, data: data };
            });
        })
        .then(function (result) {
            if (result.ok) {
                // Success: close modal, replace sidebar CTA
                closeModal();
                var sidebar = document.getElementById('frp-profile-cta-sidebar');
                if (sidebar) {
                    sidebar.innerHTML =
                        '<p class="frp-confirm-msg">Request sent — ' +
                        PRO_NAME + ' will be in touch soon.</p>';
                }
            } else {
                // Error: show server message or fallback
                var msg = (result.data && result.data.message)
                    ? result.data.message
                    : 'Something went wrong. Please try again.';
                errorEl.textContent = msg;
                errorEl.classList.add('is-visible');
                submitBtn.disabled = false;
            }
        })
        .catch(function () {
            errorEl.textContent = 'Something went wrong. Please try again.';
            errorEl.classList.add('is-visible');
            submitBtn.disabled = false;
        });
    });
})();
</script>

<?php
wp_footer();
?>
</body>
</html>
```

> **Note:** The `get_header()` call outputs `<!DOCTYPE html>` through the opening `<body>` tag. The template must call `wp_footer()` and close `</body></html>` at the end. WordPress's `get_header()` does NOT output a closing `</body>` or `</html>`.

- [ ] **Step 2: Upload and do a full manual walkthrough on staging**

Upload the updated `frp-pro-template.php` to staging (`wp-content/mu-plugins/frp-pro-template.php`).

**Gated tier walkthrough (pro with `listing_tier = 'free'` or empty):**

1. Navigate to a gated pro's profile URL on staging
2. View page source — confirm phone number does NOT appear anywhere in the HTML, including the `<script type="application/ld+json">` block (no `telephone` key). Note: the JSON-LD block is in the `<body>`, not `<head>` — search the full page source for `telephone`.
3. "Request Service" button is visible in sidebar
4. Click → modal opens with 8 fields visible
5. Submit with valid data → lead created; WP Admin → frp_lead post → Custom Fields panel (enable via Screen Options) → confirm `lead_contact_email`, `property_address`, `preferred_pro_id`, `lead_source = profile_form` are stored
6. Submit with missing email → `400` response, inline error shows, button re-enables
7. Submit with invalid email → `400` response
8. On mobile (Chrome DevTools device mode) → sticky "Request Service" bar visible at bottom, opens modal on tap
9. Upsell link in sidebar → resolves to `/pricing/` URL

**Accessible tier walkthrough (pro with `listing_tier = 'paid'`):**

1. Temporarily set a test pro's `listing_tier` to `paid` via WP Admin custom fields
2. Navigate to their profile page
3. Phone number visible in sidebar
4. "Call Now" button is an `<a>` anchor → clicking opens phone dialer (or follow the URL to confirm `/wp-json/frp/v1/call?...` is a valid endpoint)
5. "Request Quote" button opens the modal
6. On mobile → sticky "Call Now" bar visible at bottom
7. View page source — confirm the `<script type="application/ld+json">` block **does** include `"telephone"` with the pro's phone number (the gated check should be false for a paid tier pro)

- [ ] **Step 3: Commit**

```bash
git add wordpress-plugins/frp-pro-template.php
git commit -m "feat(template): frp-pro-template.php — tier-gated profile page with modal lead form, sticky bar, JSON-LD"
```

---

### Task 6: Run full test suite + deploy to live

**Files:** None — testing and deployment only.

- [ ] **Step 1: Run the full integration test suite on staging**

```bash
NODE_ENV=test node --test --test-concurrency=1 tests/*.mjs
```

Expected: All tests pass, including `profile-form-lead.test.mjs`. Watch for any regressions in other test files (guided_flow, follow-up modal, billing, etc.).

- [ ] **Step 2: Deploy `frp-directory.php` to live**

Upload `wordpress-plugins/frp-directory.php` to `wp-content/mu-plugins/frp-directory.php` on the **live** site via SiteGround File Manager.

- [ ] **Step 3: Deploy `frp-pro-template.php` to live**

Upload `wordpress-plugins/frp-pro-template.php` to `wp-content/mu-plugins/frp-pro-template.php` on the **live** site.

- [ ] **Step 4: Spot-check on live**

1. Navigate to a live `restoration_pro` profile page — confirm it renders (no blank screen)
2. View source on a free/basic pro — confirm no phone in HTML or JSON-LD
3. Submit the modal form with valid data — confirm lead appears in WP Admin → Leads
4. Check Make.com webhook history — confirm `preferred_pro_id`, `property_address`, `dispatched_pros` are present in the payload

- [ ] **Step 5: Final commit (mark implementation complete)**

```bash
git add wordpress-plugins/frp-directory.php wordpress-plugins/frp-pro-template.php tests/profile-form-lead.test.mjs
git commit -m "chore: pro profile CTA implementation complete — backend + template deployed to live"
```
