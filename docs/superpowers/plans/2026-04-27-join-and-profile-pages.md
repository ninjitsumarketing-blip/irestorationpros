# Join Page & Pro Profile Page Implementation Plan

> **For agentic workers:** REQUIRED: Use superpowers:subagent-driven-development (if subagents available) or superpowers:executing-plans to implement this plan. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build the redesigned Join as a Pro page, credential badge display on pro profiles, and fix the phone meta key gap in the /apply endpoint.

**Architecture:** Three independent work areas sharing one backend fix: (1) `frp-directory.php` gets `iicrc_certified` added to the validate/insert/claim chain plus a `phone` meta write; (2) `frp-pro-template.php` gets new credential badge rendering gated on `claim_status=claimed` plus updated upsell copy; (3) a new `frp-join.php` mu-plugin exposes `[frp_join_form]` shortcode rendering the hero form and benefit cards, submitting JSON to the existing `/apply` endpoint. The HTML stitch mockup (`stitch-html/frp-join.html`) is a visual reference design, not a deployed artifact.

**Tech Stack:** PHP 8.1 (WordPress mu-plugin pattern matching existing files), Vanilla JS (fetch API, same pattern as `frp-pro-template.php`), Node.js test runner (`node:test`, `node:assert/strict`), staging REST API via `tests/helpers/wp-client.mjs`.

---

## File Map

| File | Status | Responsibility |
|---|---|---|
| `wordpress-plugins/frp-directory.php` | Modify | Add `iicrc_certified` param + `phone` meta write to validate/insert/claim chain |
| `wordpress-plugins/frp-pro-template.php` | Modify | Add credential badge rendering block + updated upsell note copy |
| `wordpress-plugins/frp-billing.php` | Modify | Update `price_usd` for `paid` tier from `19900` → `24900` |
| `wordpress-plugins/frp-join.php` | Create | `[frp_join_form]` shortcode — form HTML + JS submission |
| `stitch-html/frp-join.html` | Modify | Visual HTML mockup of join page (design reference only, not deployed) |
| `tests/apply.test.mjs` | Modify | Add tests for `iicrc_certified` storage and `phone` meta write |
| `tests/join.test.mjs` | Create | Smoke test: join shortcode page loads, /apply endpoint accepts new field |

---

## Chunk 1: Backend Changes

### Task 1: Add `iicrc_certified` + `phone` meta fix to `/apply` endpoint

**Spec reference:** `docs/superpowers/specs/2026-04-26-join-and-profile-pages-design.md` — Part 1, Backend Change section.

**Files:**
- Modify: `wordpress-plugins/frp-directory.php` (lines 1576–1647 and 1690–1715)
- Modify: `tests/apply.test.mjs`

**Context:**
- `frp_apply_validate()` reads params and returns `compact(...)`. Add `$iicrc` to this chain.
- `frp_apply_insert_new($a)` writes meta from `$a`. Add two new `update_post_meta` calls.
- `frp_apply_initiate_claim($applicant, $match, $need_admin)`: `$pro_id = $match['pro_id']` at line 1691; early-return guard at lines 1694–1702; existing `update_post_meta` cluster starts at ~line 1709. Add two guarded writes AFTER line 1702.
- Profile template reads `phone` meta (not `dispatch_phone`). Without the fix, join-form applicants never get phone on profile.

- [ ] **Step 1: Write failing tests for iicrc_certified storage and phone meta write**

`tests/apply.test.mjs` already has `import test`, `import assert`, and `import { frpPost }`. Add only the new import and new test blocks — do NOT re-declare the existing imports.

Add to `tests/apply.test.mjs` (new import at top, new tests at bottom):

```javascript
// NEW import — add after the existing imports at the top of the file:
import { readMeta, resetTestPros } from './helpers/staging.mjs';

// NEW hooks — add near the top of the file, after existing imports:
test.before(async () => { await resetTestPros(); });
test.after(async () => { await resetTestPros(); });

test('apply stores iicrc_certified meta when value is yes', async () => {
  const uid = Date.now();
  const { status, body } = await frpPost('/wp-json/frp/v1/apply', {
    business_name: `IICRC Test Pro ${uid}`,
    contact_email: `iicrc${uid}@test.invalid`,
    dispatch_phone: '5550001111',
    state: 'CA',
    iicrc_certified: 'yes',
  });
  assert.equal(status, 200, `apply failed: ${JSON.stringify(body)}`);
  const proId = body.application_id;
  assert.ok(proId > 0, 'expected application_id');
  const stored = await readMeta(proId, 'iicrc_certified');
  assert.equal(stored, 'yes', `expected iicrc_certified='yes', got '${stored}'`);
});

test('apply stores iicrc_certified=in_progress', async () => {
  const uid = Date.now() + 1;
  const { status, body } = await frpPost('/wp-json/frp/v1/apply', {
    business_name: `IICRC InProgress ${uid}`,
    contact_email: `inprog${uid}@test.invalid`,
    dispatch_phone: '5550002222',
    state: 'TX',
    iicrc_certified: 'in_progress',
  });
  assert.equal(status, 200);
  const stored = await readMeta(body.application_id, 'iicrc_certified');
  assert.equal(stored, 'in_progress');
});

test('apply ignores invalid iicrc_certified values', async () => {
  const uid = Date.now() + 2;
  const { status, body } = await frpPost('/wp-json/frp/v1/apply', {
    business_name: `IICRC Invalid ${uid}`,
    contact_email: `invalid${uid}@test.invalid`,
    dispatch_phone: '5550003333',
    state: 'NY',
    iicrc_certified: 'definitely-certified',
  });
  assert.equal(status, 200);
  const stored = await readMeta(body.application_id, 'iicrc_certified');
  assert.equal(stored, '', `expected empty string for invalid value, got '${stored}'`);
});

test('apply writes phone meta key (not only dispatch_phone)', async () => {
  const uid = Date.now() + 3;
  const { status, body } = await frpPost('/wp-json/frp/v1/apply', {
    business_name: `Phone Meta Test ${uid}`,
    contact_email: `phonemeta${uid}@test.invalid`,
    dispatch_phone: '5559876543',
    state: 'FL',
  });
  assert.equal(status, 200);
  const proId = body.application_id;
  const phoneMeta = await readMeta(proId, 'phone');
  assert.equal(phoneMeta, '5559876543', `expected phone meta='5559876543', got '${phoneMeta}'`);
});
```

- [ ] **Step 2: Run tests to verify they fail**

```bash
cd /Users/ninjitsumarketing/projects/irestorationpros/.claude/worktrees/stoic-jennings
node --test tests/apply.test.mjs 2>&1 | tail -30
```

Expected: The 4 new tests fail (iicrc_certified not stored, phone meta empty).

- [ ] **Step 3: Implement — modify `frp_apply_validate()` (lines 1582–1607)**

In `wordpress-plugins/frp-directory.php`, inside `frp_apply_validate()`, add `$iicrc` assignment among the existing variable assignments (after `$services_raw` on line 1591, before the validation checks on line 1593):

```php
    $iicrc = sanitize_text_field( (string) ( $r->get_param( 'iicrc_certified' ) ?? '' ) );
    if ( ! in_array( $iicrc, [ 'yes', 'no', 'in_progress' ], true ) ) {
        $iicrc = '';
    }
```

Then update the `compact()` return on line 1607:

```php
    return compact( 'business', 'contact', 'email', 'phone', 'license', 'years', 'zips', 'city', 'state', 'services', 'iicrc' );
```

- [ ] **Step 4: Implement — add meta writes in `frp_apply_insert_new()` (after line 1641)**

In `frp_apply_insert_new()`, add two lines after the existing `update_post_meta` cluster (after `'date_seeded'` line ~1641):

```php
    update_post_meta( $pro_id, 'iicrc_certified', $a['iicrc'] );
    update_post_meta( $pro_id, 'phone',           $a['phone'] );
```

The full block will now look like:

```php
    update_post_meta( $pro_id, 'joined_source',      'apply_new' );
    update_post_meta( $pro_id, 'date_seeded',        gmdate( 'c' ) );
    update_post_meta( $pro_id, 'iicrc_certified',    $a['iicrc'] );
    update_post_meta( $pro_id, 'phone',              $a['phone'] );
```

- [ ] **Step 5: Implement — add guarded writes in `frp_apply_initiate_claim()` (after line 1713)**

In `frp_apply_initiate_claim()`, after the no-email early-return guard (line 1702), there is a cluster of `update_post_meta` calls starting at line 1709. The cluster currently ends at line 1713 (`claim_applicant_phone`). Add the two new writes AFTER line 1713, remaining inside the existing meta-write cluster:

```php
    update_post_meta( $pro_id, 'claim_applicant_phone', $applicant['phone'] );  // existing line 1713
    // ── new lines below ──
    if ( $applicant['iicrc'] !== '' ) {
        update_post_meta( $pro_id, 'iicrc_certified', $applicant['iicrc'] );
    }
    if ( $applicant['phone'] !== '' ) {
        update_post_meta( $pro_id, 'phone', $applicant['phone'] );
    }
```

Do NOT place these before `$tok = frp_generate_lead_token()` (line 1706) — they must go after the existing `update_post_meta` cluster, not before it.

- [ ] **Step 6: Run tests to verify they pass**

```bash
node --test tests/apply.test.mjs 2>&1 | tail -30
```

Expected: All tests pass including the 4 new ones.

- [ ] **Step 7: Commit**

```bash
git add wordpress-plugins/frp-directory.php tests/apply.test.mjs
git commit -m "feat: add iicrc_certified field and phone meta fix to /apply endpoint

- frp_apply_validate: read and sanitize iicrc_certified param (yes/no/in_progress)
- frp_apply_insert_new: write iicrc_certified and phone meta keys
- frp_apply_initiate_claim: write iicrc_certified and phone on successful claim
  (after no-email early-return guard; guarded by non-empty check)
- phone meta fix: profile template reads 'phone' not 'dispatch_phone'; both
  now written on new application and claim paths

Co-Authored-By: Claude Sonnet 4.6 <noreply@anthropic.com>"
```

---

### Task 2: Pro profile page — credential badges + upsell copy

**Spec reference:** Part 2, Changes 1–3.

**Files:**
- Modify: `wordpress-plugins/frp-pro-template.php`

**Context:**
- Data section starts at line 32. `$bio` is on line 47. Add new variables after `$bio`.
- Main content `<div class="frp-profile-main">` is at line 209. `$bio` paragraph at lines 211–213. Badge block goes after it, before `</div>`.
- Upsell note at lines 241–244 inside the `else` branch of `#frp-profile-cta-sidebar`.
- No test exists for profile page HTML output — manual verification via staging.

- [ ] **Step 1: Add credential variables to data section**

In `frp-pro-template.php`, after `$bio` (line 47), add:

```php
$claim_status   = (string) get_post_meta( $pro_id, 'claim_status', true );
$is_claimed     = ( $claim_status === 'claimed' );
$iicrc_status   = (string) get_post_meta( $pro_id, 'iicrc_certified', true );
$certifications = (string) get_post_meta( $pro_id, 'certifications', true );
$years_in_biz   = (int)    get_post_meta( $pro_id, 'years_in_business', true );
```

- [ ] **Step 2: Add badge CSS to the `<style>` block**

In `frp-pro-template.php`, add after the `.frp-confirm-msg` rule in the `<style>` block (around line 147):

```css
/* ── Credential badges ── */
.frp-credential-badges { display: flex; flex-wrap: wrap; gap: .4rem; margin: .75rem 0; }
.frp-badge { display: inline-block; padding: .2rem .6rem; background: #f1f5f9; border-radius: 999px; font-size: .8rem; color: #475569; font-weight: 500; }
.frp-badge--iicrc { background: #dbeafe; color: #1d4ed8; }
.frp-badge--featured { background: #fef9c3; color: #a16207; font-weight: 600; }
```

- [ ] **Step 3: Add badge rendering block in main content column**

In `frp-pro-template.php`, after the `$bio` paragraph's closing `<?php endif; ?>` (line 213) and BEFORE the `</div>` on line 214 that closes `.frp-profile-main`, add:

```php
        <?php if ( $is_claimed ) : ?>
        <div class="frp-credential-badges">
            <?php if ( $is_accessible ) : ?>
                <span class="frp-badge frp-badge--featured">⭐ Featured</span>
            <?php endif; ?>
            <?php if ( $iicrc_status === 'yes' ) : ?>
                <span class="frp-badge frp-badge--iicrc">✓ IICRC Certified</span>
            <?php endif; ?>
            <?php if ( $certifications ) : ?>
                <?php foreach ( array_filter( array_map( 'trim', explode( ',', $certifications ) ) ) as $cert ) : ?>
                    <span class="frp-badge"><?php echo esc_html( $cert ); ?></span>
                <?php endforeach; ?>
            <?php endif; ?>
            <?php if ( $years_in_biz > 0 ) : ?>
                <span class="frp-badge"><?php echo esc_html( $years_in_biz ); ?> yrs in business</span>
            <?php endif; ?>
        </div>
        <?php endif; ?>
```

- [ ] **Step 4: Replace upsell note copy (lines 241–244)**

Replace the current upsell note:
```php
            <p class="frp-upsell-note">
                <a href="<?php echo esc_url( home_url( '/pricing/' ) ); ?>">Upgrade your listing</a>
                so customers can reach you directly
            </p>
```

With:
```php
            <p class="frp-upsell-note">
                Want homeowners to call you directly? Upgrade your listing to show your phone number and receive leads straight to you.
                <a href="<?php echo esc_url( home_url( '/pricing/' ) ); ?>">See what's included →</a>
            </p>
```

- [ ] **Step 5: Verify on staging (manual)**

Upload `frp-pro-template.php` to SiteGround mu-plugins folder via File Manager. Visit a pro profile page on staging.

Check unclaimed pro (claim_status ≠ 'claimed'): no `.frp-credential-badges` div renders.
Check claimed free pro: badges render (IICRC if set, certifications, years). No "Featured" badge.
Check claimed paid pro: "Featured" badge appears first, then credentials.
Check upsell note text reads: "Want homeowners to call you directly? Upgrade your listing to show your phone number and receive leads straight to you. See what's included →"

- [ ] **Step 6: Commit**

```bash
git add wordpress-plugins/frp-pro-template.php
git commit -m "feat: add credential badges to pro profile, update upsell copy

- Add claim_status gate: badges only render when claim_status='claimed'
- New .frp-credential-badges block: Featured (paid tiers), IICRC Certified,
  certifications (comma-sep), years in business
- Featured badge uses existing \$is_accessible gate (paid/featured/premium)
- Replace upsell note: honest copy directing to phone visibility + pricing page
- Change 2 (bio copy polish): template already correct, no change needed

Co-Authored-By: Claude Sonnet 4.6 <noreply@anthropic.com>"
```

---

### Task 3: Billing price update

**Spec reference:** Additional Backend Change: Paid Listing Price.

**Files:**
- Modify: `wordpress-plugins/frp-billing.php` (line 19)

**Context:**
- `FRP_TIERS['paid']['price_usd']` is display-only (feeds `/billing/catalog` endpoint).
- Actual Stripe charge is controlled by `frp_stripe_price_paid` WP option — ops task, not in scope here.
- Test: `GET /wp-json/frp/v1/billing/catalog` → check `price_usd_cents` for `paid` tier.

- [ ] **Step 1: Write failing test**

Create `tests/billing-price.test.mjs`:

```javascript
import test from 'node:test';
import assert from 'node:assert/strict';
import { frpGet } from './helpers/wp-client.mjs';

test('billing catalog shows paid tier at 24900 cents ($249)', async () => {
  const { status, body } = await frpGet('/wp-json/frp/v1/billing/catalog');
  assert.equal(status, 200, `catalog endpoint failed: ${JSON.stringify(body)}`);
  const paid = Array.isArray(body)
    ? body.find(t => t.id === 'paid')
    : null;
  assert.ok(paid, 'paid tier not found in catalog response');
  assert.equal(paid.price_usd_cents, 24900, `expected 24900, got ${paid.price_usd_cents}`);
});
```

- [ ] **Step 2: Run test to verify it fails**

```bash
node --test tests/billing-price.test.mjs 2>&1 | tail -20
```

Expected: FAIL — `expected 24900, got 19900`.

- [ ] **Step 3: Update the constant in `frp-billing.php`**

In `wordpress-plugins/frp-billing.php`, change line 19:

```php
    'paid'     => [ 'label' => 'Paid Listing',     'option' => 'frp_stripe_price_paid',     'price_usd' => 24900 ],
```

(Was `19900`, now `24900`.)

- [ ] **Step 4: Run test to verify it passes**

```bash
node --test tests/billing-price.test.mjs 2>&1 | tail -20
```

Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add wordpress-plugins/frp-billing.php tests/billing-price.test.mjs
git commit -m "feat: update paid listing display price to \$249/month (24900 cents)

Display-only change — price_usd feeds /billing/catalog endpoint UI.
Actual Stripe charge controlled by frp_stripe_price_paid WP option (ops task).

Co-Authored-By: Claude Sonnet 4.6 <noreply@anthropic.com>"
```

---

## Chunk 2: Join Form Shortcode & Visual Mockup

### Task 4: Create `[frp_join_form]` shortcode plugin

**Spec reference:** Part 1, Sections 1 and 2.

**Files:**
- Create: `wordpress-plugins/frp-join.php`
- Create: `tests/join.test.mjs`

**Context:**
- Single-file mu-plugin pattern: `ABSPATH` guard → `add_shortcode` → `ob_start`/`ob_get_clean`. No separate view file — same as `frp-dashboard.php`.
- `rest_url('frp/v1/apply')` injected via `wp_json_encode` with all four XSS-escape flags — same pattern as `frp-pro-template.php` line 372.
- Services checkboxes must be collected as a JSON array in JS. `FormData.forEach` only captures the last checked value for same-name inputs — use `querySelectorAll('input[name="services"]:checked')` instead. When zero checkboxes are checked, omit the `services` key entirely from the body — the `/apply` endpoint treats a missing `services` parameter the same as an empty list (optional field).
- 429 response: disable submit and show 60-second countdown in button text (`'Try again in Ns'`); restore button and text when countdown reaches zero.
- No WP nonce on this form — the `/apply` endpoint is public (rate-limited by IP).
- **Prerequisites:** Tasks 1–3 (Chunk 1 — `frp-directory.php`, `frp-pro-template.php`, `frp-billing.php`) must be deployed to staging before the `iicrc_certified` and `phone` meta assertions in test 2 will pass. Those tasks add `$iicrc` to `frp_apply_validate()`, write both meta keys in `frp_apply_insert_new()`, and write them in `frp_apply_initiate_claim()`. Without Chunk 1 deployed, test 2 will fail on the `readMeta` assertions even though the `/apply` endpoint itself returns 200.
- After creating the file, it must be uploaded to SiteGround File Manager (`/wp-content/mu-plugins/`) and the `[frp_join_form]` shortcode must be added to the `/join/` page body in WP Admin before test 1 will pass.

- [ ] **Step 1: Write failing tests**

Create `tests/join.test.mjs`:

```javascript
import test from 'node:test';
import assert from 'node:assert/strict';
import { frpGet, frpPost } from './helpers/wp-client.mjs';
import { readMeta, resetTestPros } from './helpers/staging.mjs';

test.before(async () => { await resetTestPros(); });
test.after(async () => { await resetTestPros(); });

test('GET /join/ returns 200 and shortcode is rendered', async () => {
  const { status, body } = await frpGet('/join/');
  assert.equal(status, 200, `Expected 200, got ${status}`);
  // frpGet returns raw text when JSON parse fails (which it will for HTML pages)
  const html = typeof body === 'string' ? body : JSON.stringify(body);
  assert.ok(
    html.includes('frp-join-form'),
    'Expected page HTML to contain frp-join-form. Is [frp_join_form] shortcode placed on the /join/ page?'
  );
});

// Requires Chunk 1 (Task 1 — frp-directory.php iicrc + phone meta writes) to be
// deployed to staging before the readMeta assertions below will pass.
test('join form full payload accepted by /apply endpoint', async () => {
  const uid = Date.now();
  const { status, body } = await frpPost('/wp-json/frp/v1/apply', {
    business_name:     `Join Full Payload ${uid}`,
    dispatch_phone:    '5551234567',
    contact_email:     `joinpayload${uid}@test.invalid`,
    state:             'CA',
    services:          ['water-damage', 'fire-damage'],
    iicrc_certified:   'yes',
    license_number:    'CA-TEST-123',
    years_in_business: 7,
    service_area_zips: '90210,90211',
  });
  assert.equal(status, 200, `apply rejected join-form payload: ${JSON.stringify(body)}`);
  assert.ok(body.application_id > 0, 'expected application_id in response');
  const iicrc = await readMeta(body.application_id, 'iicrc_certified');
  assert.equal(iicrc, 'yes', `iicrc_certified not stored: '${iicrc}'`);
  const phone = await readMeta(body.application_id, 'phone');
  assert.equal(phone, '5551234567', `phone meta key not written: '${phone}'`);
});
```

- [ ] **Step 2: Run tests to verify they fail**

```bash
cd /Users/ninjitsumarketing/projects/irestorationpros/.claude/worktrees/stoic-jennings
node --test tests/join.test.mjs 2>&1 | tail -20
```

Expected: Test 1 fails — page doesn't contain `frp-join-form` (shortcode not yet active). Test 2 may already pass after Tasks 1–3 are deployed; that's acceptable — it confirms the full join payload works end-to-end.

- [ ] **Step 3: Create `wordpress-plugins/frp-join.php`**

Create the file with this complete content:

```php
<?php
/**
 * Plugin Name: FRP Join Form
 * Description: [frp_join_form] shortcode — join page application form for restoration contractors.
 * Version: 1.0.0
 */
if ( ! defined( 'ABSPATH' ) ) exit;

add_shortcode( 'frp_join_form', 'frp_join_form_render' );

function frp_join_form_render() : string {
    $apply_url   = wp_json_encode(
        rest_url( 'frp/v1/apply' ),
        JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT
    );
    $pricing_url = esc_url( home_url( '/pricing/' ) );

    $us_states = [
        'AL' => 'Alabama',        'AK' => 'Alaska',         'AZ' => 'Arizona',
        'AR' => 'Arkansas',       'CA' => 'California',     'CO' => 'Colorado',
        'CT' => 'Connecticut',    'DE' => 'Delaware',       'DC' => 'District of Columbia',
        'FL' => 'Florida',        'GA' => 'Georgia',        'HI' => 'Hawaii',
        'ID' => 'Idaho',          'IL' => 'Illinois',       'IN' => 'Indiana',
        'IA' => 'Iowa',           'KS' => 'Kansas',         'KY' => 'Kentucky',
        'LA' => 'Louisiana',      'ME' => 'Maine',          'MD' => 'Maryland',
        'MA' => 'Massachusetts',  'MI' => 'Michigan',       'MN' => 'Minnesota',
        'MS' => 'Mississippi',    'MO' => 'Missouri',       'MT' => 'Montana',
        'NE' => 'Nebraska',       'NV' => 'Nevada',         'NH' => 'New Hampshire',
        'NJ' => 'New Jersey',     'NM' => 'New Mexico',     'NY' => 'New York',
        'NC' => 'North Carolina', 'ND' => 'North Dakota',   'OH' => 'Ohio',
        'OK' => 'Oklahoma',       'OR' => 'Oregon',         'PA' => 'Pennsylvania',
        'RI' => 'Rhode Island',   'SC' => 'South Carolina', 'SD' => 'South Dakota',
        'TN' => 'Tennessee',      'TX' => 'Texas',          'UT' => 'Utah',
        'VT' => 'Vermont',        'VA' => 'Virginia',       'WA' => 'Washington',
        'WV' => 'West Virginia',  'WI' => 'Wisconsin',      'WY' => 'Wyoming',
    ];

    $service_options = [
        'water-damage'      => 'Water Damage',
        'fire-damage'       => 'Fire & Smoke Damage',
        'mold-remediation'  => 'Mold Remediation',
        'storm-damage'      => 'Storm Damage',
        'sewage-cleanup'    => 'Sewage Cleanup',
        'structural'        => 'Structural Repairs',
        'biohazard-cleanup' => 'Biohazard Cleanup',
    ];

    ob_start();
    ?>
<style>
/* ── FRP Join Form ── */
.frp-join-wrap *,
.frp-join-wrap *::before,
.frp-join-wrap *::after { box-sizing: border-box; }
.frp-join-hero { text-align: center; padding: 2.5rem 1rem 2rem; max-width: 680px; margin: 0 auto; }
.frp-join-hero h1 { font-size: clamp(1.6rem, 3.5vw, 2.2rem); font-weight: 700; line-height: 1.25; margin: 0 0 .75rem; color: #0f172a; }
.frp-join-hero p  { font-size: 1.05rem; color: #475569; margin: 0; line-height: 1.6; }
.frp-join-form-section { max-width: 680px; margin: 0 auto 3rem; padding: 0 1rem; }
.frp-join-form .frp-field { margin-bottom: 1.25rem; }
.frp-join-form .frp-label { display: block; font-size: .875rem; font-weight: 600; color: #1e293b; margin-bottom: .35rem; }
.frp-join-form input[type="text"],
.frp-join-form input[type="email"],
.frp-join-form input[type="tel"],
.frp-join-form input[type="number"],
.frp-join-form select { width: 100%; border: 1px solid #cbd5e1; border-radius: .375rem; padding: .6rem .75rem; font-size: 1rem; background: #fff; color: #0f172a; }
.frp-join-form input:focus,
.frp-join-form select:focus { outline: 2px solid #2563eb; outline-offset: 1px; border-color: transparent; }
.frp-services-grid { display: flex; flex-wrap: wrap; gap: .5rem; }
.frp-service-chip { display: flex; align-items: center; gap: .4rem; padding: .35rem .7rem; border: 1px solid #cbd5e1; border-radius: 999px; font-size: .875rem; cursor: pointer; background: #f8fafc; color: #334155; transition: background .15s, border-color .15s; }
.frp-service-chip input { margin: 0; }
.frp-service-chip:has(input:checked) { background: #dbeafe; border-color: #2563eb; color: #1d4ed8; font-weight: 600; }
.frp-iicrc-options { display: flex; gap: 1rem; flex-wrap: wrap; margin-top: .25rem; }
.frp-iicrc-option { display: flex; align-items: center; gap: .4rem; font-size: .9rem; cursor: pointer; }
.frp-iicrc-helper { font-size: .8rem; color: #64748b; margin-top: .4rem; }
.frp-join-btn { display: block; width: 100%; padding: .85rem 1.5rem; background: #1d4ed8; color: #fff; font-size: 1rem; font-weight: 700; border: none; border-radius: .375rem; cursor: pointer; margin-top: 1.75rem; transition: background .15s; }
.frp-join-btn:hover { background: #1e40af; }
.frp-join-btn:disabled { background: #94a3b8; cursor: not-allowed; }
.frp-join-notice { font-size: .85rem; color: #64748b; margin-top: .75rem; line-height: 1.5; }
.frp-join-notice a { color: #2563eb; }
.frp-join-error { background: #fef2f2; border: 1px solid #fca5a5; color: #991b1b; padding: .75rem 1rem; border-radius: .375rem; font-size: .9rem; margin-top: 1rem; display: none; }
.frp-join-success { background: #f0fdf4; border: 1px solid #86efac; color: #166534; padding: 1.5rem; border-radius: .5rem; font-size: 1rem; line-height: 1.6; text-align: center; display: none; }
/* Benefit cards */
.frp-join-benefits { background: #f8fafc; padding: 3rem 1rem; }
.frp-benefits-inner { max-width: 960px; margin: 0 auto; display: grid; grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); gap: 1.5rem; }
.frp-benefit-card { background: #fff; border-radius: .5rem; padding: 1.5rem; border: 1px solid #e2e8f0; }
.frp-benefit-card h3 { font-size: 1rem; font-weight: 700; color: #0f172a; margin: 0 0 .5rem; }
.frp-benefit-card p  { font-size: .875rem; color: #475569; margin: 0; line-height: 1.6; }
</style>

<div class="frp-join-wrap">

<section class="frp-join-hero">
    <h1>Claim your free listing and start receiving leads from homeowners in your area.</h1>
    <p>Get your restoration business in front of people searching for help right now. Free to claim, no commitment required.</p>
</section>

<section class="frp-join-form-section">
    <div id="frp-join-success" class="frp-join-success">
        Application received. Check your email &mdash; we&rsquo;ll be in touch within 1&ndash;2 business days.
    </div>

    <form id="frp-join-form" class="frp-join-form" novalidate>

        <div class="frp-field">
            <label class="frp-label" for="frp-business-name">Business Name <span aria-hidden="true">*</span></label>
            <input type="text" id="frp-business-name" name="business_name" required autocomplete="organization" placeholder="Your business name">
        </div>

        <div class="frp-field">
            <label class="frp-label" for="frp-phone">Phone <span aria-hidden="true">*</span></label>
            <input type="tel" id="frp-phone" name="dispatch_phone" required autocomplete="tel" placeholder="(555) 555-5555">
        </div>

        <div class="frp-field">
            <label class="frp-label" for="frp-email">Email <span aria-hidden="true">*</span></label>
            <input type="email" id="frp-email" name="contact_email" required autocomplete="email" placeholder="you@example.com">
        </div>

        <div class="frp-field">
            <label class="frp-label" for="frp-state">State <span aria-hidden="true">*</span></label>
            <select id="frp-state" name="state" required>
                <option value="">Select your state&hellip;</option>
                <?php foreach ( $us_states as $code => $name ) : ?>
                <option value="<?php echo esc_attr( $code ); ?>"><?php echo esc_html( $name ); ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="frp-field">
            <label class="frp-label">Services Offered</label>
            <div class="frp-services-grid">
                <?php foreach ( $service_options as $slug => $label ) : ?>
                <label class="frp-service-chip">
                    <input type="checkbox" name="services" value="<?php echo esc_attr( $slug ); ?>">
                    <?php echo esc_html( $label ); ?>
                </label>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="frp-field">
            <label class="frp-label">IICRC Certified?</label>
            <div class="frp-iicrc-options">
                <label class="frp-iicrc-option"><input type="radio" name="iicrc_certified" value="yes"> Yes</label>
                <label class="frp-iicrc-option"><input type="radio" name="iicrc_certified" value="no"> No</label>
                <label class="frp-iicrc-option"><input type="radio" name="iicrc_certified" value="in_progress"> In Progress</label>
            </div>
            <p class="frp-iicrc-helper">IICRC certification is displayed on your profile as a badge and improves credibility with homeowners.</p>
        </div>

        <div class="frp-field">
            <label class="frp-label" for="frp-license">License Number</label>
            <input type="text" id="frp-license" name="license_number" autocomplete="off" placeholder="Optional">
        </div>

        <div class="frp-field">
            <label class="frp-label" for="frp-years">Years in Business</label>
            <input type="number" id="frp-years" name="years_in_business" min="0" max="100" placeholder="Optional">
        </div>

        <div class="frp-field">
            <label class="frp-label" for="frp-zips">Service Area ZIP Codes</label>
            <input type="text" id="frp-zips" name="service_area_zips" placeholder="90210, 90211, 90212 &mdash; optional, comma-separated">
        </div>

        <div id="frp-join-error" class="frp-join-error" role="alert" aria-live="polite"></div>

        <button type="submit" id="frp-join-submit" class="frp-join-btn">Claim My Free Listing &rarr;</button>

        <p class="frp-join-notice">Already in our directory? We&rsquo;ll find your listing and send a verification email to confirm ownership.</p>
        <p class="frp-join-notice"><em>Paid upgrades available after you claim your listing. <a href="<?php echo $pricing_url; ?>">See what&rsquo;s included &rarr;</a></em></p>

    </form>
</section>

<section class="frp-join-benefits">
    <div class="frp-benefits-inner">
        <div class="frp-benefit-card">
            <h3>Your listing, your credentials</h3>
            <p>Once claimed, your business name, services, and credentials appear on your profile. IICRC certification and other self-reported credentials are displayed as badges to homeowners searching in your area.</p>
        </div>
        <div class="frp-benefit-card">
            <h3>Get matched with homeowners</h3>
            <p>When a homeowner submits a restoration request matching your service type and location, you get notified. Free listings receive leads routed through our matching system.</p>
        </div>
        <div class="frp-benefit-card">
            <h3>Paid listing: more visibility, direct contact</h3>
            <p>Paid listings unlock your phone number on your profile so homeowners can call you directly. When a homeowner contacts you from your profile page, that lead comes to you alone. Paid listings are also highlighted in directory search results and featured on the homepage.</p>
        </div>
    </div>
</section>

</div><!-- .frp-join-wrap -->

<script>
(function () {
    'use strict';
    var APPLY_URL = <?php echo $apply_url; ?>;
    var form      = document.getElementById('frp-join-form');
    var submitBtn = document.getElementById('frp-join-submit');
    var errorEl   = document.getElementById('frp-join-error');
    var successEl = document.getElementById('frp-join-success');
    var cooldown  = null;

    form.addEventListener('submit', function (e) {
        e.preventDefault();
        clearInterval(cooldown);
        submitBtn.disabled = true;
        errorEl.style.display = 'none';
        errorEl.textContent = '';

        // Collect services as array — FormData.forEach only returns the last
        // checked value for duplicate keys, so we query the DOM directly.
        var services = Array.from(
            form.querySelectorAll('input[name="services"]:checked')
        ).map(function (cb) { return cb.value; });

        // Collect remaining fields as a plain object
        var fd = new FormData(form);
        var body = {};
        fd.forEach(function (val, key) {
            if (key !== 'services') { body[key] = val; }
        });
        // Omit services key entirely when none checked — the /apply endpoint
        // treats a missing services param the same as an empty list (optional field).
        // Do NOT send services: [] to avoid any edge-case handling of empty JSON array.
        if (services.length) { body.services = services; }

        // years_in_business: endpoint uses absint — send as integer
        if (body.years_in_business !== undefined && body.years_in_business !== '') {
            body.years_in_business = parseInt(body.years_in_business, 10) || 0;
        }

        fetch(APPLY_URL, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(body),
        })
        .then(function (res) {
            return res.json().then(function (data) {
                return { status: res.status, data: data };
            });
        })
        .then(function (result) {
            if (result.status === 200) {
                form.style.display = 'none';
                successEl.style.display = 'block';
                successEl.scrollIntoView({ behavior: 'smooth', block: 'center' });
            } else if (result.status === 400) {
                var msg = (result.data && result.data.message)
                    ? result.data.message
                    : 'Please check your information and try again.';
                errorEl.textContent = msg;
                errorEl.style.display = 'block';
                submitBtn.disabled = false;
            } else if (result.status === 429) {
                errorEl.textContent = 'Too many applications from this connection. Please try again later.';
                errorEl.style.display = 'block';
                var remaining = 60;
                submitBtn.textContent = 'Try again in ' + remaining + 's';
                cooldown = setInterval(function () {
                    remaining -= 1;
                    if (remaining <= 0) {
                        clearInterval(cooldown);
                        submitBtn.disabled = false;
                        submitBtn.textContent = 'Claim My Free Listing \u2192';
                    } else {
                        submitBtn.textContent = 'Try again in ' + remaining + 's';
                    }
                }, 1000);
            } else {
                errorEl.textContent = 'Something went wrong. Please try again.';
                errorEl.style.display = 'block';
                submitBtn.disabled = false;
            }
        })
        .catch(function () {
            errorEl.textContent = 'Something went wrong. Please try again.';
            errorEl.style.display = 'block';
            submitBtn.disabled = false;
        });
    });
})();
</script>
    <?php
    return ob_get_clean();
}
```

- [ ] **Step 4: Upload to SiteGround and activate shortcode**

Upload `wordpress-plugins/frp-join.php` to `/wp-content/mu-plugins/` via SiteGround File Manager. (mu-plugins load automatically — no plugin activation step needed.)

In WP Admin → Pages → the page at slug `/join/`: add `[frp_join_form]` to the page body content and update.

- [ ] **Step 5: Run tests to verify they pass**

```bash
node --test tests/join.test.mjs 2>&1 | tail -20
```

Expected: Both tests pass.

- [ ] **Step 6: Commit**

```bash
git add wordpress-plugins/frp-join.php tests/join.test.mjs
git commit -m "feat: add [frp_join_form] shortcode for /join/ page

- 9 form fields matching /apply endpoint param names exactly
- Services collected as JSON array via querySelectorAll (not FormData)
- IICRC radio: yes/no/in_progress (exact server-validator values)
- 200: replace form with success message
- 400: show server error inline
- 429: 60-second countdown before re-enabling submit
- Benefit cards: spec copy from Part 1, Section 2

Co-Authored-By: Claude Sonnet 4.6 <noreply@anthropic.com>"
```

---

### Task 5: Redesign `stitch-html/frp-join.html`

**Spec reference:** Part 1 — visual reference only; this file is not deployed to WordPress.

**Files:**
- Modify: `stitch-html/frp-join.html`

**Context:**
- The current file uses stale "Restoration Guard" branding, invented copy ("Resilient Monolith", fake 94% retention stat), and wrong form fields. It needs a complete content replacement.
- Keep the existing Tailwind CDN script, Tailwind config block, font imports (Manrope, Inter, Material Symbols), and color palette — they are correct and must not change.
- This is a design reference for browser review only. No JS submission logic is needed — the shortcode in frp-join.php handles live behavior.

- [ ] **Step 1: Rewrite `stitch-html/frp-join.html`**

Replace the file with a redesigned version. Keep everything inside `<head>` unchanged (Tailwind CDN, config block, font links, `<style>` blocks). Replace all content inside `<body>`.

Changes by section:

**`<title>` tag** (only change in `<head>`):
```html
<title>For Pros | Find Restoration Pros</title>
```

**Nav brand** — in `<header>`, replace `Restoration Guard` with `Find Restoration Pros`.

**Main content** — replace everything inside `<main>` with:

```html
<main class="pt-24 pb-32 px-6 max-w-3xl mx-auto">

  <!-- Hero -->
  <section class="text-center mb-10">
    <h2 class="font-headline text-4xl md:text-5xl font-extrabold text-on-surface tracking-tight leading-tight mb-5">
      Claim your free listing and start receiving leads from homeowners in your area.
    </h2>
    <p class="font-body text-lg text-on-surface-variant leading-relaxed">
      Get your restoration business in front of people searching for help right now.
      Free to claim, no commitment required.
    </p>
  </section>

  <!-- Form card -->
  <section class="bg-surface-container-lowest rounded-xl shadow-sm border border-outline-variant/30 p-8 mb-16">
    <form class="space-y-6">

      <!-- business_name -->
      <div class="space-y-2">
        <label class="block text-sm font-semibold text-on-surface" for="business-name">Business Name *</label>
        <input class="w-full bg-surface-container-low border-none focus:ring-2 focus:ring-surface-tint/40 rounded-md py-3 px-4" id="business-name" name="business_name" type="text" placeholder="Your business name" required>
      </div>

      <!-- dispatch_phone -->
      <div class="space-y-2">
        <label class="block text-sm font-semibold text-on-surface" for="phone">Phone *</label>
        <input class="w-full bg-surface-container-low border-none focus:ring-2 focus:ring-surface-tint/40 rounded-md py-3 px-4" id="phone" name="dispatch_phone" type="tel" placeholder="(555) 555-5555" required>
      </div>

      <!-- contact_email -->
      <div class="space-y-2">
        <label class="block text-sm font-semibold text-on-surface" for="email">Email *</label>
        <input class="w-full bg-surface-container-low border-none focus:ring-2 focus:ring-surface-tint/40 rounded-md py-3 px-4" id="email" name="contact_email" type="email" placeholder="you@example.com" required>
      </div>

      <!-- state -->
      <div class="space-y-2">
        <label class="block text-sm font-semibold text-on-surface" for="state">State *</label>
        <select class="w-full bg-surface-container-low border-none focus:ring-2 focus:ring-surface-tint/40 rounded-md py-3 px-4 appearance-none" id="state" name="state" required>
          <option value="">Select your state…</option>
          <option value="CA">California</option>
          <option value="TX">Texas</option>
          <option value="FL">Florida</option>
          <!-- … all 50 states + DC … -->
        </select>
      </div>

      <!-- services checkboxes -->
      <div class="space-y-2">
        <label class="block text-sm font-semibold text-on-surface">Services Offered</label>
        <div class="flex flex-wrap gap-3">
          <label class="flex items-center gap-2 bg-surface-container px-4 py-2 rounded-lg cursor-pointer hover:bg-surface-variant transition-colors">
            <input class="rounded border-outline text-primary focus:ring-primary" type="checkbox" name="services" value="water-damage">
            <span class="text-sm font-medium">Water Damage</span>
          </label>
          <label class="flex items-center gap-2 bg-surface-container px-4 py-2 rounded-lg cursor-pointer hover:bg-surface-variant transition-colors">
            <input class="rounded border-outline text-primary focus:ring-primary" type="checkbox" name="services" value="fire-damage">
            <span class="text-sm font-medium">Fire &amp; Smoke Damage</span>
          </label>
          <label class="flex items-center gap-2 bg-surface-container px-4 py-2 rounded-lg cursor-pointer hover:bg-surface-variant transition-colors">
            <input class="rounded border-outline text-primary focus:ring-primary" type="checkbox" name="services" value="mold-remediation">
            <span class="text-sm font-medium">Mold Remediation</span>
          </label>
          <label class="flex items-center gap-2 bg-surface-container px-4 py-2 rounded-lg cursor-pointer hover:bg-surface-variant transition-colors">
            <input class="rounded border-outline text-primary focus:ring-primary" type="checkbox" name="services" value="storm-damage">
            <span class="text-sm font-medium">Storm Damage</span>
          </label>
          <label class="flex items-center gap-2 bg-surface-container px-4 py-2 rounded-lg cursor-pointer hover:bg-surface-variant transition-colors">
            <input class="rounded border-outline text-primary focus:ring-primary" type="checkbox" name="services" value="sewage-cleanup">
            <span class="text-sm font-medium">Sewage Cleanup</span>
          </label>
          <label class="flex items-center gap-2 bg-surface-container px-4 py-2 rounded-lg cursor-pointer hover:bg-surface-variant transition-colors">
            <input class="rounded border-outline text-primary focus:ring-primary" type="checkbox" name="services" value="structural">
            <span class="text-sm font-medium">Structural Repairs</span>
          </label>
          <label class="flex items-center gap-2 bg-surface-container px-4 py-2 rounded-lg cursor-pointer hover:bg-surface-variant transition-colors">
            <input class="rounded border-outline text-primary focus:ring-primary" type="checkbox" name="services" value="biohazard-cleanup">
            <span class="text-sm font-medium">Biohazard Cleanup</span>
          </label>
        </div>
      </div>

      <!-- iicrc_certified radio -->
      <div class="space-y-2">
        <label class="block text-sm font-semibold text-on-surface">IICRC Certified?</label>
        <div class="flex gap-6">
          <label class="flex items-center gap-2 text-sm cursor-pointer">
            <input type="radio" name="iicrc_certified" value="yes"> Yes
          </label>
          <label class="flex items-center gap-2 text-sm cursor-pointer">
            <input type="radio" name="iicrc_certified" value="no"> No
          </label>
          <label class="flex items-center gap-2 text-sm cursor-pointer">
            <input type="radio" name="iicrc_certified" value="in_progress"> In Progress
          </label>
        </div>
        <p class="text-xs text-on-surface-variant mt-1">IICRC certification is displayed on your profile as a badge and improves credibility with homeowners.</p>
      </div>

      <!-- license_number -->
      <div class="space-y-2">
        <label class="block text-sm font-semibold text-on-surface" for="license">License Number</label>
        <input class="w-full bg-surface-container-low border-none focus:ring-2 focus:ring-surface-tint/40 rounded-md py-3 px-4" id="license" name="license_number" type="text" placeholder="Optional">
      </div>

      <!-- years_in_business -->
      <div class="space-y-2">
        <label class="block text-sm font-semibold text-on-surface" for="years">Years in Business</label>
        <input class="w-full bg-surface-container-low border-none focus:ring-2 focus:ring-surface-tint/40 rounded-md py-3 px-4" id="years" name="years_in_business" type="number" min="0" max="100" placeholder="Optional">
      </div>

      <!-- service_area_zips -->
      <div class="space-y-2">
        <label class="block text-sm font-semibold text-on-surface" for="zips">Service Area ZIP Codes</label>
        <input class="w-full bg-surface-container-low border-none focus:ring-2 focus:ring-surface-tint/40 rounded-md py-3 px-4" id="zips" name="service_area_zips" type="text" placeholder="90210, 90211 — optional, comma-separated">
      </div>

      <!-- submit -->
      <button class="w-full py-3 px-6 bg-primary text-on-primary font-headline font-bold rounded transition-all active:scale-[0.98]" type="submit">
        Claim My Free Listing →
      </button>

      <!-- below-button copy -->
      <p class="text-sm text-on-surface-variant">
        Already in our directory? We'll find your listing and send a verification email to confirm ownership.
      </p>
      <p class="text-sm text-on-surface-variant">
        <em>Paid upgrades available after you claim your listing.
        <a href="/pricing/" class="underline text-primary">See what's included →</a></em>
      </p>

    </form>
  </section>

  <!-- Benefit cards -->
  <section class="grid grid-cols-1 md:grid-cols-3 gap-8 pb-8">
    <div class="flex flex-col gap-3">
      <span class="material-symbols-outlined text-3xl text-primary" style="font-variation-settings: 'FILL' 1;">verified</span>
      <h5 class="font-headline font-bold text-lg">Your listing, your credentials</h5>
      <p class="text-sm text-on-surface-variant leading-relaxed">Once claimed, your business name, services, and credentials appear on your profile. IICRC certification and other self-reported credentials are displayed as badges to homeowners searching in your area.</p>
    </div>
    <div class="flex flex-col gap-3">
      <span class="material-symbols-outlined text-3xl text-primary" style="font-variation-settings: 'FILL' 1;">notifications_active</span>
      <h5 class="font-headline font-bold text-lg">Get matched with homeowners</h5>
      <p class="text-sm text-on-surface-variant leading-relaxed">When a homeowner submits a restoration request matching your service type and location, you get notified. Free listings receive leads routed through our matching system.</p>
    </div>
    <div class="flex flex-col gap-3">
      <span class="material-symbols-outlined text-3xl text-primary" style="font-variation-settings: 'FILL' 1;">trending_up</span>
      <h5 class="font-headline font-bold text-lg">Paid listing: more visibility, direct contact</h5>
      <p class="text-sm text-on-surface-variant leading-relaxed">Paid listings unlock your phone number on your profile so homeowners can call you directly. When a homeowner contacts you from your profile page, that lead comes to you alone. Paid listings are also highlighted in directory search results and featured on the homepage.</p>
    </div>
  </section>

</main>
```

Note: The state `<select>` in the mockup shows only a few states for brevity — expand to all 50 + DC using the same list as frp-join.php Step 3.

- [ ] **Step 2: Open in browser and verify**

Open `stitch-html/frp-join.html` in a browser. Confirm:
- Headline matches spec exactly: "Claim your free listing and start receiving leads from homeowners in your area."
- All 9 fields render with correct label text and `name` attributes
- IICRC radio shows three options: Yes / No / In Progress
- Submit button reads "Claim My Free Listing →"
- Both below-button lines appear
- Three benefit cards with correct icons (`verified`, `notifications_active`, `trending_up`) and spec copy
- No "Restoration Guard" text, no fake stats, no membership-tier sidebar

- [ ] **Step 3: Commit**

```bash
git add stitch-html/frp-join.html
git commit -m "design: redesign frp-join.html stitch mockup with spec copy and correct form fields

- Brand: Find Restoration Pros (removed Restoration Guard)
- Hero: spec headline and subheadline
- Form: all 9 fields with correct parameter names; IICRC radio added
- Benefit cards: 3 cards matching spec copy (verified / notifications_active / trending_up)
- Removed: fabricated stats, partner logos, membership-tier sidebar

Co-Authored-By: Claude Sonnet 4.6 <noreply@anthropic.com>"
```

---
