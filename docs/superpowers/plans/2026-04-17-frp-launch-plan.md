# FRP Launch Implementation Plan

> **For agentic workers:** REQUIRED: Use superpowers:subagent-driven-development (if subagents available) or superpowers:executing-plans to implement this plan. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ship findrestorationpros.com (FRP) as a revenue-generating contractor directory + lead marketplace by (0) decommissioning iRestorationPros (iRP), (1) completing launch blockers, (2) closing security gaps, (3) aligning plan/code scope, and (4) pivoting SEO to a defensible directory angle.

**Architecture:**
- WordPress on SiteGround + Elementor for presentation
- Two custom plugins: `frp-directory` (existing — CPTs, dispatch, REST) and `frp-billing` (new — Stripe subscriptions)
- Stripe Checkout + Billing Portal for contractor subscriptions; Stripe webhook updates `listing_tier` / `listing_expires` on the `restoration_pro` CPT
- Cloudflare Turnstile replaces the shared `FRP_LEAD_TOKEN` as the primary bot deterrent on public forms
- Per-request nonces (short-TTL, per-session) replace the global token for authenticated form submission
- Contractor dashboard = WordPress authenticated pages rendered via shortcodes tied to a `restoration_pro` role (no SPA)
- Make.com stays as the outbound notification router (email/SMS fan-out to dispatched pros)
- Integration tests = Node (`node:test`) hitting a staging WP instance via REST; no WP-CLI/PHPUnit harness required
- FRP content strategy pivots from `"[service] [city]"` programmatic pages (which iRP was also generating — cannibalizing) to aggregate / comparison / "best of" content with real contractor data and reviews

**Tech Stack:**
- WordPress 6.x, PHP 8.1+
- Elementor + vanilla JS (no React)
- Stripe PHP SDK (`stripe/stripe-php`) + Stripe Checkout (hosted) + Billing Portal + webhooks
- Cloudflare Turnstile (free, replaces reCAPTCHA)
- Node 18+ for CLI + tests (`node:test`, no Jest)
- Make.com for outbound webhooks
- Claude Sonnet 4.6 (content generation, FRP only)
- DataForSEO / Google Places / Yelp Fusion for contractor enrichment (already configured)

**Conventions used in this plan:**
- "FRP" = findrestorationpros.com; "iRP" = irestorationpros.com (decommissioned in Chunk 0)
- File paths are relative to the project root (`.../stoic-jennings/`)
- Line numbers reference the current state of files; executors should re-locate if drift
- Every task follows: write failing test → verify fails → implement → verify passes → commit
- Where a WordPress endpoint can't be reasonably unit-tested, "test" = an integration call against a staging WP instance; the test harness in Task 1.1 provides this
- Commits are small and frequent — one per step where sensible

**Decision log (locked in by this plan):**
- D1. iRP is retired. No new content, no new leads to iRP. 301 redirect docs produced but executed manually by ops.
- D2. FRP will use Stripe Checkout + Billing Portal (hosted) not Stripe Elements — fastest to ship, PCI scope minimized.
- D3. Contractor auth uses standard WordPress user accounts with a custom `restoration_pro` role, not a separate auth system. Each WP user links to one `restoration_pro` post via `user_meta.frp_pro_id`. **Limitation:** one user = one pro profile in v1. Multi-location franchises (one account, many branches) are not supported and will need a v2 model (add a `frp_pro_group` post type that owns many `restoration_pro` children). Document this in onboarding copy so franchises know to request a custom setup.
- D4. `emergency_flow` source value is **removed** (see Task 3.2 — the UI no longer presents it post 6444339).
- D5. `scope` field on leads is **removed** from the plugin (see Task 3.3 — the 5-step UI never captures it; dead schema).
- D6. Existing `FRP_LEAD_SECRET` global constant is **retired** entirely. Replaced by Turnstile + per-request nonce.
- D7. Content generation for FRP moves from city×service pages to aggregate "best of" + contractor spotlight formats.

---

## Chunk 0: Decommission iRestorationPros (scope reduction)

Halt all iRP publishing. Strip iRP from config/env/scripts. Preserve historical data. Produce a redirect runbook for ops to execute at the DNS/hosting layer.

### Task 0.0: Provision environments (prerequisite — owner: ops)

Before any other task in this plan runs. No code here; the deliverable is provisioned infrastructure plus credentials in `.env.test`.

**Deliverables:**
- [ ] Staging WordPress instance at `https://staging.findrestorationpros.com` mirroring production plugins and theme
- [ ] Nightly DB reset from a known fixture dump (so tests aren't flaky due to leftover state)
- [ ] Application Password for a `test-runner` admin user; credentials recorded in a secret manager (1Password / Doppler), values pasted into each dev's local `.env.test`
- [ ] MailHog or equivalent mail-capture service installed on staging (captures `wp_mail` output for email assertion tests)
- [ ] Stripe account in test-mode; webhook endpoint `https://staging.findrestorationpros.com/wp-json/frp/v1/stripe/webhook` created (even before the handler exists — it'll start getting 404s, fine); `STRIPE_CLI` installed locally for developer replay
- [ ] Cloudflare Turnstile widget provisioned (test keys: sitekey `1x00000000000000000000AA`, secret `1x0000000000000000000000000000000AA` which always pass — documented at https://developers.cloudflare.com/turnstile/troubleshooting/testing/)
- [ ] SiteGround/hosting layer confirmed to sit behind Cloudflare (so `CF-Connecting-IP` header reaches PHP)

**Tracked deferrals from Task 0.0 (2026-04-19 run):** the following two checks are intentionally ⏭️ skipped on staging and MUST be resolved before the listed downstream task:
1. **Subscriber app password** — `verify-staging.mjs [3/7]`. Provision a `test-subscriber` user (role=Subscriber) + Application Password; paste into `.env.test`. **Blocks Task 1.3** (auth-gating tests for `/frp/v1/apply` require a non-admin fixture).
2. **Cloudflare-in-front on staging** — `verify-staging.mjs [6/7]`. Staging2 is currently direct-from-SiteGround; no `cf-ray`. Put staging behind CF (or accept the parity gap and re-enable the check as a hard-fail). **Blocks Task 2.2** (`CF-Connecting-IP` trust chain cannot be exercised without CF in front).

**Acceptance:** A developer can run `curl https://staging.findrestorationpros.com/wp-json/wp/v2/types` and receive a 200 + JSON. MailHog UI is reachable at `https://staging.findrestorationpros.com/mailhog/` (or agreed URL). Stripe CLI `stripe listen --forward-to staging.findrestorationpros.com/wp-json/frp/v1/stripe/webhook` connects without error.

**This task blocks all tests in Chunks 1–4.** If any task below reports a missing staging dependency, return here.

### Task 0.1: Snapshot iRP state before changes

**Files:**
- Create: `docs/superpowers/plans/artifacts/irp-snapshot-2026-04-17.md`

- [ ] **Step 1: Capture iRP inventory**

Run (locally, with env loaded):
```bash
node -e "import('./scripts/wp-publisher.js').then(async m => {
  const p = new m.WordPressPublisher('leadCapture');
  const pages = await p.getAllPages();
  const posts = await p.getAllPosts();
  console.log(JSON.stringify({pages: pages.length, posts: posts.length, pageSlugs: pages.map(x=>x.slug), postSlugs: posts.map(x=>x.slug)}, null, 2));
}).catch(e => { console.error(e); process.exit(1); });"
```
Expected: JSON dump of every iRP page/post slug. Save the output to the snapshot file.

- [ ] **Step 2: Commit the snapshot**

```bash
git add docs/superpowers/plans/artifacts/irp-snapshot-2026-04-17.md
git commit -m "docs(frp): snapshot iRP inventory before decommission"
```

### Task 0.2: Remove iRP from config

**Files:**
- Modify: `config/config.js` (remove `sites.leadCapture`, `gscSiteUrls.leadCapture`, `ga4PropertyIds.leadCapture`, the 49 `seedCities` reference iRP still uses, and any `settings.contentSchedule` that is iRP-oriented)
- Modify: `.env.example` (remove `WP_LEADCAPTURE_*`, `GA4_PROPERTY_ID_LEADCAPTURE`)
- Test: `tests/config.test.mjs` (new)

- [ ] **Step 1: Write a failing test that asserts iRP keys are gone**

Create `tests/config.test.mjs`:
```javascript
import test from 'node:test';
import assert from 'node:assert/strict';
import { CONFIG } from '../config/config.js';

test('config has no iRP / leadCapture references', () => {
  assert.equal(CONFIG.sites.leadCapture, undefined, 'sites.leadCapture must be removed');
  assert.equal(CONFIG.google.gscSiteUrls.leadCapture, undefined, 'gscSiteUrls.leadCapture must be removed');
  assert.equal(CONFIG.google.ga4PropertyIds.leadCapture, undefined, 'ga4PropertyIds.leadCapture must be removed');
});

test('config still has FRP (authority) site', () => {
  assert.ok(CONFIG.sites.authority);
  assert.equal(CONFIG.sites.authority.name, 'findrestorationpros');
});
```

- [ ] **Step 2: Run — expect FAIL (leadCapture still present)**

```bash
node --test tests/config.test.mjs
```
Expected: FAIL — `sites.leadCapture must be removed`.

- [ ] **Step 3: Delete the `leadCapture` block from `config/config.js`**

Remove `sites.leadCapture` (config.js:54-59), `gscSiteUrls.leadCapture` (config.js:83), `ga4PropertyIds.leadCapture` (config.js:87). Keep `sites.authority` untouched.

- [ ] **Step 4: Delete iRP env rows from `.env.example`**

Remove lines 2–4 (`WP_LEADCAPTURE_*`) and line 33 (`GA4_PROPERTY_ID_LEADCAPTURE`).

- [ ] **Step 5: Run — expect PASS**

```bash
node --test tests/config.test.mjs
```
Expected: PASS for both tests.

- [ ] **Step 6: Commit**

```bash
git add config/config.js .env.example tests/config.test.mjs
git commit -m "chore(frp): remove iRP config keys and env vars"
```

### Task 0.3: Strip iRP from WP publisher

**Files:**
- Modify: `scripts/wp-publisher.js`
- Test: `tests/wp-publisher.test.mjs` (new)

- [ ] **Step 1: Write failing test that instantiating with 'leadCapture' throws**

```javascript
import test from 'node:test';
import assert from 'node:assert/strict';
import { WordPressPublisher } from '../scripts/wp-publisher.js';

test('publisher rejects leadCapture site key', () => {
  assert.throws(() => new WordPressPublisher('leadCapture'), /unknown site|retired|authority only/i);
});

test('publisher accepts authority', () => {
  const p = new WordPressPublisher('authority');
  assert.equal(p.siteName, 'authority');
});
```

- [ ] **Step 2: Run — expect FAIL (currently accepts 'leadCapture')**

```bash
node --test tests/wp-publisher.test.mjs
```

- [ ] **Step 3: Modify the constructor in `scripts/wp-publisher.js`**

In the constructor, reject any site key other than `'authority'`:
```javascript
constructor(siteKey) {
  if (siteKey !== 'authority') {
    throw new Error(`Unknown site "${siteKey}" — FRP is authority-only since iRP was retired 2026-04-17`);
  }
  const site = CONFIG.sites.authority;
  this.siteName = siteKey;
  this.base = `${site.url}/wp-json/wp/v2`;
  this.auth = 'Basic ' + Buffer.from(`${site.username}:${site.appPassword}`).toString('base64');
}
```

- [ ] **Step 4: Run — expect PASS**

```bash
node --test tests/wp-publisher.test.mjs
```

- [ ] **Step 5: Commit**

```bash
git add scripts/wp-publisher.js tests/wp-publisher.test.mjs
git commit -m "refactor(frp): lock wp-publisher to authority site only"
```

### Task 0.4: Archive iRP-specific scripts

**Files:**
- Move: `scripts/orchestrator.js` → `archive/scripts/orchestrator.js`
- Move: `scripts/city-page-generator.js` → `archive/scripts/city-page-generator.js`
- Move: `scripts/publish-core-pages.js` → `archive/scripts/publish-core-pages.js`
- Move: `scripts/gsc-reader.js` → `archive/scripts/gsc-reader.js` (can be revived for FRP later, see Chunk 4)
- Modify: `package.json` (remove `test-gsc`, `generate-page`, `generate-dry`, `run-weekly`, `single-page` scripts)
- Create: `archive/README.md`

- [ ] **Step 1: Create archive directory and move scripts**

```bash
mkdir -p archive/scripts
git mv scripts/orchestrator.js archive/scripts/orchestrator.js
git mv scripts/city-page-generator.js archive/scripts/city-page-generator.js
git mv scripts/publish-core-pages.js archive/scripts/publish-core-pages.js
git mv scripts/gsc-reader.js archive/scripts/gsc-reader.js
```

- [ ] **Step 2: Write the archive README**

Contents of `archive/README.md`:
```markdown
# Archived scripts — iRestorationPros era

These scripts drove irestorationpros.com (iRP) content generation before the 2026-04-17
pivot to FRP-only. They reference `CONFIG.sites.leadCapture` (removed) and
`CONFIG.zapier` (never existed). Do not resurrect without porting to the FRP config.

- `orchestrator.js` — weekly cron brain (read GSC → plan with Claude → publish)
- `city-page-generator.js` — programmatic [service] × [city] page generator
- `publish-core-pages.js` — one-shot core pages publisher
- `gsc-reader.js` — Google Search Console intelligence report (reusable for FRP if re-pointed)
```

- [ ] **Step 3: Prune `package.json` scripts**

Remove `test-gsc`, `generate-page`, `generate-dry`, `run-weekly`, `single-page`. Keep `test-wp` and `test-image`. Add:
```json
"test": "node --test tests/"
```

- [ ] **Step 4: Run — ensure nothing else imports the archived files**

```bash
grep -rn "scripts/orchestrator\|scripts/city-page-generator\|scripts/publish-core-pages\|scripts/gsc-reader" scripts/ tests/ config/ 2>/dev/null
```
Expected: no output (all references gone).

- [ ] **Step 5: Commit**

```bash
git add -A
git commit -m "chore(frp): archive iRP-era scripts; FRP is the only target"
```

### Task 0.5: Produce redirect runbook for ops

**Files:**
- Create: `docs/superpowers/runbooks/irp-to-frp-redirect.md`

- [ ] **Step 1: Write the runbook**

Contents cover: (a) 301 rules at SiteGround/Cloudflare level mapping iRP city-page URLs to FRP `/service-areas/[city]/` (the closest FRP equivalent) or FRP homepage for unmatched paths; (b) Search Console change-of-address requirement; (c) GA4 cross-site event preservation; (d) timeline: soft-launch 7-day redirect check, then remove iRP WP instance after 30 days.

- [ ] **Step 2: Commit**

```bash
git add docs/superpowers/runbooks/irp-to-frp-redirect.md
git commit -m "docs(frp): add iRP→FRP redirect runbook for ops"
```

---

## Chunk 1: Launch Blockers

The four launch-blocking gaps from the audit:
1. Contractor dashboard (login + lead list + billing) — completely missing
2. Stripe billing — `listing_tier` / `is_paid_listing` / `listing_expires` fields exist but nothing writes to them
3. `/frp/v1/apply` handler — route registered, handler missing
4. `/frp/v1/leads/{id}` (Route B) — route registered, handler empty/incomplete

Plus prerequisite: an integration test harness so the rest of this chunk can be TDD'd.

### Task 1.1: Staging-WP integration test harness

**Files:**
- Create: `tests/helpers/wp-client.mjs`
- Create: `tests/helpers/staging.mjs`
- Create: `tests/smoke.test.mjs`
- Create: `.env.test.example`

- [ ] **Step 1: Document staging WP requirements**

Ops creates a dedicated staging WP instance at `https://staging.findrestorationpros.com` (SiteGround subdomain) with the current `frp-directory.php` plugin installed. An Application Password for user `test-runner` is provisioned with `manage_options`. Staging DB is reset nightly from a fixture dump.

- [ ] **Step 2: Write `tests/helpers/wp-client.mjs`**

```javascript
// Minimal REST client for staging FRP
import { readFileSync } from 'node:fs';

const ENV = process.env.NODE_ENV === 'test' ? '.env.test' : '.env';
try {
  const text = readFileSync(ENV, 'utf8');
  for (const line of text.split('\n')) {
    const m = line.match(/^([A-Z_][A-Z0-9_]*)=(.*)$/);
    if (m) process.env[m[1]] ??= m[2];
  }
} catch { /* ignore */ }

// Env names match .env.test.example (provisioned by Task 0.0).
const BASE = process.env.FRP_STAGING_URL || 'https://staging.findrestorationpros.com';
const AUTH = 'Basic ' + Buffer.from(
  `${process.env.FRP_STAGING_USERNAME}:${process.env.FRP_STAGING_APP_PASSWORD}`
).toString('base64');

// Subscriber-role auth for admin-gating tests (see Task 1.3.5d).
const SUB_AUTH = 'Basic ' + Buffer.from(
  `${process.env.FRP_STAGING_SUBSCRIBER_USERNAME}:${process.env.FRP_STAGING_SUBSCRIBER_APP_PASSWORD}`
).toString('base64');

// `auth`: false = no auth, true = admin, 'subscriber' = non-admin user.
function resolveAuth(auth) {
  if (auth === true) return AUTH;
  if (auth === 'subscriber') return SUB_AUTH;
  return null;
}

export async function frpGet(path, { auth = false } = {}) {
  const authHeader = resolveAuth(auth);
  const res = await fetch(BASE + path, {
    headers: authHeader ? { Authorization: authHeader } : {},
  });
  return { status: res.status, body: await res.json().catch(() => null) };
}

export async function frpPost(path, body, { auth = false, headers = {} } = {}) {
  const authHeader = resolveAuth(auth);
  const res = await fetch(BASE + path, {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      ...(authHeader ? { Authorization: authHeader } : {}),
      ...headers,
    },
    body: JSON.stringify(body),
  });
  return { status: res.status, body: await res.json().catch(() => null) };
}

export { BASE };
```

- [ ] **Step 3: Write `tests/helpers/staging.mjs` (fixture helpers)**

Provides `createTestPro(opts)` and `deleteTestPro(id)` that use the authenticated REST endpoints to seed+teardown a `restoration_pro` for dispatch tests. Also a `resetTestPros()` helper that deletes anything with meta `test_fixture=1`.

- [ ] **Step 4: Write `tests/smoke.test.mjs`**

```javascript
import test from 'node:test';
import assert from 'node:assert/strict';
import { frpGet } from './helpers/wp-client.mjs';

test('staging WP is reachable and FRP plugin is active', async () => {
  const { status, body } = await frpGet('/wp-json/frp/v1/search?zip=90210&service=water-damage');
  assert.equal(status, 200);
  assert.ok(Array.isArray(body));
});
```

- [ ] **Step 5: Run — expect PASS (assumes staging is up)**

```bash
STAGING_WP_URL=... STAGING_WP_USER=... STAGING_WP_PASS=... node --test tests/smoke.test.mjs
```
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add tests/ .env.test.example
git commit -m "test(frp): add staging WP integration harness"
```

### Task 1.2: Add `restoration_pro` user role + User↔Pro binding

**Files:**
- Modify: `wordpress-plugins/frp-directory.php` (add role registration, activation hook, user meta registration)
- Test: `tests/role-binding.test.mjs` (new)

**Design:** A WordPress user with role `restoration_pro` has `user_meta.frp_pro_id` = the post ID of their `restoration_pro` CPT row. One user = one pro (1:1). Admins (`manage_options`) can see all; pros can only see their own.

- [ ] **Step 1: Write failing integration test**

```javascript
import test from 'node:test';
import assert from 'node:assert/strict';
import { frpPost, frpGet } from './helpers/wp-client.mjs';

test('admin can bind a WP user to a restoration_pro post', async () => {
  // Create a pro and a user via the new admin endpoint
  const { status, body } = await frpPost('/wp-json/frp/v1/admin/bind-pro-user',
    { user_login: 'testpro1', pro_id: 0 /* auto-create */ },
    { auth: true }
  );
  assert.equal(status, 200);
  assert.ok(body.user_id > 0);
  assert.ok(body.pro_id > 0);
});
```

- [ ] **Step 2: Run — expect FAIL (endpoint doesn't exist)**

- [ ] **Step 3: Implement role + binding endpoint in `frp-directory.php`**

Add:
```php
// Role registration — runs on activation
register_activation_hook( __FILE__, function() {
    add_role( 'restoration_pro', 'Restoration Pro', [
        'read' => true,
        // No post editing rights; they only access their dashboard
    ] );
} );

// User meta for pro binding
add_action( 'init', function() {
    register_meta( 'user', 'frp_pro_id', [
        'type'          => 'integer',
        'single'        => true,
        'show_in_rest'  => false,
        'auth_callback' => function() { return current_user_can( 'manage_options' ); },
    ] );
} );

// Helper — get current user's pro ID, or 0
function frp_current_pro_id() {
    $uid = get_current_user_id();
    if ( ! $uid ) return 0;
    return (int) get_user_meta( $uid, 'frp_pro_id', true );
}

// Admin-only binding endpoint (added to frp_register_rest_routes)
register_rest_route( 'frp/v1', '/admin/bind-pro-user', [
    'methods'             => 'POST',
    'callback'            => 'frp_admin_bind_handler',
    'permission_callback' => function() { return current_user_can( 'manage_options' ); },
] );

function frp_admin_bind_handler( WP_REST_Request $r ) {
    $login = sanitize_user( $r->get_param( 'user_login' ) );
    $pro_id = (int) $r->get_param( 'pro_id' );
    if ( ! $login ) return new WP_Error( 'bad_request', 'user_login required', [ 'status' => 400 ] );

    $user = get_user_by( 'login', $login );
    if ( ! $user ) {
        $pass = wp_generate_password( 24 );
        $user_id = wp_insert_user( [
            'user_login' => $login,
            'user_pass'  => $pass,
            'user_email' => $login . '@placeholder.invalid',
            'role'       => 'restoration_pro',
        ] );
        if ( is_wp_error( $user_id ) ) return $user_id;
    } else {
        $user_id = $user->ID;
        $user->set_role( 'restoration_pro' );
    }

    if ( $pro_id === 0 ) {
        $pro_id = wp_insert_post( [
            'post_type'   => 'restoration_pro',
            'post_status' => 'draft',
            'post_title'  => $login,
        ] );
    }

    update_user_meta( $user_id, 'frp_pro_id', $pro_id );
    return [ 'user_id' => $user_id, 'pro_id' => $pro_id ];
}
```

- [ ] **Step 4: Deploy to staging, re-run test — expect PASS**

- [ ] **Step 5: Commit**

```bash
git add wordpress-plugins/frp-directory.php tests/role-binding.test.mjs
git commit -m "feat(frp): add restoration_pro role and user↔pro binding endpoint"
```

### Task 1.3: Implement `/frp/v1/apply` handler (contractor onboarding)

**Files:**
- Modify: `wordpress-plugins/frp-directory.php` (add `frp_apply_handler`)
- Test: `tests/apply.test.mjs` (new)

**Design:** Public endpoint. Accepts business info, license #, contact email, service competencies (whitelist), and service-area ZIPs/city. Creates a `restoration_pro` CPT as `draft` + a `restoration_pro` user (bound via frp_admin_bind mechanism in Task 1.2) + triggers an email to admin for manual review + triggers a "Thanks, we'll review within 2 business days" email to applicant. No auto-approval in v1.

Fields to accept (from `stitch-html/frp-join.html:179-240`): `business_name` (required), `contact_name`, `contact_email` (required), `dispatch_phone` (required), `license_number`, `years_in_business`, `services[]` (whitelist), `service_area_zips` (comma-separated), `service_area_city`, `state` (required).

- [ ] **Step 1: Write failing integration test**

```javascript
import test from 'node:test';
import assert from 'node:assert/strict';
import { frpPost } from './helpers/wp-client.mjs';

test('apply creates a draft pro and returns application id', async () => {
  const { status, body } = await frpPost('/wp-json/frp/v1/apply', {
    business_name: 'Acme Restoration LLC',
    contact_name: 'Jane Doe',
    contact_email: 'jane@acmerestoration.test',
    dispatch_phone: '5551234567',
    license_number: 'CSLB-12345',
    years_in_business: 8,
    services: ['water-damage', 'mold-remediation'],
    service_area_zips: '90210,90211',
    service_area_city: 'Beverly Hills',
    state: 'CA',
  });
  assert.equal(status, 200);
  assert.ok(body.application_id > 0);
});

test('apply rejects missing required fields', async () => {
  const { status } = await frpPost('/wp-json/frp/v1/apply', { business_name: 'x' });
  assert.equal(status, 400);
});

test('apply rejects invalid service in services[]', async () => {
  const { status } = await frpPost('/wp-json/frp/v1/apply', {
    business_name: 'X', contact_email: 'x@x.test', dispatch_phone: '5551234567',
    state: 'CA', services: ['rocket-science'],
  });
  assert.equal(status, 400);
});
```

- [ ] **Step 2: Run — expect FAIL**

- [ ] **Step 3: Implement `frp_apply_handler`**

In `frp-directory.php`, add:
```php
function frp_apply_handler( WP_REST_Request $request ) {
    // Rate limit: 2 applications per IP per hour
    if ( ! frp_check_rate_limit( 'apply', 2, HOUR_IN_SECONDS ) ) {
        return new WP_Error( 'rate_limited', 'Too many applications; try later.', [ 'status' => 429 ] );
    }

    $valid_services = [ 'water-damage','mold-remediation','fire-damage','storm-damage','sewage-cleanup','structural','biohazard-cleanup' ];

    $business = sanitize_text_field( $request->get_param( 'business_name' ) ?? '' );
    $contact  = sanitize_text_field( $request->get_param( 'contact_name' ) ?? '' );
    $email    = sanitize_email( $request->get_param( 'contact_email' ) ?? '' );
    $phone    = sanitize_text_field( $request->get_param( 'dispatch_phone' ) ?? '' );
    $license  = sanitize_text_field( $request->get_param( 'license_number' ) ?? '' );
    $years    = (int) ( $request->get_param( 'years_in_business' ) ?? 0 );
    $zips     = sanitize_text_field( $request->get_param( 'service_area_zips' ) ?? '' );
    $city     = sanitize_text_field( $request->get_param( 'service_area_city' ) ?? '' );
    $state    = strtoupper( sanitize_text_field( $request->get_param( 'state' ) ?? '' ) );
    $services_raw = (array) ( $request->get_param( 'services' ) ?? [] );

    if ( ! $business || ! $email || ! $phone || ! $state ) {
        return new WP_Error( 'bad_request', 'business_name, contact_email, dispatch_phone, state are required.', [ 'status' => 400 ] );
    }
    if ( ! is_email( $email ) ) {
        return new WP_Error( 'bad_request', 'Invalid contact_email.', [ 'status' => 400 ] );
    }
    if ( ! preg_match( '/^\+?[\d\s\-().]{7,20}$/', $phone ) ) {
        return new WP_Error( 'bad_request', 'Invalid dispatch_phone.', [ 'status' => 400 ] );
    }
    $services = array_values( array_intersect( $services_raw, $valid_services ) );
    if ( ! empty( $services_raw ) && count( $services ) !== count( $services_raw ) ) {
        return new WP_Error( 'bad_request', 'One or more services invalid.', [ 'status' => 400 ] );
    }

    // Create the draft pro
    $pro_id = wp_insert_post( [
        'post_type'   => 'restoration_pro',
        'post_status' => 'draft',
        'post_title'  => $business,
    ] );
    if ( is_wp_error( $pro_id ) ) return $pro_id;

    update_post_meta( $pro_id, 'contact_name',    $contact );
    update_post_meta( $pro_id, 'contact_email',   $email );
    update_post_meta( $pro_id, 'dispatch_phone',  $phone );
    update_post_meta( $pro_id, 'dispatch_email',  $email );
    update_post_meta( $pro_id, 'license_number', $license );
    update_post_meta( $pro_id, 'years_in_business', $years );
    update_post_meta( $pro_id, 'zip_codes', $zips );
    update_post_meta( $pro_id, 'city',  $city );
    update_post_meta( $pro_id, 'state', $state );
    update_post_meta( $pro_id, 'services', implode( ',', $services ) );
    update_post_meta( $pro_id, 'listing_status', 'pending_review' );
    update_post_meta( $pro_id, 'listing_tier',   'free' );
    update_post_meta( $pro_id, 'is_paid_listing', 0 );
    update_post_meta( $pro_id, 'joined_source', 'apply_form' );
    update_post_meta( $pro_id, 'date_seeded', gmdate( 'c' ) );

    // Fire notification emails (helper in frp-emails.php, Task 1.9)
    do_action( 'frp_application_submitted', $pro_id );

    return [ 'application_id' => $pro_id, 'status' => 'pending_review' ];
}
```

Note that `license_number` is a new private meta — add it to the `$private_fields` list near `frp-directory.php:147-154`.

- [ ] **Step 4: Deploy to staging; run tests — expect PASS (3/3)**

- [ ] **Step 5: Commit**

```bash
git add wordpress-plugins/frp-directory.php tests/apply.test.mjs
git commit -m "feat(frp): implement /apply contractor onboarding handler"
```

### Task 1.3.5: Claim-existing-listing flow (dedupe against seeded pros)

**Why:** FRP launches with hundreds of seeded `restoration_pro` entries scraped from Yelp + Google Places (`joined_source=seed`). Task 1.3 as-written would create a duplicate draft whenever the real owner applies through `/apply`. This task inserts a match-and-claim layer ahead of the insert.

**Design summary** (see conversation thread for full rationale):
- **Strong match** (license_number / google_place_id / yelp_id) → offer claim, no insert.
- **Medium match** (phone + fuzzy-name ≥0.85, or website-domain + state) → offer claim AND create an admin-review ticket as a safety net.
- **Weak match** (name-only or address-only) → admin-review ticket, no insert, no email to applicant yet.
- **No match** → fall through to Task 1.3's original insert path.

Claim verification email is sent to the **seeded business's on-file email** (from Places/Yelp enrichment), NOT the email the applicant typed. This prevents identity theft via impersonation applications.

**New meta fields on `restoration_pro` CPT** (add to `$private_fields` list near `frp-directory.php:147-154`):
- `claim_status` — `unclaimed` (default seeded) | `claim_pending` | `claimed` | `disputed`
- `claim_token`, `claim_token_expiry` — same pattern as `lead_update_token`
- `claimed_by_user` (int, WP user ID)
- `date_claimed` (ISO timestamp)
- Extend `joined_source` valid values: `seed` | `apply_new` | `apply_claim` | `admin_manual`

**New CPT** (admin-only): `frp_claim_review` for weak/medium matches that need human eyes.

---

#### Task 1.3.5a: Match function + tier-specific tests

**Files:**
- Create: `wordpress-plugins/includes/frp-match.php`
- Modify: `wordpress-plugins/frp-directory.php` (load the include)
- Test: `tests/pro-match.test.mjs`

**Contract:** `frp_match_applicant( array $applicant ) : array` returns `[ 'tier' => 'strong'|'medium'|'weak'|'none', 'pro_id' => int, 'reason' => string, 'signals' => [] ]`.

- [ ] **Step 1: Write failing tests — one per tier**

```javascript
import test from 'node:test';
import assert from 'node:assert/strict';
import { frpPost } from './helpers/wp-client.mjs';
import { seedPro } from './helpers/staging.mjs';

test('strong match: license_number exact', async () => {
  const seeded = await seedPro({ license_number: 'CSLB-123456', state: 'CA', business_name: 'Acme Restoration' });
  const { body } = await frpPost('/wp-json/frp/v1/admin/match-test', {
    applicant: { license_number: 'CSLB-123456', state: 'CA', business_name: 'Different Name Inc' },
  }, { auth: true });
  assert.equal(body.tier, 'strong');
  assert.equal(body.pro_id, seeded.pro_id);
  assert.match(body.reason, /license/i);
});

test('strong match: google_place_id', async () => {
  const seeded = await seedPro({ google_place_id: 'ChIJxxxxxx', business_name: 'Acme' });
  const { body } = await frpPost('/wp-json/frp/v1/admin/match-test', {
    applicant: { google_place_id: 'ChIJxxxxxx' },
  }, { auth: true });
  assert.equal(body.tier, 'strong');
  assert.equal(body.pro_id, seeded.pro_id);
});

test('medium match: phone + fuzzy-name ≥0.85', async () => {
  const seeded = await seedPro({ phone: '5551234567', business_name: 'Acme Restoration Services, LLC' });
  const { body } = await frpPost('/wp-json/frp/v1/admin/match-test', {
    applicant: { dispatch_phone: '(555) 123-4567', business_name: 'Acme Restoration Services LLC' },
  }, { auth: true });
  assert.equal(body.tier, 'medium');
  assert.equal(body.pro_id, seeded.pro_id);
});

test('medium match: website-domain + state', async () => {
  const seeded = await seedPro({ website: 'https://www.acmerestoration.com/contact', state: 'CA' });
  const { body } = await frpPost('/wp-json/frp/v1/admin/match-test', {
    applicant: { website: 'http://acmerestoration.com', state: 'CA' },
  }, { auth: true });
  assert.equal(body.tier, 'medium');
});

test('weak match: name-only fuzzy, different phone/state', async () => {
  await seedPro({ business_name: 'Acme Restoration', state: 'CA', phone: '5550000000' });
  const { body } = await frpPost('/wp-json/frp/v1/admin/match-test', {
    applicant: { business_name: 'Acme Restoration', state: 'TX', dispatch_phone: '5559999999' },
  }, { auth: true });
  assert.equal(body.tier, 'weak');
});

test('no match: distinct signals', async () => {
  const { body } = await frpPost('/wp-json/frp/v1/admin/match-test', {
    applicant: { business_name: 'Totally New Biz XYZ', dispatch_phone: '5552220000', state: 'NV' },
  }, { auth: true });
  assert.equal(body.tier, 'none');
  assert.equal(body.pro_id, 0);
});

test('strong beats medium — license wins over phone mismatch', async () => {
  const withLicense = await seedPro({ license_number: 'CSLB-A', business_name: 'A Corp', phone: '5551110000' });
  await seedPro({ business_name: 'A Corp', phone: '5552220000' }); // decoy fuzzy-name
  const { body } = await frpPost('/wp-json/frp/v1/admin/match-test', {
    applicant: { license_number: 'CSLB-A', business_name: 'A Corp', dispatch_phone: '5552220000' },
  }, { auth: true });
  assert.equal(body.tier, 'strong');
  assert.equal(body.pro_id, withLicense.pro_id);
});
```

- [ ] **Step 2: Run — expect FAIL**

- [ ] **Step 3: Implement `wordpress-plugins/includes/frp-match.php`**

```php
<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Score applicant against existing restoration_pro posts.
 * Return highest-confidence hit.
 */
function frp_match_applicant( array $a ) : array {
    // ── Strong-tier lookups (unique identifiers) ──────────────────────
    foreach ( [ 'license_number', 'google_place_id', 'yelp_id' ] as $key ) {
        $val = trim( (string) ( $a[ $key ] ?? '' ) );
        if ( $val === '' ) continue;
        $hit = frp_find_pro_by_meta( $key, $val );
        if ( $hit ) {
            return [ 'tier' => 'strong', 'pro_id' => $hit, 'reason' => "exact {$key} match", 'signals' => [ $key => $val ] ];
        }
    }

    // ── Medium-tier lookups ───────────────────────────────────────────
    $phone_norm = frp_normalize_phone( $a['dispatch_phone'] ?? '' );
    $name_norm  = frp_normalize_name( $a['business_name'] ?? '' );
    if ( $phone_norm ) {
        foreach ( frp_find_pros_by_meta( 'phone', $phone_norm, 'normalized' ) as $pid ) {
            $candidate_name = frp_normalize_name( get_the_title( $pid ) );
            if ( frp_name_similarity( $name_norm, $candidate_name ) >= 0.85 ) {
                return [ 'tier' => 'medium', 'pro_id' => $pid, 'reason' => 'phone + fuzzy name', 'signals' => [ 'phone' => $phone_norm ] ];
            }
        }
    }

    $domain = frp_extract_domain( $a['website'] ?? '' );
    $state  = strtoupper( trim( (string) ( $a['state'] ?? '' ) ) );
    if ( $domain && $state ) {
        foreach ( frp_find_pros_by_domain( $domain ) as $pid ) {
            if ( strtoupper( (string) get_post_meta( $pid, 'state', true ) ) === $state ) {
                return [ 'tier' => 'medium', 'pro_id' => $pid, 'reason' => 'website domain + state', 'signals' => [ 'domain' => $domain, 'state' => $state ] ];
            }
        }
    }

    // ── Weak-tier lookups (name-only fuzzy) ───────────────────────────
    if ( $name_norm ) {
        foreach ( frp_find_pros_by_name_prefix( $name_norm ) as $pid ) {
            $sim = frp_name_similarity( $name_norm, frp_normalize_name( get_the_title( $pid ) ) );
            if ( $sim >= 0.90 ) {
                return [ 'tier' => 'weak', 'pro_id' => $pid, 'reason' => 'fuzzy name only', 'signals' => [ 'name_sim' => $sim ] ];
            }
        }
    }

    return [ 'tier' => 'none', 'pro_id' => 0, 'reason' => 'no signals matched', 'signals' => [] ];
}

function frp_normalize_phone( string $raw ) : string {
    $digits = preg_replace( '/\D+/', '', $raw );
    // Strip US country code
    if ( strlen( $digits ) === 11 && $digits[0] === '1' ) $digits = substr( $digits, 1 );
    return strlen( $digits ) === 10 ? $digits : '';
}

function frp_normalize_name( string $raw ) : string {
    $n = strtolower( trim( $raw ) );
    // Strip legal suffixes + articles ONLY. Do NOT strip "restoration", "pros",
    // or "professionals" — in a restoration directory those are the
    // discriminating words ("Acme Restoration" vs "Acme Plumbing" must not
    // collapse to identical tokens).
    $n = preg_replace( '/\b(inc|llc|llp|corp|corporation|co|company|ltd|limited|the)\b\.?/i', '', $n );
    // Strip punctuation
    $n = preg_replace( '/[^a-z0-9 ]/', ' ', $n );
    return trim( preg_replace( '/\s+/', ' ', $n ) );
}

function frp_name_similarity( string $a, string $b ) : float {
    if ( $a === '' || $b === '' ) return 0.0;
    similar_text( $a, $b, $pct );
    return $pct / 100.0;
}

function frp_extract_domain( string $url ) : string {
    if ( ! $url ) return '';
    $host = parse_url( $url, PHP_URL_HOST );
    if ( ! $host ) $host = parse_url( 'http://' . ltrim( $url, '/' ), PHP_URL_HOST );
    return $host ? strtolower( preg_replace( '/^www\./', '', $host ) ) : '';
}

function frp_find_pro_by_meta( string $key, string $value ) : int {
    $q = new WP_Query( [
        'post_type' => 'restoration_pro', 'posts_per_page' => 1, 'fields' => 'ids',
        'meta_query' => [[ 'key' => $key, 'value' => $value, 'compare' => '=' ]],
    ] );
    return $q->posts[0] ?? 0;
}

function frp_find_pros_by_meta( string $key, string $value, string $variant = 'raw' ) : array {
    global $wpdb;

    // Phone matching: compare digits-only on both sides.
    // $value has already been normalized to exactly 10 digits by frp_normalize_phone.
    // We do NOT use LIKE '%digits%' — that would match 11-digit international numbers
    // that happen to contain the 10-digit substring.
    if ( $key === 'phone' && $variant === 'normalized' ) {
        // REGEXP_REPLACE requires MySQL 8.0.4+ / MariaDB 10.0.5+.
        // FRP runs on SiteGround (MySQL 8 since 2023); if stack downgrades, swap
        // to an in-PHP pass: SELECT all phone meta rows, normalize in PHP, filter.
        $rows = $wpdb->get_col( $wpdb->prepare(
            "SELECT post_id FROM {$wpdb->postmeta}
             WHERE meta_key = 'phone'
               AND REGEXP_REPLACE(meta_value, '[^0-9]', '') = %s",
            $value
        ) );
        // Fallback for pre-8.0 MySQL — detected by a prior probe at bootstrap:
        if ( $wpdb->last_error && str_contains( $wpdb->last_error, 'REGEXP_REPLACE' ) ) {
            $wpdb->last_error = '';
            $all = $wpdb->get_results(
                "SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = 'phone'"
            );
            $rows = [];
            foreach ( $all as $row ) {
                if ( frp_normalize_phone( $row->meta_value ) === $value ) {
                    $rows[] = $row->post_id;
                }
            }
        }
        return array_map( 'intval', $rows );
    }

    $q = new WP_Query( [
        'post_type' => 'restoration_pro', 'posts_per_page' => 20, 'fields' => 'ids',
        'meta_query' => [[ 'key' => $key, 'value' => $value ]],
    ] );
    return $q->posts;
}

function frp_find_pros_by_domain( string $domain ) : array {
    global $wpdb;
    $rows = $wpdb->get_col( $wpdb->prepare(
        "SELECT post_id FROM {$wpdb->postmeta}
         WHERE meta_key = 'website' AND meta_value LIKE %s",
        '%' . $wpdb->esc_like( $domain ) . '%'
    ) );
    return array_map( 'intval', $rows );
}

function frp_find_pros_by_name_prefix( string $name_norm ) : array {
    if ( strlen( $name_norm ) < 4 ) return [];
    global $wpdb;
    $prefix = substr( $name_norm, 0, 4 );
    $rows = $wpdb->get_col( $wpdb->prepare(
        "SELECT ID FROM {$wpdb->posts}
         WHERE post_type = 'restoration_pro' AND post_status IN ('publish','draft')
           AND LOWER(post_title) LIKE %s LIMIT 50",
        $wpdb->esc_like( $prefix ) . '%'
    ) );
    return array_map( 'intval', $rows );
}
```

Also: register admin-only diagnostic route `/frp/v1/admin/match-test` (calls `frp_match_applicant` with an arbitrary body, returns the result — used only by tests).

- [ ] **Step 4: Run — expect PASS (7/7)**

- [ ] **Step 5: Commit**

```bash
git add wordpress-plugins/includes/frp-match.php wordpress-plugins/frp-directory.php tests/pro-match.test.mjs
git commit -m "feat(frp): applicant-to-seeded-pro matcher with tiered confidence"
```

---

#### Task 1.3.5b: Route `/apply` through the matcher

**Files:**
- Modify: `wordpress-plugins/frp-directory.php` (`frp_apply_handler`)
- Modify: `wordpress-plugins/frp-emails.php` (add `frp_claim_requested` handler)
- Test: `tests/apply-claim.test.mjs`

- [ ] **Step 1: Write failing tests**

```javascript
test('applying for a strongly-matched seeded pro does NOT create a duplicate', async () => {
  const seeded = await seedPro({ license_number: 'CSLB-999', contact_email: 'owner@biz.test' });
  const countBefore = await countPros();
  const { body } = await frpPost('/wp-json/frp/v1/apply', {
    business_name: 'Biz', contact_email: 'applicant@somewhere.test',
    dispatch_phone: '5551234567', state: 'CA', license_number: 'CSLB-999',
    cf_turnstile_response: 'always-pass',
  });
  const countAfter = await countPros();
  assert.equal(body.status, 'claim_sent');
  assert.equal(body.match_tier, 'strong');
  assert.equal(body.pro_id, seeded.pro_id);
  assert.equal(countAfter - countBefore, 0); // NO new pro
});

test('claim email goes to on-file email, not applicant email', async () => {
  await seedPro({ license_number: 'CSLB-998', contact_email: 'onfile@biz.test' });
  await frpPost('/wp-json/frp/v1/apply', {
    business_name: 'Biz', contact_email: 'stranger@evil.test',
    dispatch_phone: '5551234567', state: 'CA', license_number: 'CSLB-998',
    cf_turnstile_response: 'always-pass',
  });
  const mail = await readLastEmail();
  assert.equal(mail.to, 'onfile@biz.test');
  assert.notEqual(mail.to, 'stranger@evil.test');
});

test('weak match creates an admin review ticket, no email to applicant', async () => {
  await seedPro({ business_name: 'Similar Name Co' });
  const { body } = await frpPost('/wp-json/frp/v1/apply', {
    business_name: 'Similar Name Co', contact_email: 'x@x.test',
    dispatch_phone: '5559998888', state: 'TX', cf_turnstile_response: 'always-pass',
  });
  assert.equal(body.status, 'pending_manual_review');
  const tickets = await frpGet('/wp-json/wp/v2/frp_claim_review', { auth: true });
  assert.ok(tickets.body.length > 0);
});

test('no match → falls through to existing insert behavior', async () => {
  const countBefore = await countPros();
  const { body } = await frpPost('/wp-json/frp/v1/apply', {
    business_name: 'Brand New LLC Never Seen', contact_email: 'new@new.test',
    dispatch_phone: '5550001111', state: 'NV', cf_turnstile_response: 'always-pass',
  });
  const countAfter = await countPros();
  assert.equal(body.status, 'pending_review'); // original response
  assert.equal(countAfter - countBefore, 1);
});

test('seeded pro with no on-file email → weak match fallback (admin review)', async () => {
  await seedPro({ license_number: 'CSLB-777', contact_email: '' });
  const { body } = await frpPost('/wp-json/frp/v1/apply', {
    business_name: 'X', contact_email: 'a@a.test', dispatch_phone: '5554443333',
    state: 'CA', license_number: 'CSLB-777', cf_turnstile_response: 'always-pass',
  });
  assert.equal(body.status, 'pending_manual_review');
  assert.match(body.reason, /no on-file email/i);
});
```

- [ ] **Step 2: Run — expect FAIL**

- [ ] **Step 3: Extract Task 1.3's validation + insert blocks into reusable helpers**

Task 1.3 wrote validation + `wp_insert_post` + meta-setting inline inside `frp_apply_handler`. Before the rewrite, pull those two blocks into pure functions so this task can call them without duplicating logic:

- `frp_apply_validate( WP_REST_Request $r ) : array|WP_Error` — moves the entire body-parameter sanitization + required-field check from Task 1.3's handler. Returns a validated applicant array (with normalized email/phone/state, sanitized business_name, etc.) or a `WP_Error` on failure. **Do not change behavior** — this is a pure cut-and-paste refactor with no new validation.
- `frp_apply_insert_new( array $a ) : array|WP_Error` — moves Task 1.3's `wp_insert_post(...)` call plus every `update_post_meta` line plus the response shape (`[ 'pro_id' => ..., 'status' => 'pending_review' ]`). Sets `joined_source` to `apply_new`.

After this refactor runs, `frp_apply_handler` from Task 1.3 (before 1.3.5) should be functionally equivalent — verify by running Task 1.3's test file (`tests/apply.test.mjs`) and expecting PASS unchanged.

Commit this refactor on its own:

```bash
git add wordpress-plugins/frp-directory.php
git commit -m "refactor(frp): extract frp_apply_validate + frp_apply_insert_new from /apply handler"
```

- [ ] **Step 4: Rewrite `frp_apply_handler` to branch on match result**

```php
function frp_apply_handler( WP_REST_Request $request ) {
    if ( ! frp_verify_turnstile( $request ) ) return new WP_Error( 'forbidden', 'Bot check failed', [ 'status' => 403 ] );
    if ( ! frp_check_rate_limit( 'apply', 2, HOUR_IN_SECONDS ) ) {
        return new WP_Error( 'rate_limited', 'Too many applications; try later.', [ 'status' => 429 ] );
    }

    // Uses frp_apply_validate() extracted in the refactor step above.
    $applicant = frp_apply_validate( $request );
    if ( is_wp_error( $applicant ) ) return $applicant;

    // NEW: match against existing pros
    $match = frp_match_applicant( $applicant );

    if ( $match['tier'] === 'strong' ) {
        return frp_apply_initiate_claim( $applicant, $match, /*need_admin_ticket=*/ false );
    }
    if ( $match['tier'] === 'medium' ) {
        return frp_apply_initiate_claim( $applicant, $match, /*need_admin_ticket=*/ true );
    }
    if ( $match['tier'] === 'weak' ) {
        $ticket = frp_apply_open_review_ticket( $applicant, $match );
        return [ 'status' => 'pending_manual_review', 'ticket_id' => $ticket, 'reason' => $match['reason'] ];
    }
    // 'none' → fall through to original insert path
    return frp_apply_insert_new( $applicant );
}

function frp_apply_initiate_claim( array $applicant, array $match, bool $need_admin_ticket ) {
    $pro_id = $match['pro_id'];
    $onfile_email = (string) get_post_meta( $pro_id, 'contact_email', true );

    if ( ! $onfile_email || ! is_email( $onfile_email ) ) {
        // No on-file email → can't verify without human. Open ticket instead.
        $ticket = frp_apply_open_review_ticket( $applicant, $match + [ 'fallback_reason' => 'no on-file email' ] );
        return [ 'status' => 'pending_manual_review', 'ticket_id' => $ticket, 'reason' => 'no on-file email; admin will reach out' ];
    }

    // Generate claim token. frp_generate_lead_token() is a pure return-only
    // helper (frp-directory.php:449 — verified: it does not persist meta).
    // It returns [ 'token' => bin2hex(random_bytes(16)), 'expiry' => ISO-1hr ].
    // We override expiry to 72 hours for the claim flow.
    $tok = frp_generate_lead_token();
    $claim_expiry = gmdate( 'c', time() + 72 * HOUR_IN_SECONDS );

    update_post_meta( $pro_id, 'claim_status', 'claim_pending' );
    update_post_meta( $pro_id, 'claim_token', $tok['token'] );
    update_post_meta( $pro_id, 'claim_token_expiry', $claim_expiry );
    update_post_meta( $pro_id, 'claim_applicant_email', $applicant['contact_email'] );
    update_post_meta( $pro_id, 'claim_applicant_phone', $applicant['dispatch_phone'] );

    if ( $need_admin_ticket ) {
        frp_apply_open_review_ticket( $applicant, $match + [ 'safety_net' => true ] );
    }

    do_action( 'frp_claim_requested', $pro_id, $applicant );

    return [
        'status' => 'claim_sent',
        'pro_id' => $pro_id,
        'match_tier' => $match['tier'],
        'message' => 'We already list this business. A verification email was sent to the address we have on file.',
    ];
}

function frp_apply_open_review_ticket( array $applicant, array $match ) : int {
    $ticket_id = wp_insert_post( [
        'post_type'   => 'frp_claim_review',
        'post_status' => 'publish',
        'post_title'  => 'Review: ' . $applicant['business_name'],
    ] );
    update_post_meta( $ticket_id, 'match_tier',      $match['tier'] );
    update_post_meta( $ticket_id, 'candidate_pro_id', $match['pro_id'] );
    update_post_meta( $ticket_id, 'reason',          $match['reason'] );
    update_post_meta( $ticket_id, 'applicant_json', wp_json_encode( $applicant ) );
    update_post_meta( $ticket_id, 'review_status',  'open' );
    do_action( 'frp_claim_review_opened', $ticket_id );
    return $ticket_id;
}

// frp_apply_insert_new() was extracted from Task 1.3 in the refactor step
// above. It takes the validated applicant array and performs the original
// wp_insert_post(...) + meta-writing + response-shape logic verbatim. The
// only behavior change from Task 1.3: set joined_source = 'apply_new'.
```

Register `frp_claim_review` CPT (private, admin-only, like `frp_lead`). Register its meta fields.

In `frp-emails.php` add:
```php
add_action( 'frp_claim_requested', 'frp_email_claim_requested', 10, 2 );
function frp_email_claim_requested( $pro_id, $applicant ) {
    $onfile = (string) get_post_meta( $pro_id, 'contact_email', true );
    $token  = (string) get_post_meta( $pro_id, 'claim_token', true );
    $url    = home_url( "/claim/?pro={$pro_id}&token={$token}" );
    // Use home_url(), NOT $_SERVER['HTTP_HOST'] — this action may fire from
    // WP-Cron or CLI contexts where HTTP_HOST is missing or spoofed.
    $host   = parse_url( home_url(), PHP_URL_HOST );
    wp_mail( $onfile, "Someone requested to claim your {$host} listing",
        frp_email_tpl( 'claim-request', [
            'business'      => get_the_title( $pro_id ),
            'applicant_email' => $applicant['contact_email'],
            'applicant_phone' => $applicant['dispatch_phone'],
            'claim_url'     => $url,
        ] ),
        [ 'Content-Type: text/html; charset=UTF-8' ] );
}
```

Create the `claim-request.html` template under `wordpress-plugins/templates/emails/`.

- [ ] **Step 5: Run — expect PASS (all tests from Step 1 + Task 1.3's original tests still pass)**

- [ ] **Step 6: Commit**

```bash
git add wordpress-plugins/frp-directory.php wordpress-plugins/frp-emails.php wordpress-plugins/templates/emails/claim-request.html tests/apply-claim.test.mjs
git commit -m "feat(frp): /apply routes through matcher — claim, review, or insert"
```

---

#### Task 1.3.5c: `/frp/v1/claim/{pro_id}` verify endpoint

**Files:**
- Modify: `wordpress-plugins/frp-directory.php` (new route + handler)
- Test: `tests/claim-verify.test.mjs`

**Design:** GET with `?token=xyz` renders a WP page (shortcode `[frp_claim_verify]`) that shows the pro profile + a confirm button. POST binds the user. If the clicker is not logged in, the flow creates a new WP user bound to the on-file email (same as Task 1.2 binding), mails a temporary password, and bounds them.

- [ ] **Step 1: Write failing tests**

```javascript
test('valid token + confirm → pro is claimed, user bound', async () => {
  const seeded = await seedPro({ contact_email: 'owner@biz.test', license_number: 'CSLB-C1' });
  await frpPost('/wp-json/frp/v1/apply', { /* triggers claim */ license_number: 'CSLB-C1', business_name:'B', contact_email:'x@x.test', dispatch_phone:'5551234567', state:'CA', cf_turnstile_response: 'always-pass' });
  const token = await readMetaRaw(seeded.pro_id, 'claim_token');

  const { status, body } = await frpPost(`/wp-json/frp/v1/claim/${seeded.pro_id}`, { token, confirm: true });
  assert.equal(status, 200);
  assert.equal(body.claimed, true);
  assert.ok(body.user_id);

  assert.equal(await readMeta(seeded.pro_id, 'claim_status'), 'claimed');
  assert.equal(await readMeta(seeded.pro_id, 'joined_source'), 'apply_claim');
  assert.equal(Number(await readMeta(seeded.pro_id, 'claimed_by_user')), body.user_id);
});

test('expired token → 403', async () => {
  const seeded = await seedPro({});
  await setPostMeta(seeded.pro_id, 'claim_token', 'abc');
  await setPostMeta(seeded.pro_id, 'claim_token_expiry', '2020-01-01');
  const { status } = await frpPost(`/wp-json/frp/v1/claim/${seeded.pro_id}`,
    { token: 'abc', confirm: true });
  assert.equal(status, 403);
});

test('wrong token → 403', async () => {
  const seeded = await seedPro({});
  await setPostMeta(seeded.pro_id, 'claim_token', 'correct');
  await setPostMeta(seeded.pro_id, 'claim_token_expiry', new Date(Date.now()+3600*1000).toISOString());
  const { status } = await frpPost(`/wp-json/frp/v1/claim/${seeded.pro_id}`,
    { token: 'wrong', confirm: true });
  assert.equal(status, 403);
});

test('second claimer after success → 409 conflict', async () => {
  // First claim succeeds; second with same token (pre-use) finds claim_status=claimed
  const seeded = await seedPro({ contact_email: 'x@x.test' });
  await setPostMeta(seeded.pro_id, 'claim_token', 't1');
  await setPostMeta(seeded.pro_id, 'claim_token_expiry', new Date(Date.now()+3600*1000).toISOString());
  await setPostMeta(seeded.pro_id, 'claim_status', 'claimed');

  const { status } = await frpPost(`/wp-json/frp/v1/claim/${seeded.pro_id}`,
    { token: 't1', confirm: true });
  assert.equal(status, 409);
});

test('preview (confirm=false) returns business name without claiming', async () => {
  const seeded = await seedPro({ business_name: 'Acme Restoration', contact_email: 'owner@biz.test' });
  await setPostMeta(seeded.pro_id, 'claim_token', 'preview-token');
  await setPostMeta(seeded.pro_id, 'claim_token_expiry', new Date(Date.now()+3600*1000).toISOString());
  await setPostMeta(seeded.pro_id, 'claim_status', 'claim_pending');

  const { status, body } = await frpPost(`/wp-json/frp/v1/claim/${seeded.pro_id}`,
    { token: 'preview-token', confirm: false });
  assert.equal(status, 200);
  assert.equal(body.preview, true);
  assert.equal(body.business, 'Acme Restoration');
  // claim_status must still be claim_pending (preview must not mutate state)
  assert.equal(await readMeta(seeded.pro_id, 'claim_status'), 'claim_pending');
});

test('enumeration-hardening: unknown pro_id returns 403 (not 409/404 leaking status)', async () => {
  // Without a token, we must not reveal whether a listing exists or is claimed.
  const seeded = await seedPro({});
  await setPostMeta(seeded.pro_id, 'claim_status', 'claimed');
  const { status } = await frpPost(`/wp-json/frp/v1/claim/${seeded.pro_id}`, { confirm: true });
  assert.equal(status, 403); // token missing → forbidden, regardless of claim_status
});

test('applicant meta cleared after successful claim', async () => {
  const seeded = await seedPro({ contact_email: 'owner@biz.test' });
  await setPostMeta(seeded.pro_id, 'claim_token', 'tokA');
  await setPostMeta(seeded.pro_id, 'claim_token_expiry', new Date(Date.now()+3600*1000).toISOString());
  await setPostMeta(seeded.pro_id, 'claim_applicant_email', 'stranger@x.test');
  await setPostMeta(seeded.pro_id, 'claim_applicant_phone', '5559990000');

  await frpPost(`/wp-json/frp/v1/claim/${seeded.pro_id}`, { token: 'tokA', confirm: true });
  assert.equal(await readMetaRaw(seeded.pro_id, 'claim_applicant_email'), '');
  assert.equal(await readMetaRaw(seeded.pro_id, 'claim_applicant_phone'), '');
});
```

- [ ] **Step 2: Run — FAIL**

- [ ] **Step 3: Implement**

```php
register_rest_route( 'frp/v1', '/claim/(?P<id>\d+)', [
    'methods' => 'POST', 'callback' => 'frp_claim_verify_handler',
    'permission_callback' => '__return_true',
] );

function frp_claim_verify_handler( WP_REST_Request $r ) {
    $pro_id = (int) $r['id'];
    if ( get_post_type( $pro_id ) !== 'restoration_pro' ) {
        // Return 403 (not 404) — do not leak whether pro_id exists.
        return new WP_Error( 'forbidden', 'Invalid token', [ 'status' => 403 ] );
    }
    if ( ! frp_check_rate_limit( 'claim_verify_' . $pro_id, 5, HOUR_IN_SECONDS ) ) {
        return new WP_Error( 'rate_limited', 'Too many attempts', [ 'status' => 429 ] );
    }

    // ── Token check FIRST (before status check). Reason: checking claim_status
    // before token would let an attacker enumerate which pro_ids are claimed
    // by watching for 409 vs 403 response codes without ever knowing a token.
    $token  = (string) $r->get_param( 'token' );
    $stored = (string) get_post_meta( $pro_id, 'claim_token', true );
    $expiry = (string) get_post_meta( $pro_id, 'claim_token_expiry', true );
    if ( ! $token || ! $stored || ! hash_equals( $stored, $token ) ) {
        return new WP_Error( 'forbidden', 'Invalid token', [ 'status' => 403 ] );
    }
    if ( ! $expiry || strtotime( $expiry ) < time() ) {
        return new WP_Error( 'forbidden', 'Token expired', [ 'status' => 403 ] );
    }

    // Now that token is verified, it's safe to surface claim_status.
    $status = (string) get_post_meta( $pro_id, 'claim_status', true );
    if ( $status === 'claimed' ) {
        return new WP_Error( 'conflict', 'Listing is already claimed.', [ 'status' => 409 ] );
    }

    $confirm = (bool) $r->get_param( 'confirm' );
    if ( ! $confirm ) {
        return [ 'preview' => true, 'business' => get_the_title( $pro_id ) ];
    }

    // Bind: if the requester is logged in and has the restoration_pro role, reuse their user.
    // Otherwise create/upgrade a WP user from the on-file email.
    $uid = get_current_user_id();
    if ( ! $uid ) {
        $email = (string) get_post_meta( $pro_id, 'contact_email', true );
        $user  = get_user_by( 'email', $email );
        if ( ! $user ) {
            $pass = wp_generate_password( 24 );
            $uid = wp_insert_user( [
                'user_login' => sanitize_user( substr( explode( '@', $email )[0], 0, 60 ) . '-' . wp_rand( 100, 999 ) ),
                'user_pass'  => $pass,
                'user_email' => $email,
                'role'       => 'restoration_pro',
            ] );
            if ( is_wp_error( $uid ) ) return $uid;
            wp_new_user_notification( $uid, null, 'both' ); // mail the temp password
        } else {
            $uid = $user->ID;
            $user->set_role( 'restoration_pro' );
        }
    }

    update_user_meta( $uid, 'frp_pro_id', $pro_id );
    update_post_meta( $pro_id, 'claim_status', 'claimed' );
    update_post_meta( $pro_id, 'claimed_by_user', $uid );
    update_post_meta( $pro_id, 'date_claimed', gmdate( 'c' ) );
    update_post_meta( $pro_id, 'joined_source', 'apply_claim' );
    // Wipe all transient claim-flow meta so admin listings don't show stale
    // applicant data on a claimed record.
    delete_post_meta( $pro_id, 'claim_token' );
    delete_post_meta( $pro_id, 'claim_token_expiry' );
    delete_post_meta( $pro_id, 'claim_applicant_email' );
    delete_post_meta( $pro_id, 'claim_applicant_phone' );

    do_action( 'frp_pro_claimed', $pro_id, $uid );

    return [ 'claimed' => true, 'pro_id' => $pro_id, 'user_id' => $uid ];
}
```

**`/claim/` shortcode page spec.** Create a WP page slug `claim` containing `[frp_claim_verify]`. The shortcode:

1. Reads `?pro=<int>&token=<hex>` from the querystring.
2. Calls `POST /frp/v1/claim/{pro}` with `{ token, confirm: false }` via `admin-ajax.php`-style fetch.
3. If 403/429/409 — renders the corresponding error message (expired / rate-limited / already claimed) with a support link. No confirm button.
4. If 200 preview — renders:
   - Business name + city (from `preview.business` + a supplementary fetch of the public pro REST route).
   - Heading: "Is this your business?"
   - A "Yes, claim this listing" button that POSTs with `{ token, confirm: true }`.
   - A "This isn't mine" link that opens a `mailto:` to support.
5. On confirm success: redirect to `/contractor/dashboard/` (Task 1.8) with a flash notice.

The shortcode code lives in `wordpress-plugins/includes/frp-claim-shortcode.php`.

- [ ] **Step 4: PASS → commit**

```bash
git add wordpress-plugins/frp-directory.php tests/claim-verify.test.mjs
git commit -m "feat(frp): /claim/{pro_id} verify endpoint with conflict + expiry handling"
```

---

#### Task 1.3.5d: Admin claim-review queue

**Files:**
- Modify: `wordpress-plugins/frp-directory.php` (register `frp_claim_review` CPT, register admin REST routes, admin list columns)
- Modify: `tests/helpers/staging.mjs` (add `createReviewTicket` fixture)
- Test: `tests/claim-review-admin.test.mjs`

- [ ] **Step 1: Add `createReviewTicket` test helper**

In `tests/helpers/staging.mjs` (where `seedPro`, `readMeta`, `setPostMeta` already live from Task 1.1), add:

```javascript
/**
 * Insert an frp_claim_review ticket directly via the staging REST bridge
 * (bypasses the /apply flow so tests can isolate the admin endpoints).
 */
export async function createReviewTicket(fields = {}) {
  const body = {
    match_tier:       fields.match_tier       ?? 'weak',
    candidate_pro_id: fields.candidate_pro_id ?? 0,
    reason:           fields.reason           ?? 'test fixture',
    applicant_json:   fields.applicant_json   ?? '{}',
    review_status:    'open',
  };
  const { body: res } = await frpPost('/wp-json/frp/v1/test/fixture/claim-review', body, { auth: true });
  return res.ticket_id;
}
```

The `/frp/v1/test/fixture/claim-review` route is already registered by the staging-only fixture bridge from Task 1.1 (see `tests/helpers/staging.mjs` bootstrap). Extend that bridge to accept `post_type=frp_claim_review` — one-line change.

- [ ] **Step 2: Write failing tests**

```javascript
test('admin can approve a weak-match ticket → triggers claim email', async () => {
  const seeded = await seedPro({ contact_email: 'owner@biz.test' });
  const ticket = await createReviewTicket({
    candidate_pro_id: seeded.pro_id,
    match_tier: 'weak',
    applicant_json: JSON.stringify({ contact_email: 'applicant@x.test', dispatch_phone: '5551234567' }),
  });
  const { status } = await frpPost(`/wp-json/frp/v1/admin/claim-review/${ticket}/approve`, {}, { auth: true });
  assert.equal(status, 200);
  assert.equal(await readMeta(seeded.pro_id, 'claim_status'), 'claim_pending');
});

test('admin can reject a ticket → creates new insert instead', async () => {
  const ticket = await createReviewTicket({
    match_tier: 'weak',
    applicant_json: JSON.stringify({ business_name: 'New Co', contact_email: 'new@x.test', dispatch_phone: '5559990000', state: 'CA' }),
  });
  const countBefore = await countPros();
  const { status } = await frpPost(`/wp-json/frp/v1/admin/claim-review/${ticket}/reject`, {}, { auth: true });
  assert.equal(status, 200);
  assert.equal(await countPros() - countBefore, 1);
});

test('non-admin cannot hit approve/reject — 403', async () => {
  const ticket = await createReviewTicket({});
  // auth: false = no Basic Auth header, or send a non-admin app-password
  const approve = await frpPost(`/wp-json/frp/v1/admin/claim-review/${ticket}/approve`, {}, { auth: 'subscriber' });
  assert.equal(approve.status, 403);
  const reject  = await frpPost(`/wp-json/frp/v1/admin/claim-review/${ticket}/reject`,  {}, { auth: 'subscriber' });
  assert.equal(reject.status, 403);
});

test('resolving a ticket marks it resolved + records admin + timestamp', async () => {
  const ticket = await createReviewTicket({ applicant_json: JSON.stringify({ business_name: 'X', contact_email: 'x@x.test', dispatch_phone: '5551112222', state: 'CA' }) });
  await frpPost(`/wp-json/frp/v1/admin/claim-review/${ticket}/reject`, {}, { auth: true });
  assert.equal(await readMeta(ticket, 'review_status'), 'resolved');
  assert.ok(await readMeta(ticket, 'resolved_by'));
  assert.ok(await readMeta(ticket, 'resolved_at'));
});
```

- [ ] **Step 3: Run — FAIL**

- [ ] **Step 4: Implement CPT + admin endpoints**

```php
// Register the CPT (add to the existing CPT registration block near line ~90 of frp-directory.php):
register_post_type( 'frp_claim_review', [
    'public'              => false,
    'show_ui'             => true,
    'show_in_menu'        => 'edit.php?post_type=restoration_pro', // nest under pros
    'show_in_rest'        => false,
    'capability_type'     => 'post',
    'capabilities'        => [ 'create_posts' => 'do_not_allow' ], // only programmatic inserts
    'map_meta_cap'        => true,
    'supports'            => [ 'title' ],
    'labels'              => [ 'name' => 'Claim reviews', 'singular_name' => 'Claim review' ],
] );

// Admin REST routes:
register_rest_route( 'frp/v1', '/admin/claim-review/(?P<id>\d+)/approve', [
    'methods'  => 'POST',
    'callback' => 'frp_claim_review_approve_handler',
    'permission_callback' => function () { return current_user_can( 'manage_options' ); },
] );
register_rest_route( 'frp/v1', '/admin/claim-review/(?P<id>\d+)/reject', [
    'methods'  => 'POST',
    'callback' => 'frp_claim_review_reject_handler',
    'permission_callback' => function () { return current_user_can( 'manage_options' ); },
] );

function frp_claim_review_approve_handler( WP_REST_Request $r ) {
    $tid = (int) $r['id'];
    if ( get_post_type( $tid ) !== 'frp_claim_review' ) {
        return new WP_Error( 'not_found', 'Ticket not found', [ 'status' => 404 ] );
    }
    if ( get_post_meta( $tid, 'review_status', true ) === 'resolved' ) {
        return new WP_Error( 'conflict', 'Already resolved', [ 'status' => 409 ] );
    }
    $applicant = json_decode( (string) get_post_meta( $tid, 'applicant_json', true ), true );
    if ( ! is_array( $applicant ) ) return new WP_Error( 'bad_data', 'Malformed applicant', [ 'status' => 500 ] );

    $pro_id    = (int) get_post_meta( $tid, 'candidate_pro_id', true );
    $match     = [ 'tier' => (string) get_post_meta( $tid, 'match_tier', true ), 'pro_id' => $pro_id, 'reason' => 'admin-approved' ];
    $result    = frp_apply_initiate_claim( $applicant, $match, false );

    frp_claim_review_mark_resolved( $tid, 'approved' );
    return [ 'ticket_id' => $tid, 'outcome' => 'approved', 'claim' => $result ];
}

function frp_claim_review_reject_handler( WP_REST_Request $r ) {
    $tid = (int) $r['id'];
    if ( get_post_type( $tid ) !== 'frp_claim_review' ) {
        return new WP_Error( 'not_found', 'Ticket not found', [ 'status' => 404 ] );
    }
    if ( get_post_meta( $tid, 'review_status', true ) === 'resolved' ) {
        return new WP_Error( 'conflict', 'Already resolved', [ 'status' => 409 ] );
    }
    $applicant = json_decode( (string) get_post_meta( $tid, 'applicant_json', true ), true );
    if ( ! is_array( $applicant ) ) return new WP_Error( 'bad_data', 'Malformed applicant', [ 'status' => 500 ] );

    $result = frp_apply_insert_new( $applicant );
    frp_claim_review_mark_resolved( $tid, 'rejected' );
    return [ 'ticket_id' => $tid, 'outcome' => 'rejected', 'insert' => $result ];
}

function frp_claim_review_mark_resolved( int $tid, string $outcome ) : void {
    update_post_meta( $tid, 'review_status', 'resolved' );
    update_post_meta( $tid, 'review_outcome', $outcome );
    update_post_meta( $tid, 'resolved_by',   get_current_user_id() );
    update_post_meta( $tid, 'resolved_at',   gmdate( 'c' ) );
}

// Admin list columns:
add_filter( 'manage_frp_claim_review_posts_columns', function ( $cols ) {
    return [
        'cb'           => $cols['cb'] ?? '',
        'title'        => 'Applicant business',
        'match_tier'   => 'Tier',
        'candidate'    => 'Candidate pro',
        'reason'       => 'Reason',
        'review_status'=> 'Status',
        'date'         => 'Submitted',
    ];
} );
add_action( 'manage_frp_claim_review_posts_custom_column', function ( $col, $post_id ) {
    switch ( $col ) {
        case 'match_tier':   echo esc_html( get_post_meta( $post_id, 'match_tier', true ) ); break;
        case 'candidate':
            $pid = (int) get_post_meta( $post_id, 'candidate_pro_id', true );
            echo $pid ? sprintf( '<a href="%s">%s</a>', esc_url( get_edit_post_link( $pid ) ), esc_html( get_the_title( $pid ) ) ) : '—';
            break;
        case 'reason':        echo esc_html( get_post_meta( $post_id, 'reason', true ) ); break;
        case 'review_status': echo esc_html( get_post_meta( $post_id, 'review_status', true ) ?: 'open' ); break;
    }
}, 10, 2 );
```

- [ ] **Step 5: Run — expect PASS (4/4)**

- [ ] **Step 6: Commit**

```bash
git add wordpress-plugins/frp-directory.php tests/helpers/staging.mjs tests/claim-review-admin.test.mjs
git commit -m "feat(frp): admin claim-review queue with approve/reject actions"
```

---

#### Task 1.3.5e: Backfill `claim_status=unclaimed` on existing seeded pros

**Files:**
- Create: `wordpress-plugins/includes/frp-migrations.php`
- Modify: `wordpress-plugins/frp-directory.php` (load the include, run migration on `admin_init` once)
- Test: `tests/claim-backfill.test.mjs`

**Why:** All subtasks above assume `claim_status` meta exists on seeded pros. Without a backfill, the first real applicant who matches a seeded pro that was created before 1.3.5 shipped would hit a meta-read returning `''`, which happens to behave correctly everywhere *except* the admin list column (which would show an empty status). Explicit `unclaimed` makes the data self-describing for admins and future queries.

- [ ] **Step 1: Write failing test**

```javascript
test('backfill sets claim_status=unclaimed on seeded pros that have no claim_status meta', async () => {
  // Insert a pro directly via fixture bridge, do NOT set claim_status.
  const pro = await seedPro({ business_name: 'Legacy Co' });
  await deleteMeta(pro.pro_id, 'claim_status'); // simulate pre-1.3.5 state
  await frpPost('/wp-json/frp/v1/admin/run-migration', { name: 'claim_status_backfill_v1' }, { auth: true });
  assert.equal(await readMeta(pro.pro_id, 'claim_status'), 'unclaimed');
});

test('backfill is idempotent — running twice does not overwrite claimed pros', async () => {
  const pro = await seedPro({});
  await setPostMeta(pro.pro_id, 'claim_status', 'claimed');
  await frpPost('/wp-json/frp/v1/admin/run-migration', { name: 'claim_status_backfill_v1' }, { auth: true });
  await frpPost('/wp-json/frp/v1/admin/run-migration', { name: 'claim_status_backfill_v1' }, { auth: true });
  assert.equal(await readMeta(pro.pro_id, 'claim_status'), 'claimed');
});
```

- [ ] **Step 2: Run — FAIL**

- [ ] **Step 3: Implement migration**

```php
// wordpress-plugins/includes/frp-migrations.php
if ( ! defined( 'ABSPATH' ) ) exit;

add_action( 'admin_init', function () {
    if ( get_option( 'frp_migration_claim_status_backfill_v1' ) === 'done' ) return;
    frp_migration_claim_status_backfill_v1();
    update_option( 'frp_migration_claim_status_backfill_v1', 'done' );
} );

function frp_migration_claim_status_backfill_v1() : int {
    global $wpdb;
    // Only touch pros that have NO claim_status meta row at all.
    $rows = $wpdb->get_col( "
        SELECT p.ID FROM {$wpdb->posts} p
        LEFT JOIN {$wpdb->postmeta} m
          ON m.post_id = p.ID AND m.meta_key = 'claim_status'
        WHERE p.post_type = 'restoration_pro'
          AND m.meta_id IS NULL
    " );
    foreach ( $rows as $id ) {
        add_post_meta( (int) $id, 'claim_status', 'unclaimed', true );
    }
    return count( $rows );
}

// Admin-only REST hook for the test suite to invoke migrations on demand:
add_action( 'rest_api_init', function () {
    register_rest_route( 'frp/v1', '/admin/run-migration', [
        'methods'  => 'POST',
        'callback' => function ( $r ) {
            $name = (string) $r->get_param( 'name' );
            $fn   = 'frp_migration_' . preg_replace( '/[^a-z0-9_]/', '', $name );
            if ( ! function_exists( $fn ) ) return new WP_Error( 'unknown', 'Unknown migration', [ 'status' => 404 ] );
            return [ 'migration' => $name, 'affected' => $fn() ];
        },
        'permission_callback' => function () { return current_user_can( 'manage_options' ); },
    ] );
} );
```

- [ ] **Step 4: PASS → commit**

```bash
git add wordpress-plugins/includes/frp-migrations.php wordpress-plugins/frp-directory.php tests/claim-backfill.test.mjs
git commit -m "feat(frp): migration to backfill claim_status=unclaimed on seeded pros"
```

---

### Task 1.4.0: Guarantee `lead_update_token` is set on every fresh lead

**Files:**
- Modify: `wordpress-plugins/frp-directory.php` (`frp_lead_create_handler`)
- Test: `tests/lead-create-token.test.mjs` (new)

**Why:** `frp_generate_lead_token()` exists (line 449) and is called on the *duplicate* branch (around line 784), but Route B (Task 1.4) is useless if fresh leads never get a token. Ensure both branches (new + duplicate) set `lead_update_token` + `lead_update_token_expiry`.

- [ ] **Step 1: Write failing test**

```javascript
test('freshly created lead has token and expiry meta', async () => {
  const { body } = await frpPost('/wp-json/frp/v1/leads', { /* valid */ cf_turnstile_response: 'always-pass' });
  const { body: lead } = await frpGet(`/wp-json/frp/v1/admin/debug/lead-raw/${body.lead_id}`, { auth: true });
  assert.match(lead.lead_update_token, /^[a-f0-9]{32}$/);
  assert.ok(Date.parse(lead.lead_update_token_expiry) > Date.now());
});
```

- [ ] **Step 2: Run — expect FAIL if fresh-create path skips token**

- [ ] **Step 3: In `frp_lead_create_handler`, right after `wp_insert_post`, write token meta unconditionally**

```php
$tok = frp_generate_lead_token();
update_post_meta( $lead_id, 'lead_update_token', $tok['token'] );
update_post_meta( $lead_id, 'lead_update_token_expiry', $tok['expiry'] );
```

- [ ] **Step 4: PASS → commit**

```bash
git add wordpress-plugins/frp-directory.php tests/lead-create-token.test.mjs
git commit -m "fix(frp): guarantee update token on fresh lead creation"
```

### Task 1.4: Implement Route B — `/frp/v1/leads/{id}` (lead update)

**Files:**
- Modify: `wordpress-plugins/frp-directory.php` (complete `frp_lead_update_handler`)
- Test: `tests/lead-update.test.mjs` (new)

**Design:** Homeowner clicks a magic link in their confirmation email (`/leads/{id}?token=xyz`). Token must match `lead_update_token` meta and not be expired. Allowed updates: `status=cancelled` (homeowner got help elsewhere) or `add_notes` (free-text). No phone/email change (identity risk). Rate-limited per lead to prevent enumeration.

- [ ] **Step 1: Write failing tests**

```javascript
import test from 'node:test';
import assert from 'node:assert/strict';
import { frpPost } from './helpers/wp-client.mjs';
import { createTestLead } from './helpers/staging.mjs';

test('valid token cancels the lead', async () => {
  const { lead_id, token } = await createTestLead();
  const { status, body } = await frpPost(`/wp-json/frp/v1/leads/${lead_id}`,
    { token, action: 'cancel' });
  assert.equal(status, 200);
  assert.equal(body.status, 'cancelled');
});

test('wrong token returns 403', async () => {
  const { lead_id } = await createTestLead();
  const { status } = await frpPost(`/wp-json/frp/v1/leads/${lead_id}`,
    { token: 'nope', action: 'cancel' });
  assert.equal(status, 403);
});

test('expired token returns 403', async () => {
  const { lead_id, token } = await createTestLead({ tokenExpired: true });
  const { status } = await frpPost(`/wp-json/frp/v1/leads/${lead_id}`,
    { token, action: 'cancel' });
  assert.equal(status, 403);
});
```

- [ ] **Step 2: Run — expect FAIL**

- [ ] **Step 3: Complete `frp_lead_update_handler` in `frp-directory.php`**

```php
function frp_lead_update_handler( WP_REST_Request $request ) {
    $lead_id = (int) $request['id'];
    if ( ! $lead_id || get_post_type( $lead_id ) !== 'frp_lead' ) {
        return new WP_Error( 'not_found', 'Lead not found.', [ 'status' => 404 ] );
    }

    // Per-lead rate limit: 10 attempts per hour
    if ( ! frp_check_rate_limit( 'lead_update_' . $lead_id, 10, HOUR_IN_SECONDS ) ) {
        return new WP_Error( 'rate_limited', 'Too many attempts.', [ 'status' => 429 ] );
    }

    $token  = (string) $request->get_param( 'token' );
    $stored = (string) get_post_meta( $lead_id, 'lead_update_token', true );
    $expiry = (string) get_post_meta( $lead_id, 'lead_update_token_expiry', true );

    if ( ! $token || ! $stored || ! hash_equals( $stored, $token ) ) {
        return new WP_Error( 'forbidden', 'Invalid token.', [ 'status' => 403 ] );
    }
    if ( ! $expiry || strtotime( $expiry ) < time() ) {
        return new WP_Error( 'forbidden', 'Token expired.', [ 'status' => 403 ] );
    }

    $action = sanitize_text_field( $request->get_param( 'action' ) ?? '' );
    $valid_actions = [ 'cancel', 'add_notes' ];
    if ( ! in_array( $action, $valid_actions, true ) ) {
        return new WP_Error( 'bad_request', 'Invalid action.', [ 'status' => 400 ] );
    }

    if ( $action === 'cancel' ) {
        update_post_meta( $lead_id, 'lead_status', 'cancelled' );
        do_action( 'frp_lead_cancelled', $lead_id ); // notify dispatched pros
        return [ 'status' => 'cancelled', 'lead_id' => $lead_id ];
    }

    if ( $action === 'add_notes' ) {
        $notes = sanitize_textarea_field( $request->get_param( 'notes' ) ?? '' );
        if ( strlen( $notes ) > 1000 ) {
            return new WP_Error( 'bad_request', 'Notes too long.', [ 'status' => 400 ] );
        }
        $existing = (string) get_post_meta( $lead_id, 'lead_customer_notes', true );
        update_post_meta( $lead_id, 'lead_customer_notes', trim( $existing . "\n---\n" . $notes ) );
        return [ 'status' => 'notes_added', 'lead_id' => $lead_id ];
    }

    return new WP_Error( 'server_error', 'Unreachable.', [ 'status' => 500 ] );
}
```

Also: register `lead_customer_notes` in the frp_lead meta list near lines 97–103.

- [ ] **Step 4: Deploy; run tests — expect PASS (3/3)**

- [ ] **Step 5: Commit**

```bash
git add wordpress-plugins/frp-directory.php tests/lead-update.test.mjs
git commit -m "feat(frp): implement Route B /leads/{id} update handler"
```

### Task 1.5: Stripe — new `frp-billing` plugin skeleton + product catalog

**Files:**
- Create: `wordpress-plugins/frp-billing.php`
- Create: `docs/superpowers/runbooks/stripe-product-setup.md`
- Test: `tests/billing-catalog.test.mjs` (new)

**Design:** New separate plugin. Three subscription tiers: `basic` (pro directory listing, no priority dispatch) = $49/mo; `paid` (priority dispatch, 50mi radius, email leads) = $199/mo; `featured` (all of paid + tagline + YouTube embed + Tier-1 dispatch weight) = $499/mo. Runbook describes creating these in Stripe dashboard and recording their `price_id` values into WP options `frp_stripe_price_basic` / `frp_stripe_price_paid` / `frp_stripe_price_featured`.

- [ ] **Step 1: Write the runbook**

`docs/superpowers/runbooks/stripe-product-setup.md` — step-by-step: (a) Create Stripe account if new; (b) Products → Add product "FRP Basic Listing" / "Paid Listing" / "Featured Listing"; (c) monthly recurring prices; (d) copy each `price_id` (starts with `price_`); (e) in WP admin → Settings → FRP Billing, paste each price_id; (f) set webhook endpoint to `https://findrestorationpros.com/wp-json/frp/v1/stripe/webhook` and copy the signing secret into `FRP_STRIPE_WEBHOOK_SECRET` env.

- [ ] **Step 2: Write failing test**

```javascript
import test from 'node:test';
import assert from 'node:assert/strict';
import { frpGet } from './helpers/wp-client.mjs';

test('billing catalog endpoint returns the three tiers', async () => {
  const { status, body } = await frpGet('/wp-json/frp/v1/billing/catalog');
  assert.equal(status, 200);
  assert.equal(body.tiers.length, 3);
  const ids = body.tiers.map(t => t.id).sort();
  assert.deepEqual(ids, ['basic', 'featured', 'paid']);
});
```

- [ ] **Step 3: Run — expect FAIL**

- [ ] **Step 4: Implement skeleton plugin**

Create `wordpress-plugins/frp-billing.php`:
```php
<?php
/**
 * Plugin Name: FRP Billing
 * Description: Stripe Checkout + subscription mapping for restoration_pro listings
 * Version: 0.1.0
 * Requires Plugins: frp-directory
 */
if ( ! defined( 'ABSPATH' ) ) exit;

// Load Stripe SDK (via Composer — see composer.json in next task)
require_once __DIR__ . '/vendor/autoload.php';

const FRP_TIERS = [
    'basic'    => [ 'label' => 'Basic Listing',    'option' => 'frp_stripe_price_basic' ],
    'paid'     => [ 'label' => 'Paid Listing',     'option' => 'frp_stripe_price_paid' ],
    'featured' => [ 'label' => 'Featured Listing', 'option' => 'frp_stripe_price_featured' ],
];

add_action( 'rest_api_init', function() {
    register_rest_route( 'frp/v1', '/billing/catalog', [
        'methods'             => 'GET',
        'callback'            => 'frp_billing_catalog',
        'permission_callback' => '__return_true',
    ] );
} );

function frp_billing_catalog() {
    $out = [];
    foreach ( FRP_TIERS as $id => $meta ) {
        $out[] = [
            'id'    => $id,
            'label' => $meta['label'],
            'price_configured' => (bool) get_option( $meta['option'] ),
        ];
    }
    return [ 'tiers' => $out ];
}

// Admin settings page — minimal; lets ops paste the three price_ids
add_action( 'admin_menu', function() {
    add_options_page( 'FRP Billing', 'FRP Billing', 'manage_options', 'frp-billing', 'frp_billing_settings_page' );
} );
add_action( 'admin_init', function() {
    foreach ( FRP_TIERS as $meta ) {
        register_setting( 'frp_billing', $meta['option'], [ 'sanitize_callback' => 'sanitize_text_field' ] );
    }
} );
function frp_billing_settings_page() {
    ?>
    <div class="wrap">
      <h1>FRP Billing — Stripe Price IDs</h1>
      <form method="post" action="options.php">
        <?php settings_fields( 'frp_billing' ); ?>
        <table class="form-table">
          <?php foreach ( FRP_TIERS as $id => $meta ): ?>
            <tr><th><?php echo esc_html( $meta['label'] ); ?></th>
                <td><input type="text" name="<?php echo esc_attr( $meta['option'] ); ?>"
                       value="<?php echo esc_attr( get_option( $meta['option'] ) ); ?>"
                       class="regular-text" placeholder="price_..."></td></tr>
          <?php endforeach; ?>
        </table>
        <?php submit_button(); ?>
      </form>
    </div>
    <?php
}
```

Create a `wordpress-plugins/composer.json` with `stripe/stripe-php` pinned, run `composer install` in deployment runbook.

- [ ] **Step 5: Deploy; run — expect PASS**

- [ ] **Step 6: Commit**

```bash
git add wordpress-plugins/frp-billing.php wordpress-plugins/composer.json docs/superpowers/runbooks/stripe-product-setup.md tests/billing-catalog.test.mjs
git commit -m "feat(frp): add frp-billing plugin skeleton with tier catalog"
```

### Task 1.6: Stripe Checkout session creation endpoint

**Files:**
- Modify: `wordpress-plugins/frp-billing.php` (add `/billing/checkout`)
- Test: `tests/billing-checkout.test.mjs` (new)

**Design:** Authenticated endpoint, requires current user has `restoration_pro` role and a bound `frp_pro_id`. Body: `{ tier: "paid" }`. Returns `{ checkout_url }` — the Stripe-hosted session URL. Success URL = `/contractor/dashboard?billing=success&session_id={CHECKOUT_SESSION_ID}`. Cancel URL = `/contractor/dashboard?billing=cancelled`.

- [ ] **Step 1: Write failing test**

```javascript
import test from 'node:test';
import assert from 'node:assert/strict';
import { frpPost } from './helpers/wp-client.mjs';
import { loginAsPro } from './helpers/staging.mjs';

test('authenticated pro can create a checkout session', async () => {
  const cookie = await loginAsPro('testpro1');
  const { status, body } = await frpPost('/wp-json/frp/v1/billing/checkout',
    { tier: 'paid' },
    { headers: { Cookie: cookie } });
  assert.equal(status, 200);
  assert.match(body.checkout_url, /^https:\/\/checkout\.stripe\.com\//);
});

test('unauthenticated checkout returns 401', async () => {
  const { status } = await frpPost('/wp-json/frp/v1/billing/checkout', { tier: 'paid' });
  assert.equal(status, 401);
});
```

- [ ] **Step 2: Run — expect FAIL**

- [ ] **Step 3: Implement `frp_billing_checkout`**

```php
add_action( 'rest_api_init', function() {
    register_rest_route( 'frp/v1', '/billing/checkout', [
        'methods'             => 'POST',
        'callback'            => 'frp_billing_checkout',
        'permission_callback' => function() {
            return is_user_logged_in() && in_array( 'restoration_pro', (array) wp_get_current_user()->roles, true );
        },
    ] );
} );

function frp_billing_checkout( WP_REST_Request $r ) {
    $tier = (string) $r->get_param( 'tier' );
    if ( ! isset( FRP_TIERS[ $tier ] ) ) {
        return new WP_Error( 'bad_request', 'Unknown tier.', [ 'status' => 400 ] );
    }
    $price_id = (string) get_option( FRP_TIERS[ $tier ]['option'] );
    if ( ! $price_id ) {
        return new WP_Error( 'not_configured', 'Tier price not configured.', [ 'status' => 500 ] );
    }
    if ( ! defined( 'FRP_STRIPE_SECRET_KEY' ) ) {
        return new WP_Error( 'not_configured', 'Stripe secret not configured.', [ 'status' => 500 ] );
    }

    $pro_id = frp_current_pro_id();
    if ( ! $pro_id ) {
        return new WP_Error( 'no_pro', 'User is not bound to a pro.', [ 'status' => 403 ] );
    }

    \Stripe\Stripe::setApiKey( FRP_STRIPE_SECRET_KEY );

    $customer_id = (string) get_user_meta( get_current_user_id(), 'frp_stripe_customer_id', true );
    if ( ! $customer_id ) {
        $customer = \Stripe\Customer::create( [
            'email'    => wp_get_current_user()->user_email,
            'metadata' => [ 'frp_pro_id' => (string) $pro_id, 'wp_user_id' => (string) get_current_user_id() ],
        ] );
        $customer_id = $customer->id;
        update_user_meta( get_current_user_id(), 'frp_stripe_customer_id', $customer_id );
    }

    $base = home_url();
    $session = \Stripe\Checkout\Session::create( [
        'mode'         => 'subscription',
        'customer'     => $customer_id,
        'line_items'   => [ [ 'price' => $price_id, 'quantity' => 1 ] ],
        'success_url'  => $base . '/contractor/dashboard/?billing=success&session_id={CHECKOUT_SESSION_ID}',
        'cancel_url'   => $base . '/contractor/dashboard/?billing=cancelled',
        'metadata'     => [ 'frp_pro_id' => (string) $pro_id, 'tier' => $tier ],
        'subscription_data' => [
            'metadata' => [ 'frp_pro_id' => (string) $pro_id, 'tier' => $tier ],
        ],
    ] );

    return [ 'checkout_url' => $session->url, 'session_id' => $session->id ];
}
```

- [ ] **Step 4: Deploy (with `FRP_STRIPE_SECRET_KEY` set to a test-mode `sk_test_...`); run tests — expect PASS**

- [ ] **Step 5: Commit**

```bash
git add wordpress-plugins/frp-billing.php tests/billing-checkout.test.mjs
git commit -m "feat(frp): Stripe Checkout session creation for pros"
```

### Task 1.7: Stripe webhook — update pro tier on subscription events

**Files:**
- Modify: `wordpress-plugins/frp-billing.php`
- Test: `tests/billing-webhook.test.mjs` (new)

**Design:** Webhook at `/frp/v1/stripe/webhook`. Validates signature via `\Stripe\Webhook::constructEvent`. Handles: `checkout.session.completed` (initial activation → set `listing_status=active`, `listing_tier=<tier from metadata>`, `is_paid_listing=1` if tier≠basic, `listing_expires=<subscription.current_period_end>`), `invoice.payment_succeeded` (renewal → extend `listing_expires`), `customer.subscription.deleted` (cancellation → `listing_status=expired`, `is_paid_listing=0`), `customer.subscription.updated` (plan change → new tier).

- [ ] **Step 1: Write failing tests using Stripe CLI replay or signed fixtures**

Use the Stripe CLI to trigger test events against staging:
```bash
stripe listen --forward-to staging.findrestorationpros.com/wp-json/frp/v1/stripe/webhook
stripe trigger checkout.session.completed
```
Test asserts that post-event, the pro has `listing_status=active`. Alternatively, the test can construct a signed request body manually using `FRP_STRIPE_WEBHOOK_SECRET`.

```javascript
import test from 'node:test';
import assert from 'node:assert/strict';
import crypto from 'node:crypto';
import { frpPost, frpGet } from './helpers/wp-client.mjs';
import { createTestPro } from './helpers/staging.mjs';

function signStripe(payload, secret) {
  const timestamp = Math.floor(Date.now() / 1000);
  const signed = `${timestamp}.${payload}`;
  const sig = crypto.createHmac('sha256', secret).update(signed).digest('hex');
  return `t=${timestamp},v1=${sig}`;
}

test('checkout.session.completed activates pro to paid tier', async () => {
  const pro = await createTestPro();
  const payload = JSON.stringify({
    id: 'evt_test_1',
    type: 'checkout.session.completed',
    data: { object: {
      id: 'cs_test_1',
      metadata: { frp_pro_id: String(pro.pro_id), tier: 'paid' },
      subscription: 'sub_test_1',
      customer: 'cus_test_1',
    } },
  });
  const { status } = await frpPost('/wp-json/frp/v1/stripe/webhook', JSON.parse(payload), {
    headers: { 'Stripe-Signature': signStripe(payload, process.env.FRP_STRIPE_WEBHOOK_SECRET) },
  });
  assert.equal(status, 200);

  const proAfter = await frpGet(`/wp-json/wp/v2/restoration_pro/${pro.pro_id}`, { auth: true });
  assert.equal(proAfter.body.meta.listing_tier, 'paid');
  assert.equal(proAfter.body.meta.listing_status, 'active');
  assert.equal(proAfter.body.meta.is_paid_listing, 1);
});
```

- [ ] **Step 2: Run — expect FAIL**

- [ ] **Step 3a: Implement webhook skeleton — signature check, idempotency guard, event dispatch**

```php
add_action( 'rest_api_init', function() {
    register_rest_route( 'frp/v1', '/stripe/webhook', [
        'methods'             => 'POST',
        'callback'            => 'frp_stripe_webhook',
        'permission_callback' => '__return_true', // signature is our auth
    ] );
} );

function frp_stripe_webhook( WP_REST_Request $r ) {
    if ( ! defined( 'FRP_STRIPE_WEBHOOK_SECRET' ) || ! defined( 'FRP_STRIPE_SECRET_KEY' ) ) {
        return new WP_Error( 'not_configured', 'Stripe not configured', [ 'status' => 500 ] );
    }
    $payload = $r->get_body();
    $sig     = $r->get_header( 'stripe_signature' );

    try {
        $event = \Stripe\Webhook::constructEvent( $payload, $sig, FRP_STRIPE_WEBHOOK_SECRET );
    } catch ( \Throwable $e ) {
        return new WP_Error( 'bad_signature', 'Invalid signature', [ 'status' => 400 ] );
    }

    // Idempotency — Stripe retries on 5xx; same event.id can fire many times.
    $seen_key = 'frp_stripe_evt_' . $event->id;
    if ( get_transient( $seen_key ) ) {
        return [ 'received' => true, 'deduped' => true ];
    }
    set_transient( $seen_key, 1, 7 * DAY_IN_SECONDS );

    \Stripe\Stripe::setApiKey( FRP_STRIPE_SECRET_KEY );

    switch ( $event->type ) {
        case 'checkout.session.completed':
            frp_billing_activate_from_session( $event->data->object );
            break;
        case 'invoice.payment_succeeded':
            frp_billing_extend_from_invoice( $event->data->object );
            break;
        case 'customer.subscription.updated':
            frp_billing_sync_subscription( $event->data->object );
            break;
        case 'customer.subscription.deleted':
            frp_billing_deactivate_subscription( $event->data->object );
            break;
    }
    return [ 'received' => true ];
}
```

Commit `feat(frp): Stripe webhook skeleton with signature + idempotency`.

- [ ] **Step 3b: Implement `checkout.session.completed` handler**

```php
function frp_billing_activate_from_session( $session ) {
    $pro_id = (int) ( $session->metadata->frp_pro_id ?? 0 );
    $tier   = (string) ( $session->metadata->tier ?? 'basic' );
    if ( ! $pro_id ) return;

    // The API sometimes inlines the subscription as an object; sometimes returns the ID.
    $sub = is_object( $session->subscription )
        ? $session->subscription
        : \Stripe\Subscription::retrieve( (string) $session->subscription );

    update_post_meta( $pro_id, 'listing_status', 'active' );
    update_post_meta( $pro_id, 'listing_tier', $tier );
    update_post_meta( $pro_id, 'is_paid_listing', $tier === 'basic' ? 0 : 1 );
    update_post_meta( $pro_id, 'listing_expires', gmdate( 'c', $sub->current_period_end ) );
    update_post_meta( $pro_id, 'frp_stripe_subscription_id', $sub->id );
    update_post_meta( $pro_id, 'frp_stripe_customer_id', is_object( $sub->customer ) ? $sub->customer->id : $sub->customer );

    do_action( 'frp_subscription_activated', $pro_id, $tier ); // hooks in frp-emails.php (Task 1.10)
}
```

Test: one failing, one passing, commit.

- [ ] **Step 3c: Implement `invoice.payment_succeeded` handler**

```php
function frp_billing_extend_from_invoice( $invoice ) {
    if ( empty( $invoice->subscription ) ) return;
    $sub_id = is_object( $invoice->subscription ) ? $invoice->subscription->id : (string) $invoice->subscription;
    $sub = \Stripe\Subscription::retrieve( $sub_id );
    $pro_id = frp_pro_id_for_subscription( $sub->id );
    if ( ! $pro_id ) return;
    update_post_meta( $pro_id, 'listing_expires', gmdate( 'c', $sub->current_period_end ) );
}
```

Test + commit.

- [ ] **Step 3d: Implement `customer.subscription.updated` and `.deleted` handlers**

```php
function frp_billing_sync_subscription( $sub ) {
    $pro_id = frp_pro_id_for_subscription( $sub->id );
    if ( ! $pro_id ) return;
    $price = $sub->items->data[0]->price->id ?? '';
    $tier  = frp_tier_for_price( $price );
    if ( $tier ) {
        update_post_meta( $pro_id, 'listing_tier', $tier );
        update_post_meta( $pro_id, 'is_paid_listing', $tier === 'basic' ? 0 : 1 );
    }
    update_post_meta( $pro_id, 'listing_expires', gmdate( 'c', $sub->current_period_end ) );
}

function frp_billing_deactivate_subscription( $sub ) {
    $pro_id = frp_pro_id_for_subscription( $sub->id );
    if ( ! $pro_id ) return;
    update_post_meta( $pro_id, 'listing_status', 'expired' );
    update_post_meta( $pro_id, 'is_paid_listing', 0 );
    do_action( 'frp_subscription_deactivated', $pro_id );
}

function frp_pro_id_for_subscription( $sub_id ) {
    $q = new WP_Query( [
        'post_type'  => 'restoration_pro',
        'meta_key'   => 'frp_stripe_subscription_id',
        'meta_value' => $sub_id,
        'fields'     => 'ids',
        'posts_per_page' => 1,
    ] );
    return $q->posts[0] ?? 0;
}

function frp_tier_for_price( $price_id ) {
    foreach ( FRP_TIERS as $id => $meta ) {
        if ( get_option( $meta['option'] ) === $price_id ) return $id;
    }
    return '';
}
```

Test + commit.

Also: register new meta keys `frp_stripe_subscription_id`, `frp_stripe_customer_id` on `restoration_pro` CPT (private, `show_in_rest=false`) near lines 147–162 of `frp-directory.php`.

- [ ] **Step 4: Deploy; run all webhook tests — expect PASS**

- [ ] **Step 5: Final commit of consolidated fixes**

```bash
git add wordpress-plugins/frp-billing.php wordpress-plugins/frp-directory.php tests/billing-webhook.test.mjs
git commit -m "feat(frp): Stripe webhook — subscription lifecycle → pro tier/status/expiry"
```

### Task 1.8: Contractor dashboard — auth gate + lead list shortcode

**Files:**
- Create: `wordpress-plugins/frp-dashboard.php`
- Create: `stitch-html/frp-contractor-dashboard.html` (template reference)
- Test: `tests/dashboard.test.mjs` (new)

**Design:** Shortcode `[frp_contractor_dashboard]` renders the dashboard. If user not logged in → render login form + link to password reset. If logged in but not `restoration_pro` → render a "request access" message. If logged in + pro → render three tabs: Leads / Profile / Billing. Pros see only leads where `lead_assigned_pros` contains their `frp_pro_id`.

- [ ] **Step 1: Write failing test (HTTP fetch of `/contractor/dashboard/` with cookie)**

```javascript
import test from 'node:test';
import assert from 'node:assert/strict';
import { loginAsPro, createTestLead, assignLeadToPro } from './helpers/staging.mjs';

test('logged-in pro sees their leads, not others', async () => {
  const { cookie, pro_id } = await loginAsPro('testpro1');
  const myLead = await createTestLead({ assignTo: pro_id });
  const otherLead = await createTestLead({ assignTo: 99999 });

  const html = await fetch('https://staging.findrestorationpros.com/contractor/dashboard/', {
    headers: { Cookie: cookie },
  }).then(r => r.text());

  assert.ok(html.includes(`data-lead-id="${myLead.lead_id}"`));
  assert.ok(!html.includes(`data-lead-id="${otherLead.lead_id}"`));
});
```

- [ ] **Step 2: Run — expect FAIL (shortcode doesn't exist)**

- [ ] **Step 3: Implement `frp-dashboard.php`**

```php
<?php
/**
 * Plugin Name: FRP Contractor Dashboard
 * Description: Authenticated contractor self-service (leads, profile, billing)
 * Version: 0.1.0
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
    ?>
    <div class="frp-dashboard">
      <nav class="frp-dash-tabs">
        <a href="#leads" class="active">Leads</a>
        <a href="#profile">Profile</a>
        <a href="#billing">Billing</a>
      </nav>
      <section id="leads"><?php echo frp_dashboard_leads_html( $pro_id ); ?></section>
      <section id="profile"><?php echo frp_dashboard_profile_html( $pro_id ); ?></section>
      <section id="billing"><?php echo frp_dashboard_billing_html( $pro_id ); ?></section>
    </div>
    <?php
    return ob_get_clean();
}

function frp_dashboard_leads_html( $pro_id ) {
    $q = new WP_Query( [
        'post_type'      => 'frp_lead',
        'posts_per_page' => 50,
        'post_status'    => 'publish',
        'meta_query'     => [[
            'key'     => 'lead_assigned_pros',
            'value'   => (string) $pro_id,
            'compare' => 'LIKE',
        ]],
        'orderby'        => 'date',
        'order'          => 'DESC',
    ] );
    if ( ! $q->have_posts() ) return '<p>No leads yet. Hang tight.</p>';
    $out = '<ul class="frp-lead-list">';
    foreach ( $q->posts as $lead ) {
        $id    = $lead->ID;
        $city  = esc_html( get_post_meta( $id, 'lead_city', true ) );
        $svc   = esc_html( get_post_meta( $id, 'lead_service', true ) );
        $urg   = esc_html( get_post_meta( $id, 'lead_urgency', true ) );
        $score = (int) get_post_meta( $id, 'lead_score', true );
        $phone = esc_html( get_post_meta( $id, 'lead_phone', true ) );
        $out .= "<li data-lead-id='{$id}'><strong>{$svc}</strong> · {$city} · urgency:{$urg} · score:{$score} · <a href='tel:{$phone}'>{$phone}</a></li>";
    }
    $out .= '</ul>';
    return $out;
}

function frp_dashboard_profile_html( $pro_id ) {
    // Simple read-only for v1; edit form in Task 1.10
    $pro = get_post( $pro_id );
    return '<h3>' . esc_html( $pro->post_title ) . '</h3>' .
           '<p>Listing tier: ' . esc_html( get_post_meta( $pro_id, 'listing_tier', true ) ?: 'free' ) . '</p>';
}

function frp_dashboard_billing_html( $pro_id ) {
    $cust = get_post_meta( $pro_id, 'frp_stripe_customer_id', true );
    if ( ! $cust ) {
        return '<p>No subscription. <a href="#" onclick="frpStartCheckout(\'paid\')">Start Paid Listing</a></p>';
    }
    // Stripe Billing Portal link created server-side via a small REST endpoint (Task 1.9)
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
```

Ops also creates a WordPress page at `/contractor/dashboard/` with `[frp_contractor_dashboard]` as its content.

- [ ] **Step 4: Deploy; run test — expect PASS**

- [ ] **Step 5: Commit**

```bash
git add wordpress-plugins/frp-dashboard.php stitch-html/frp-contractor-dashboard.html tests/dashboard.test.mjs
git commit -m "feat(frp): contractor dashboard shortcode (leads/profile/billing)"
```

### Task 1.9: Stripe Billing Portal link + pro profile edit

**Files:**
- Modify: `wordpress-plugins/frp-billing.php` (add `/billing/portal`)
- Modify: `wordpress-plugins/frp-dashboard.php` (add profile edit form + POST handler)
- Test: `tests/dashboard-profile.test.mjs` (new)

- [ ] **Step 1: Write failing tests for portal redirect and profile save**

```javascript
test('GET /billing/portal redirects to Stripe billing portal', async () => {
  const cookie = await loginAsPro('testpro1');
  const res = await fetch(`${BASE}/wp-json/frp/v1/billing/portal`, {
    headers: { Cookie: cookie }, redirect: 'manual',
  });
  assert.equal(res.status, 302);
  assert.match(res.headers.get('location'), /billing\.stripe\.com/);
});

test('pro can save their profile description', async () => {
  const cookie = await loginAsPro('testpro1');
  const { status } = await frpPost('/wp-json/frp/v1/me/profile',
    { description: 'IICRC-certified water damage specialists since 2012.' },
    { headers: { Cookie: cookie } });
  assert.equal(status, 200);
});
```

- [ ] **Step 2: Implement `/billing/portal` and `/me/profile`**

```php
// billing portal
register_rest_route( 'frp/v1', '/billing/portal', [
    'methods' => 'GET',
    'callback' => function() {
        $pro_id = frp_current_pro_id();
        $cust = (string) get_post_meta( $pro_id, 'frp_stripe_customer_id', true );
        if ( ! $cust ) { wp_safe_redirect( home_url( '/contractor/dashboard/' ) ); exit; }
        \Stripe\Stripe::setApiKey( FRP_STRIPE_SECRET_KEY );
        $portal = \Stripe\BillingPortal\Session::create( [
            'customer'    => $cust,
            'return_url'  => home_url( '/contractor/dashboard/' ),
        ] );
        wp_redirect( $portal->url, 303 );
        exit;
    },
    'permission_callback' => function() { return is_user_logged_in() && frp_current_pro_id(); },
] );

// profile editor — whitelisted fields only
register_rest_route( 'frp/v1', '/me/profile', [
    'methods' => 'POST',
    'callback' => 'frp_me_profile_handler',
    'permission_callback' => function() { return is_user_logged_in() && frp_current_pro_id(); },
] );

function frp_me_profile_handler( WP_REST_Request $r ) {
    $pro_id = frp_current_pro_id();
    $editable = [ 'description', 'website', 'youtube_url', 'featured_tagline', 'response_time' ];
    foreach ( $editable as $field ) {
        $val = $r->get_param( $field );
        if ( $val !== null ) {
            update_post_meta( $pro_id, $field, sanitize_text_field( (string) $val ) );
        }
    }
    return [ 'ok' => true ];
}
```

Note: `featured_tagline` and `youtube_url` should only save if `listing_tier === 'featured'` — enforce in handler.

- [ ] **Step 3: Run tests — expect PASS**

- [ ] **Step 4: Commit**

```bash
git add wordpress-plugins/frp-billing.php wordpress-plugins/frp-dashboard.php tests/dashboard-profile.test.mjs
git commit -m "feat(frp): billing portal link + pro profile editor"
```

### Task 1.10: Transactional email templates

**Files:**
- Create: `wordpress-plugins/frp-emails.php`
- Test: `tests/emails.test.mjs` (new)

**Design:** Single plugin hooks into `frp_application_submitted`, `frp_lead_created`, `frp_lead_dispatched`, `frp_lead_cancelled`, `frp_subscription_activated`. Uses `wp_mail` for applicant/admin notifications. Make.com still handles pro SMS dispatch (that channel already works). These are WP-generated HTML emails: plain text + minimal inline CSS, 600px wide.

- [ ] **Step 1: Write failing test**

```javascript
test('applying to /apply triggers admin notification email', async () => {
  const before = await countTestEmails();
  await frpPost('/wp-json/frp/v1/apply', { /* valid body */ });
  await new Promise(r => setTimeout(r, 1000)); // wait for action
  const after = await countTestEmails();
  assert.equal(after - before, 2); // admin + applicant
});
```

Test depends on staging WP using a mail-capture service (e.g., MailHog, or a `wp_mail` filter that writes to a file). Document in the runbook.

- [ ] **Step 2: Implement `frp-emails.php`**

```php
<?php
/**
 * Plugin Name: FRP Transactional Emails
 */
if ( ! defined( 'ABSPATH' ) ) exit;

add_action( 'frp_application_submitted', 'frp_email_application', 10, 1 );
function frp_email_application( $pro_id ) {
    $pro   = get_post( $pro_id );
    $email = (string) get_post_meta( $pro_id, 'contact_email', true );
    $admin = get_option( 'admin_email' );

    // To applicant
    wp_mail( $email, 'We got your FRP application',
        frp_email_tpl( 'application-applicant', [ 'business' => $pro->post_title ] ),
        [ 'Content-Type: text/html; charset=UTF-8' ] );

    // To admin
    wp_mail( $admin, "[FRP] New application: {$pro->post_title}",
        frp_email_tpl( 'application-admin', [ 'pro_id' => $pro_id, 'edit_url' => admin_url( "post.php?post={$pro_id}&action=edit" ) ] ),
        [ 'Content-Type: text/html; charset=UTF-8' ] );
}

add_action( 'frp_lead_created', 'frp_email_lead_created', 10, 1 );
function frp_email_lead_created( $lead_id ) {
    $email = (string) get_post_meta( $lead_id, 'lead_email', true );
    if ( ! $email ) return;
    $token = (string) get_post_meta( $lead_id, 'lead_update_token', true );
    $cancel_url = home_url( "/lead-status/?id={$lead_id}&token={$token}" );
    wp_mail( $email, 'Your restoration request',
        frp_email_tpl( 'lead-homeowner', [ 'lead_id' => $lead_id, 'cancel_url' => $cancel_url ] ),
        [ 'Content-Type: text/html; charset=UTF-8' ] );
}

add_action( 'frp_subscription_activated', 'frp_email_sub_activated', 10, 2 );
function frp_email_sub_activated( $pro_id, $tier ) {
    $email = (string) get_post_meta( $pro_id, 'contact_email', true );
    wp_mail( $email, 'Your FRP listing is live',
        frp_email_tpl( 'subscription-active', [ 'pro_id' => $pro_id, 'tier' => $tier ] ),
        [ 'Content-Type: text/html; charset=UTF-8' ] );
}

function frp_email_tpl( $name, $vars = [] ) {
    $path = __DIR__ . "/templates/emails/{$name}.html";
    $html = file_exists( $path ) ? file_get_contents( $path ) : '<p>{{body}}</p>';
    foreach ( $vars as $k => $v ) {
        $html = str_replace( '{{' . $k . '}}', esc_html( (string) $v ), $html );
    }
    return $html;
}
```

Create the five template files under `wordpress-plugins/templates/emails/`: `application-applicant.html`, `application-admin.html`, `lead-homeowner.html`, `subscription-active.html`, `lead-cancelled.html`.

Also: fire `do_action( 'frp_lead_created', $lead_id )` inside `frp_lead_create_handler` in `frp-directory.php` right before it returns the success response. Fire `do_action( 'frp_subscription_activated', $pro_id, $tier )` inside `frp_billing_activate_from_session`.

- [ ] **Step 3: Run tests — expect PASS**

- [ ] **Step 4: Commit**

```bash
git add wordpress-plugins/frp-emails.php wordpress-plugins/templates/ tests/emails.test.mjs
git commit -m "feat(frp): transactional emails for applications, leads, subscriptions"
```

### Task 1.11: Daily listing-expiry sweep (WP-Cron)

**Files:**
- Modify: `wordpress-plugins/frp-billing.php` (register `frp_expiry_sweep` cron event + handler)
- Test: `tests/expiry-sweep.test.mjs`

**Why:** Stripe webhooks can be missed (downtime, signing secret rotation lag, Stripe outage). Without a self-healing sweep, a lapsed pro with `listing_expires < now()` stays `active` forever and gets free dispatch. This is a 10-minute safety net.

- [ ] **Step 1: Write failing test** — seed a pro with `listing_status=active`, `listing_expires=2020-01-01`, fire the sweep, assert status is now `expired` and `is_paid_listing=0`.

- [ ] **Step 2: Implement**

```php
register_activation_hook( __FILE__, function() {
    if ( ! wp_next_scheduled( 'frp_expiry_sweep' ) ) {
        wp_schedule_event( time(), 'daily', 'frp_expiry_sweep' );
    }
} );
register_deactivation_hook( __FILE__, function() {
    wp_clear_scheduled_hook( 'frp_expiry_sweep' );
} );
add_action( 'frp_expiry_sweep', 'frp_run_expiry_sweep' );

function frp_run_expiry_sweep() {
    $now = gmdate( 'c' );
    $q = new WP_Query( [
        'post_type' => 'restoration_pro', 'posts_per_page' => -1, 'fields' => 'ids',
        'meta_query' => [
            'relation' => 'AND',
            [ 'key' => 'listing_status', 'value' => 'active' ],
            [ 'key' => 'listing_expires', 'value' => $now, 'compare' => '<', 'type' => 'CHAR' ],
        ],
    ] );
    foreach ( $q->posts as $id ) {
        update_post_meta( $id, 'listing_status', 'expired' );
        update_post_meta( $id, 'is_paid_listing', 0 );
        do_action( 'frp_pro_expired_by_sweep', $id );
    }
}
```

Also expose a manual-trigger admin URL `/wp-admin/admin-post.php?action=frp_expiry_sweep` for on-demand runs.

- [ ] **Step 3: PASS → commit**

```bash
git add wordpress-plugins/frp-billing.php tests/expiry-sweep.test.mjs
git commit -m "feat(frp): daily expiry sweep self-heals missed Stripe webhooks"
```

### Task 1.12: GDPR / CCPA — lead deletion endpoint

**Files:**
- Modify: `wordpress-plugins/frp-directory.php` (`/frp/v1/leads/{id}/delete`)
- Test: `tests/lead-delete.test.mjs`

**Why:** With PII encrypted at rest (Task 2.4), we're still storing it. State privacy law (CCPA, and GDPR if we ever take EU traffic) requires a "right to be forgotten" path. Homeowners click a link in their confirmation email → identify themselves via `lead_update_token` → lead is hard-deleted.

**Design:** Endpoint `POST /frp/v1/leads/{id}/delete` with token. On success: `wp_delete_post($lead_id, true)` (force bypass trash). Log an anonymized audit row in a `frp_lead_deletion_log` table (date, zip, service, no PII) for compliance reporting.

- [ ] **Step 1: Write failing tests** — valid token deletes; wrong token 403; already-deleted returns 404.

- [ ] **Step 2: Implement handler**

```php
register_rest_route( 'frp/v1', '/leads/(?P<id>\d+)/delete', [
    'methods' => 'POST', 'callback' => 'frp_lead_delete_handler',
    'permission_callback' => '__return_true',
] );

function frp_lead_delete_handler( WP_REST_Request $r ) {
    if ( ! frp_verify_turnstile( $r ) ) return new WP_Error( 'forbidden', 'Bot check failed', [ 'status' => 403 ] );
    $lead_id = (int) $r['id'];
    if ( get_post_type( $lead_id ) !== 'frp_lead' ) return new WP_Error( 'not_found', 'Not found', [ 'status' => 404 ] );

    $token  = (string) $r->get_param( 'token' );
    $stored = (string) get_post_meta( $lead_id, 'lead_update_token', true );
    $expiry = (string) get_post_meta( $lead_id, 'lead_update_token_expiry', true );
    if ( ! $token || ! $stored || ! hash_equals( $stored, $token ) ) return new WP_Error( 'forbidden', 'Invalid token', [ 'status' => 403 ] );
    if ( ! $expiry || strtotime( $expiry ) < time() )              return new WP_Error( 'forbidden', 'Expired', [ 'status' => 403 ] );

    // Anonymized audit log (no PII)
    $zip = (string) get_post_meta( $lead_id, 'lead_zip', true );
    $svc = (string) get_post_meta( $lead_id, 'lead_service', true );
    wp_insert_post( [
        'post_type' => 'frp_lead_deletion',
        'post_status' => 'publish',
        'post_title' => "Deletion {$lead_id}",
        'meta_input' => [ 'zip' => $zip, 'service' => $svc, 'deleted_at' => gmdate( 'c' ) ],
    ] );

    wp_delete_post( $lead_id, true );
    return [ 'deleted' => true ];
}
```

Also register the `frp_lead_deletion` CPT (private, manage_options only) alongside `frp_lead`.

- [ ] **Step 3: PASS → commit**

```bash
git add wordpress-plugins/frp-directory.php tests/lead-delete.test.mjs
git commit -m "feat(frp): CCPA/GDPR lead deletion endpoint with anonymized audit"
```

---

## Chunk 2: Security Hardening

Tackle the five issues called out in the audit, in priority order.

### Task 2.1: Replace global `FRP_LEAD_SECRET` with Turnstile + per-request nonce

**Files:**
- Modify: `wordpress-plugins/frp-directory.php` (remove `wp_head` injection; replace token check; add Turnstile verification)
- Modify: `stitch-html/frp-home-rebuilt.html` (add Turnstile widget; remove `FRP_LEAD_TOKEN` references)
- Modify: `stitch-html/frp-join.html` (add Turnstile widget)
- Modify: `.env.example` (add `CF_TURNSTILE_SECRET`, `CF_TURNSTILE_SITEKEY`; remove `FRP_LEAD_SECRET`)
- Test: `tests/turnstile.test.mjs` (new)

**Design:** Public forms embed the Cloudflare Turnstile widget (free; privacy-respecting; no Google). The widget emits a `cf-turnstile-response` token that the form posts alongside the rest. The server verifies the token via `https://challenges.cloudflare.com/turnstile/v0/siteverify`. Successful verification is the ONLY check on first submission. Returning visitors can optionally use a short-lived (15 min) nonce tied to the session cookie to skip a second challenge for `/leads/{id}` updates — but MVP uses Turnstile on every write.

The existing `wp_head` injection at lines 15–18 is **deleted entirely**.

- [ ] **Step 1: Write failing test**

```javascript
import test from 'node:test';
import assert from 'node:assert/strict';
import { frpPost } from './helpers/wp-client.mjs';

test('lead create without turnstile token → 403', async () => {
  const { status } = await frpPost('/wp-json/frp/v1/leads', {
    service: 'water-damage', zip: '90210', urgency: 'now',
    property_type: 'residential', has_insurance: 'yes',
    phone: '5551234567', source: 'guided_flow',
  });
  assert.equal(status, 403);
});

test('lead create with valid turnstile token → 200', async () => {
  // staging configured with CF_TURNSTILE_SECRET=1x0000000000000000000000000000000AA (always-pass)
  const { status } = await frpPost('/wp-json/frp/v1/leads', {
    service: 'water-damage', zip: '90210', urgency: 'now',
    property_type: 'residential', has_insurance: 'yes',
    phone: '5551234567', source: 'guided_flow',
    cf_turnstile_response: 'XXXX.DUMMY.TOKEN.XXXX',
  });
  assert.equal(status, 200);
});
```

- [ ] **Step 2: Run — expect FAIL (current code accepts without token if header bypass is intact)**

- [ ] **Step 3: Delete `wp_head` token injection and legacy token check**

In `frp-directory.php`:
- Delete lines 15–18 (`wp_head` action injecting `window.FRP_LEAD_TOKEN`).
- Delete lines 697–703 in `frp_lead_create_handler` (token check using `FRP_LEAD_SECRET`).
- Delete lines 1009–1013 in the contact/other handler (same pattern).
- Add a Turnstile verification helper. **Fail-closed by default** — if `CF_TURNSTILE_SECRET` is undefined, reject. Only an explicit dev-mode flag permits bypass:
```php
function frp_verify_turnstile( WP_REST_Request $r ) {
    if ( ! defined( 'CF_TURNSTILE_SECRET' ) ) {
        // Fail closed. A missing constant is a misconfigured production, not a dev escape hatch.
        if ( defined( 'FRP_TURNSTILE_DEV_BYPASS' ) && FRP_TURNSTILE_DEV_BYPASS === true ) {
            error_log( 'FRP: Turnstile bypassed via FRP_TURNSTILE_DEV_BYPASS' );
            return true;
        }
        return false;
    }
    $tok = (string) $r->get_param( 'cf_turnstile_response' );
    if ( ! $tok ) return false;
    $resp = wp_remote_post( 'https://challenges.cloudflare.com/turnstile/v0/siteverify', [
        'body' => [
            'secret'   => CF_TURNSTILE_SECRET,
            'response' => $tok,
            'remoteip' => frp_client_ip(),
        ],
        'timeout' => 5,
    ] );
    if ( is_wp_error( $resp ) ) return false;
    $body = json_decode( wp_remote_retrieve_body( $resp ), true );
    return ! empty( $body['success'] );
}
```

**Prerequisite:** `frp_client_ip()` is used above but is defined in Task 2.2. Since Task 2.1 must land first (it swaps the auth model), add a minimal `frp_client_ip()` stub here that just returns `$_SERVER['REMOTE_ADDR']`. Task 2.2 replaces the stub with the full trusted-proxy implementation.

```php
// Placeholder; Task 2.2 replaces this with trusted-proxy-aware version.
if ( ! function_exists( 'frp_client_ip' ) ) {
    function frp_client_ip() { return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'; }
}
```

Call `if ( ! frp_verify_turnstile( $request ) ) return new WP_Error( 'forbidden', 'Bot check failed', [ 'status' => 403 ] );` at the top of every public POST handler (`frp_lead_create_handler`, `frp_lead_update_handler`, `frp_apply_handler`, `frp_contact_handler`).

- [ ] **Step 4: Update forms**

In `stitch-html/frp-home-rebuilt.html`:
- Remove the `FRP_LEAD_TOKEN` constant (lines 940–941).
- Remove `'X-FRP-Lead-Token': FRP_LEAD_TOKEN` from all three fetch calls (lines 1349, 1458, 1519).
- Add `<div class="cf-turnstile" data-sitekey="{{CF_TURNSTILE_SITEKEY}}" data-callback="onTurnstileSuccess"></div>` before each submit button and load the script: `<script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>`.
- Add the token to the fetch body: `body: JSON.stringify({ ...data, cf_turnstile_response: window._frpTurnstileToken })`.
- Ops populates `{{CF_TURNSTILE_SITEKEY}}` via an Elementor widget snippet that echoes `define('CF_TURNSTILE_SITEKEY', '...')` into `wp_head` (OK because sitekeys are public by design).

Apply equivalent changes to `stitch-html/frp-join.html` and any other form-posting page.

- [ ] **Step 5: Deploy; run tests — expect PASS (both)**

- [ ] **Step 6: Commit**

```bash
git add wordpress-plugins/frp-directory.php stitch-html/frp-home-rebuilt.html stitch-html/frp-join.html .env.example tests/turnstile.test.mjs
git commit -m "security(frp): replace FRP_LEAD_TOKEN with Cloudflare Turnstile"
```

### Task 2.2: Harden rate limiter — trusted-proxy chain for real client IP

**Files:**
- Modify: `wordpress-plugins/frp-directory.php` (`frp_check_rate_limit` + new `frp_client_ip`)
- Test: `tests/rate-limit.test.mjs` (new)

**Design:** `frp_check_rate_limit` currently uses `$_SERVER['REMOTE_ADDR']` which, behind Cloudflare/SiteGround's proxy, is the proxy's IP — so every request shares a bucket. Real IP lives in `$_SERVER['HTTP_CF_CONNECTING_IP']` (Cloudflare) or the right-most trustable entry in `X-Forwarded-For`. Define a `FRP_TRUSTED_PROXIES` config (CIDRs) and only read forwarded headers when REMOTE_ADDR is in that list.

- [ ] **Step 1: Write failing test**

```javascript
test('rate limit keys on CF-Connecting-IP not proxy address', async () => {
  // Burst 4 leads from "5.5.5.5"; 4th should 429
  for (let i = 0; i < 3; i++) {
    const { status } = await frpPost('/wp-json/frp/v1/leads',
      { /* valid body */ cf_turnstile_response: 'always-pass' },
      { headers: { 'CF-Connecting-IP': '5.5.5.5' } });
    assert.equal(status, 200);
  }
  const { status } = await frpPost('/wp-json/frp/v1/leads',
    { /* valid body */ cf_turnstile_response: 'always-pass' },
    { headers: { 'CF-Connecting-IP': '5.5.5.5' } });
  assert.equal(status, 429);
});

test('forged CF-Connecting-IP from untrusted source is ignored', async () => {
  // Simulated non-Cloudflare origin (e.g., REMOTE_ADDR not in FRP_TRUSTED_PROXIES)
  // Send burst with rotating CF headers; rate limiter should still throttle by REMOTE_ADDR
});
```

- [ ] **Step 2: Run — expect FAIL**

- [ ] **Step 3: Replace the `frp_client_ip` stub from Task 2.1 with the full implementation**

**Correctness notes:**
- **Use `CF-Connecting-IP` exclusively when behind Cloudflare.** Don't fall back to `X-Forwarded-For` — the XFF header order depends on proxy count and direction (client appears *left-most*, not right-most, when all hops are trusted). Fallback logic is the #1 source of client-IP spoofing bugs. Since this deployment is CF → SiteGround, we only need CF-Connecting-IP.
- **IPv6:** `ip2long` is IPv4-only. Implement v6 matching via `inet_pton` + raw byte comparison, or accept the v6 limitation and document it (Cloudflare publishes IPv6 ranges too; skipping v6 CIDRs means v6-origin clients are treated as untrusted and fall back to REMOTE_ADDR, which is safe but coarse).

```php
function frp_client_ip() {
    $remote = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    $trusted = defined( 'FRP_TRUSTED_PROXIES' ) ? array_map( 'trim', explode( ',', FRP_TRUSTED_PROXIES ) ) : [];

    // Only trust forwarded headers if REMOTE_ADDR is a known proxy.
    if ( ! frp_ip_in_list( $remote, $trusted ) ) {
        return $remote;
    }

    // Cloudflare injects the exact client IP here; it is NOT a chain.
    if ( ! empty( $_SERVER['HTTP_CF_CONNECTING_IP'] ) ) {
        $cand = filter_var( $_SERVER['HTTP_CF_CONNECTING_IP'], FILTER_VALIDATE_IP );
        if ( $cand ) return $cand;
    }

    // Deliberately do NOT consume X-Forwarded-For here — too fragile.
    return $remote;
}

function frp_ip_in_list( $ip, $cidrs ) {
    $is_v6 = strpos( $ip, ':' ) !== false;
    foreach ( $cidrs as $cidr ) {
        if ( ! $cidr ) continue;
        if ( strpos( $cidr, '/' ) === false ) {
            if ( $ip === $cidr ) return true;
            continue;
        }
        [ $subnet, $bits ] = explode( '/', $cidr );
        $bits = (int) $bits;

        if ( $is_v6 && strpos( $subnet, ':' ) !== false ) {
            // IPv6 byte-level compare
            $ip_bin     = @inet_pton( $ip );
            $subnet_bin = @inet_pton( $subnet );
            if ( $ip_bin === false || $subnet_bin === false ) continue;
            $bytes = intdiv( $bits, 8 );
            $rem   = $bits % 8;
            if ( substr( $ip_bin, 0, $bytes ) !== substr( $subnet_bin, 0, $bytes ) ) continue;
            if ( $rem === 0 ) return true;
            $mask = chr( 0xFF << ( 8 - $rem ) & 0xFF );
            if ( ( ord( $ip_bin[ $bytes ] ) & ord( $mask ) ) === ( ord( $subnet_bin[ $bytes ] ) & ord( $mask ) ) ) return true;
            continue;
        }

        if ( $is_v6 || strpos( $subnet, ':' ) !== false ) continue; // v4/v6 mismatch
        $ip_long = ip2long( $ip );
        $sub_long = ip2long( $subnet );
        if ( $ip_long === false || $sub_long === false ) continue;
        $mask = -1 << ( 32 - $bits );
        if ( ( $ip_long & $mask ) === ( $sub_long & $mask ) ) return true;
    }
    return false;
}
```

Modify `frp_check_rate_limit` at lines 23–32 to use `md5( frp_client_ip() )` instead of `md5( $_SERVER['REMOTE_ADDR'] )`.

Document in `.env.example`: `FRP_TRUSTED_PROXIES=173.245.48.0/20,103.21.244.0/22,103.22.200.0/22,141.101.64.0/18,108.162.192.0/18,190.93.240.0/20,188.114.96.0/20,197.234.240.0/22,198.41.128.0/17,162.158.0.0/15,104.16.0.0/13,104.24.0.0/14,172.64.0.0/13,131.0.72.0/22,2400:cb00::/32,2606:4700::/32,2803:f800::/32,2405:b500::/32,2405:8100::/32,2a06:98c0::/29,2c0f:f248::/32` (Cloudflare's published ranges — refresh from https://www.cloudflare.com/ips/ periodically).

- [ ] **Step 4: Run tests — expect PASS**

- [ ] **Step 5: Commit**

```bash
git add wordpress-plugins/frp-directory.php .env.example tests/rate-limit.test.mjs
git commit -m "security(frp): rate limiter uses real client IP via trusted proxies"
```

### Task 2.3: Escape stored review JSON on every display path

**Files:**
- Modify: `wordpress-plugins/frp-directory.php` (wherever `google_reviews` / `yelp_reviews` are read and echoed)
- Grep target: `get_post_meta.*google_reviews\|get_post_meta.*yelp_reviews` and audit each call site.
- Test: `tests/xss-reviews.test.mjs` (new)

**Design:** Reviews are stored as JSON strings and may contain user-generated text with `<script>` payloads. Every output path must `wp_json_encode` (safe for `<script>` context) or `esc_html` (for text context). Also sanitize on *write* to strip raw HTML tags from review bodies — defense in depth.

- [ ] **Step 1: Audit call sites**

```bash
grep -n "google_reviews\|yelp_reviews" wordpress-plugins/frp-directory.php
```
Note every call site in a checklist in the commit message.

- [ ] **Step 2: Write failing test**

```javascript
test('review with <script> does not render unescaped', async () => {
  // Seed a pro with a malicious review via authenticated meta update
  const pro_id = await createTestPro({
    google_reviews: JSON.stringify([{ author: '<script>alert(1)</script>', text: 'x', rating: 5 }]),
  });
  const html = await fetch(`${BASE}/restoration-pros/${pro_id}/`).then(r => r.text());
  assert.ok(!html.includes('<script>alert(1)</script>'));
  assert.ok(html.includes('&lt;script&gt;alert(1)&lt;/script&gt;') || !html.includes('alert(1)'));
});
```

- [ ] **Step 3: Patch every call site**

For contexts that inject JSON into a `<script>` block: `echo wp_json_encode( json_decode( $raw ) );` — wp_json_encode escapes `</script>` correctly.

For contexts that render review text into HTML: parse with `json_decode`, iterate, and `echo esc_html( $review->text )`.

On write (any admin meta save or seeding script): `$clean = array_map( fn($r) => [
  'author' => wp_strip_all_tags( $r['author'] ?? '' ),
  'text'   => wp_strip_all_tags( $r['text']   ?? '' ),
  'rating' => (float) ( $r['rating'] ?? 0 ),
], $raw );` then `update_post_meta( $id, 'google_reviews', wp_json_encode( $clean ) );`.

- [ ] **Step 4: Run test — expect PASS**

- [ ] **Step 5: Commit**

```bash
git add wordpress-plugins/frp-directory.php tests/xss-reviews.test.mjs
git commit -m "security(frp): escape stored review JSON on all display paths"
```

### Task 2.4: Encrypt PII at rest for `frp_lead`

**Files:**
- Create: `wordpress-plugins/frp-security.php`
- Modify: `wordpress-plugins/frp-directory.php` (use `frp_enc_*` helpers for `lead_phone`, `lead_email`, `lead_address`)
- Test: `tests/pii-encryption.test.mjs` (new)

**Design:** libsodium AEAD (`sodium_crypto_secretbox`) with a site-specific key stored in `wp-config.php` as `FRP_PII_KEY` (32-byte base64). Getters/setters wrap plaintext on write, decrypt on authorized read. Decryption only happens for admins (`manage_options`) and the Make.com webhook dispatcher (which is server-side PHP and has access to the key).

Trade-off: admin screens that currently display raw meta values will need patching — the plugin's admin list columns read through `frp_lead_get_meta()` instead of `get_post_meta()`.

- [ ] **Step 1: Write failing test**

```javascript
test('lead_phone is encrypted in wp_postmeta', async () => {
  const lead = await createTestLead({ phone: '5551234567' });
  // Fetch raw postmeta via a privileged debug endpoint
  const { body } = await frpGet(`/wp-json/frp/v1/admin/debug/lead-raw/${lead.lead_id}`, { auth: true });
  assert.notEqual(body.lead_phone_raw, '5551234567');
  assert.match(body.lead_phone_raw, /^enc:v1:/);
});

test('admin reads decrypted phone via helper', async () => {
  const lead = await createTestLead({ phone: '5551234567' });
  const { body } = await frpGet(`/wp-json/frp/v1/admin/lead/${lead.lead_id}`, { auth: true });
  assert.equal(body.lead_phone, '5551234567');
});
```

- [ ] **Step 2: Implement `frp-security.php`**

```php
<?php
if ( ! defined( 'ABSPATH' ) ) exit;

function frp_pii_key() {
    if ( ! defined( 'FRP_PII_KEY' ) ) {
        throw new RuntimeException( 'FRP_PII_KEY not set in wp-config.php' );
    }
    return base64_decode( FRP_PII_KEY );
}

function frp_enc( string $plain ) : string {
    if ( $plain === '' ) return '';
    $nonce = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
    $cipher = sodium_crypto_secretbox( $plain, $nonce, frp_pii_key() );
    return 'enc:v1:' . base64_encode( $nonce . $cipher );
}

function frp_dec( string $blob ) : string {
    if ( strpos( $blob, 'enc:v1:' ) !== 0 ) {
        // Plaintext passthrough is only allowed during the one-time migration window.
        // After migration completes, define `FRP_PII_DISALLOW_LEGACY=true` in wp-config.php
        // to cause this path to throw — defense against future code paths writing plaintext.
        if ( defined( 'FRP_PII_DISALLOW_LEGACY' ) && FRP_PII_DISALLOW_LEGACY === true ) {
            throw new RuntimeException( 'FRP: plaintext PII encountered after migration cutoff' );
        }
        return $blob;
    }
    $raw = base64_decode( substr( $blob, 7 ) );
    $nonce = substr( $raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
    $cipher = substr( $raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
    $plain = sodium_crypto_secretbox_open( $cipher, $nonce, frp_pii_key() );
    return $plain === false ? '' : $plain;
}

// Note: the `enc:v1:` prefix is intentional. Future key-rotation will emit `enc:v2:` blobs
// alongside v1; `frp_dec` dispatches by version so we can re-encrypt gradually without
// an atomic migration. `scripts/rotate-pii-key.mjs` (stub today) will implement that flow.

const FRP_PII_META_KEYS = [ 'lead_phone', 'lead_email', 'lead_address' ];

function frp_lead_set_meta( int $lead_id, string $key, string $value ) : void {
    if ( in_array( $key, FRP_PII_META_KEYS, true ) ) {
        update_post_meta( $lead_id, $key, frp_enc( $value ) );
    } else {
        update_post_meta( $lead_id, $key, $value );
    }
}

function frp_lead_get_meta( int $lead_id, string $key ) : string {
    $raw = (string) get_post_meta( $lead_id, $key, true );
    return in_array( $key, FRP_PII_META_KEYS, true ) ? frp_dec( $raw ) : $raw;
}
```

In `frp-directory.php` `frp_lead_create_handler`, every place it calls `update_post_meta( $lead_id, 'lead_phone', ... )` → replace with `frp_lead_set_meta(...)`. Same for `lead_email` and `lead_address`. In the dispatcher that composes the Make.com webhook payload, use `frp_lead_get_meta` to decrypt before sending.

**Cross-task audit (mandatory):** After this task lands, every call site that *reads* a PII meta key must go through `frp_lead_get_meta` instead of `get_post_meta` — otherwise reads return ciphertext. Touch points created in earlier chunks:
- `frp_email_lead_created` (Task 1.10) — currently uses `get_post_meta` for `lead_email`
- `frp_dashboard_leads_html` (Task 1.8) — currently uses `get_post_meta` for `lead_phone`
- `frp_lead_update_handler` notes branch (Task 1.4) — if it ever echoes PII
- Any admin list-column renderer for `frp_lead`

Grep step before commit:
```bash
grep -n "get_post_meta.*lead_phone\|get_post_meta.*lead_email\|get_post_meta.*lead_address" wordpress-plugins/
```
Every hit must be replaced with `frp_lead_get_meta( $id, '<key>' )`.

Also: one-time migration script under `scripts/migrate-encrypt-pii.mjs` that iterates existing leads and encrypts any plaintext values. Runbook entry in `docs/superpowers/runbooks/pii-encryption-migration.md`.

- [ ] **Step 3: Run tests — expect PASS**

- [ ] **Step 4: Commit**

```bash
git add wordpress-plugins/frp-security.php wordpress-plugins/frp-directory.php scripts/migrate-encrypt-pii.mjs docs/superpowers/runbooks/pii-encryption-migration.md tests/pii-encryption.test.mjs
git commit -m "security(frp): encrypt lead PII at rest with libsodium AEAD"
```

### Task 2.5: Secret rotation runbook + `rotate-secrets` script

**Files:**
- Create: `scripts/rotate-secrets.mjs`
- Create: `docs/superpowers/runbooks/secret-rotation.md`
- Modify: `config/config.js` (remove rotation-date warning, replace with a CLI-driven audit)

**Design:** Quarterly rotation of Stripe webhook secret, Turnstile secret, Make.com webhook secret, FRP_PII_KEY. Each key has an explicit rotation procedure. The `rotate-secrets.mjs` script prints the current age of each key (reading timestamps from a `.secret-ages.json` file checked into the repo, updated manually after each rotation) and warns if any exceeds 90 days.

FRP_PII_KEY rotation requires a re-encryption migration — document this. Don't rotate FRP_PII_KEY casually.

- [ ] **Step 1: Write failing test for `rotate-secrets.mjs`**

```javascript
// tests/rotate-secrets.test.mjs
import test from 'node:test';
import assert from 'node:assert/strict';
import { execSync } from 'node:child_process';
import { writeFileSync, unlinkSync } from 'node:fs';

test('rotate-secrets exits non-zero when any secret > 90 days old', () => {
  writeFileSync('.secret-ages.json.test', JSON.stringify({
    stripe_webhook: '2020-01-01',
    turnstile: '2026-04-01',
  }));
  try {
    execSync('node scripts/rotate-secrets.mjs .secret-ages.json.test');
    assert.fail('expected non-zero exit');
  } catch (e) {
    assert.notEqual(e.status, 0);
    assert.match(e.stdout?.toString() ?? e.stderr?.toString(), /stripe_webhook.*days/i);
  } finally {
    unlinkSync('.secret-ages.json.test');
  }
});

test('rotate-secrets exits zero when all secrets fresh', () => {
  writeFileSync('.secret-ages.json.test', JSON.stringify({
    stripe_webhook: new Date().toISOString().slice(0,10),
  }));
  try {
    const out = execSync('node scripts/rotate-secrets.mjs .secret-ages.json.test');
    assert.match(out.toString(), /all secrets fresh/i);
  } finally {
    unlinkSync('.secret-ages.json.test');
  }
});
```

- [ ] **Step 2: Run — expect FAIL**

- [ ] **Step 3: Write the runbook**

Sections: key inventory, rotation cadence, per-key procedure (Stripe webhook secret can be rotated in Stripe dashboard → endpoint → roll; Turnstile secret in CF dashboard; Make.com in the scenario's webhook settings; FRP_PII_KEY requires staged re-encryption using `scripts/rotate-pii-key.mjs` — out of scope for this task, stub).

- [ ] **Step 4: Write `rotate-secrets.mjs`**

Reads `.secret-ages.json` (or path from argv[2]), reports days-since-rotation, exits non-zero if any > 90. Prints "all secrets fresh" when clean. Wire into a weekly cron reminder via Make.com webhook.

- [ ] **Step 5: Run — expect PASS**

- [ ] **Step 6: Commit**

```bash
git add scripts/rotate-secrets.mjs docs/superpowers/runbooks/secret-rotation.md config/config.js .secret-ages.json tests/rotate-secrets.test.mjs
git commit -m "security(frp): formal secret-rotation runbook + age audit script"
```

### Task 2.6: HTTP security headers (CSP, HSTS, frame options)

**Files:**
- Modify: `wordpress-plugins/frp-security.php` (add `send_headers` hook)
- Test: `tests/security-headers.test.mjs`

**Why:** Any third-party security scan (and most SaaS contractor procurement questionnaires) will flag missing CSP / HSTS / X-Frame-Options. 10 minutes to close.

- [ ] **Step 1: Write failing test** — fetch homepage, assert presence of headers.

```javascript
test('homepage sends HSTS + X-Frame-Options + X-Content-Type-Options + CSP', async () => {
  const res = await fetch('https://staging.findrestorationpros.com/');
  assert.ok(res.headers.get('strict-transport-security'));
  assert.equal(res.headers.get('x-frame-options'), 'SAMEORIGIN');
  assert.equal(res.headers.get('x-content-type-options'), 'nosniff');
  assert.match(res.headers.get('content-security-policy'), /default-src/);
});
```

- [ ] **Step 2: Implement**

```php
add_action( 'send_headers', function() {
    header( 'Strict-Transport-Security: max-age=31536000; includeSubDomains' );
    header( 'X-Frame-Options: SAMEORIGIN' );
    header( 'X-Content-Type-Options: nosniff' );
    header( 'Referrer-Policy: strict-origin-when-cross-origin' );
    header( 'Permissions-Policy: camera=(), microphone=(), geolocation=(self)' );
    // CSP — start report-only, tighten after 2-week monitoring
    $csp = "default-src 'self'; " .
           "script-src 'self' 'unsafe-inline' https://challenges.cloudflare.com https://js.stripe.com https://www.googletagmanager.com; " .
           "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; " .
           "font-src 'self' https://fonts.gstatic.com; " .
           "img-src 'self' data: https: blob:; " .
           "connect-src 'self' https://api.stripe.com https://challenges.cloudflare.com; " .
           "frame-src https://challenges.cloudflare.com https://js.stripe.com https://checkout.stripe.com https://billing.stripe.com; " .
           "form-action 'self' https://checkout.stripe.com;";
    header( 'Content-Security-Policy-Report-Only: ' . $csp );
} );
```

Flip from `Content-Security-Policy-Report-Only` to enforcing `Content-Security-Policy` after 2 weeks of clean reports.

- [ ] **Step 3: PASS → commit**

```bash
git add wordpress-plugins/frp-security.php tests/security-headers.test.mjs
git commit -m "security(frp): HSTS, CSP (report-only), frame/content-type hardening"
```

---

## Chunk 3: Plan Alignment & Dead Code

Close the loop on the four mismatches identified in the audit.

### Task 3.1: (Verification only) Confirm `CONFIG.zapier` and `FRP_LEAD_SECRET` are gone

Not a coding task; runs after Chunk 0 + Task 2.1 to confirm no residual references.

- [ ] **Step 1: Grep for zapier remnants**

```bash
grep -rn "CONFIG\.zapier\|config\.zapier\|zapier" scripts/ config/ wordpress-plugins/ tests/
```
Expected: no hits outside `archive/` (archived comments are fine).

- [ ] **Step 2: Grep for FRP_LEAD_SECRET remnants**

```bash
grep -rn "FRP_LEAD_SECRET\|FRP_LEAD_TOKEN" scripts/ config/ wordpress-plugins/ stitch-html/ tests/
```
Expected: no hits outside `archive/`.

- [ ] **Step 3: If hits exist, file a follow-up task and remove; otherwise mark this verified.**

### Task 3.2: Remove `emergency_flow` source value

**Files:**
- Modify: `wordpress-plugins/frp-directory.php` (remove from `$valid_sources` at line 715 and the `$is_emergency` branch at 721–729)
- Test: `tests/emergency-flow.test.mjs` (new)

**Decision (D4):** The UI no longer presents an emergency flow post commit 6444339. Accepting `source=emergency_flow` now lets callers bypass required `urgency`/`property`/`insurance` validation with silent defaults (plugin lines 727–729) — a real problem. Remove.

- [ ] **Step 1: Write failing test**

```javascript
test('source=emergency_flow is rejected', async () => {
  const { status, body } = await frpPost('/wp-json/frp/v1/leads', {
    source: 'emergency_flow', service: 'water-damage', zip: '90210',
    phone: '5551234567', cf_turnstile_response: 'always-pass',
  });
  // With emergency_flow removed from whitelist, source falls back to 'guided_flow'
  // and then missing urgency/property/insurance triggers 400
  assert.equal(status, 400);
  assert.match(body.message, /urgency|property|insurance/i);
});
```

- [ ] **Step 2: Run — expect FAIL (currently accepts via is_emergency branch)**

- [ ] **Step 3: Patch plugin**

```php
// Change (line 715):
$valid_sources  = [ 'guided_flow', 'followup_modal' ]; // removed 'emergency_flow'

// Delete lines 720–721 ($is_emergency)
// Delete the " : ( $is_emergency ? 'now' : null )" fallbacks on 727–729
// Change to simple:
$urgency   = in_array( $raw_urgency,  $valid_urgency,  true ) ? $raw_urgency  : null;
$property  = in_array( $raw_property, $valid_property, true ) ? $raw_property : null;
$insurance = in_array( $raw_insure,   $valid_insurance, true ) ? $raw_insure   : null;
```

- [ ] **Step 4: Run — expect PASS**

- [ ] **Step 5: Commit**

```bash
git add wordpress-plugins/frp-directory.php tests/emergency-flow.test.mjs
git commit -m "fix(frp): remove dead emergency_flow source; tighten intake validation"
```

### Task 3.3: Remove `scope` field (dead schema)

**Files:**
- Modify: `wordpress-plugins/frp-directory.php` (remove scope parsing at lines 764–776; remove `lead_scope` from `frp_register_lead_meta` at line 99)

**Decision (D5):** The 5-step UI never captures `scope`. Accepting it server-side is dead schema. If we revive a damage-scope capture later, reintroduce with a proper form step and test coverage.

- [ ] **Step 1: Grep for any live client sending `scope`**

```bash
grep -n "scope" stitch-html/*.html
```
Expected: no matches. Confirm before removing.

- [ ] **Step 2: Remove the parse block**

In `frp-directory.php`, delete lines 764–776 (the `scope` sanitize block). Remove `'lead_scope'` from the string_fields array near line 99.

- [ ] **Step 3: Run entire test suite — ensure no regressions**

```bash
node --test tests/
```

- [ ] **Step 4: Commit**

```bash
git add wordpress-plugins/frp-directory.php
git commit -m "chore(frp): remove unused scope field from lead intake"
```

### Task 3.4: Dispatch-query scale — indexed geohash column

**Files:**
- Modify: `wordpress-plugins/frp-directory.php` (add geohash column on save; use `meta_query LIKE 'prefix'` for coarse pre-filter before haversine)
- Test: `tests/dispatch-scale.test.mjs` (new — benchmark assert)

**Design:** At 10k pros, `frp_dispatch_query` iterates 100 posts and reads `lat`/`lng` from `postmeta` for each = 200 DB round-trips per lead. Use a two-level geohash scheme to prefilter:

- `geohash_3` meta (~156km × 156km cells) — coarse. For a 50-mile (~80km) radius search, compute the 9-cell "box of 9" around the lead cell (center + 8 neighbors). This is the cheap DB prefilter.
- `geohash_5` meta (~4.9km × 4.9km cells) — fine-grained. For a 1–3 mile "paid-ZIP" Tier-1 match, the 9-neighbor box at precision 5 is the right size.

**Do not** use precision-5 for a 50mi search. At ~5km cells, a 50mi (80km) radius is ~256+ cells — `meta_query IN (...)` explodes. Pick precision by radius:

| Radius | Precision | Neighbor cells needed |
|--------|-----------|----------------------|
| ≤ 3 mi | 5 | 9 |
| ≤ 25 mi | 4 (~39km) | 9 |
| ≤ 50 mi | 3 (~156km) | 9 |

This is a scalability patch, not a correctness patch. MVP can skip if pro count is < 500; add the task but gate execution on launch-date pro count.

- [ ] **Step 1: Write benchmark test**

```javascript
test('dispatch query < 250ms at 1000 pros', async () => {
  await seed1000TestPros(); // helper
  const start = performance.now();
  await frpPost('/wp-json/frp/v1/leads', { /* valid body */ });
  const ms = performance.now() - start;
  assert.ok(ms < 250, `dispatch took ${ms}ms`);
});
```

- [ ] **Step 2: Add geohash computation (both precisions)**

```php
add_action( 'save_post_restoration_pro', function( $post_id ) {
    $lat = (float) get_post_meta( $post_id, 'lat', true );
    $lng = (float) get_post_meta( $post_id, 'lng', true );
    if ( $lat && $lng ) {
        update_post_meta( $post_id, 'geohash_3', frp_geohash( $lat, $lng, 3 ) );
        update_post_meta( $post_id, 'geohash_5', frp_geohash( $lat, $lng, 5 ) );
    }
}, 20, 1 );

function frp_geohash( $lat, $lng, $precision ) {
    $base32 = '0123456789bcdefghjkmnpqrstuvwxyz';
    $lat_r = [ -90.0, 90.0 ]; $lng_r = [ -180.0, 180.0 ];
    $hash = ''; $bit = 0; $ch = 0; $even = true;
    while ( strlen( $hash ) < $precision ) {
        if ( $even ) {
            $mid = ( $lng_r[0] + $lng_r[1] ) / 2;
            if ( $lng >= $mid ) { $ch = ( $ch << 1 ) + 1; $lng_r[0] = $mid; }
            else { $ch = $ch << 1; $lng_r[1] = $mid; }
        } else {
            $mid = ( $lat_r[0] + $lat_r[1] ) / 2;
            if ( $lat >= $mid ) { $ch = ( $ch << 1 ) + 1; $lat_r[0] = $mid; }
            else { $ch = $ch << 1; $lat_r[1] = $mid; }
        }
        $even = ! $even;
        if ( ++$bit === 5 ) { $hash .= $base32[ $ch ]; $bit = 0; $ch = 0; }
    }
    return $hash;
}
```

- [ ] **Step 3: Implement `frp_geohash_neighbors` (9-cell box)**

```php
function frp_geohash_neighbors( $hash ) {
    // Decode hash to [lat, lng], then emit 8 neighbors by nudging +/- cell size.
    [ $lat, $lng, $lat_err, $lng_err ] = frp_geohash_decode( $hash );
    $len = strlen( $hash );
    $cells = [ $hash ];
    foreach ( [ -1, 0, 1 ] as $dLat ) {
        foreach ( [ -1, 0, 1 ] as $dLng ) {
            if ( $dLat === 0 && $dLng === 0 ) continue;
            $n = frp_geohash( $lat + $dLat * $lat_err * 2, $lng + $dLng * $lng_err * 2, $len );
            if ( ! in_array( $n, $cells, true ) ) $cells[] = $n;
        }
    }
    return $cells;
}
// frp_geohash_decode — standard geohash bbox decoder; include alongside frp_geohash.
```

- [ ] **Step 4: Modify `frp_dispatch_query`**

Pick the geohash precision by intended radius:
- Tier 1 (paid, <=3mi effective via zip_codes/service_radius): use `geohash_5`.
- Tier 2 (25mi forced): use `geohash_4` (add a `geohash_4` meta write in Step 2 if you want Tier-2 indexed; otherwise fall back to `geohash_3`).
- Tiers 3/4 (50mi forced): use `geohash_3`.

Then:
```php
$cells = frp_geohash_neighbors( frp_geohash( $lead_coords[0], $lead_coords[1], $precision ) );
$meta_query[] = [ 'key' => "geohash_{$precision}", 'value' => $cells, 'compare' => 'IN' ];
```

Keep the haversine pass for exact distance filter + sort (unchanged).

- [ ] **Step 5: Backfill geohash on existing pros**

```bash
node scripts/backfill-geohash.mjs
```
(new one-shot script iterates all `restoration_pro` posts and triggers a meta update to force save_post filter).

- [ ] **Step 6: Run benchmark — expect PASS**

- [ ] **Step 7: Commit**

```bash
git add wordpress-plugins/frp-directory.php scripts/backfill-geohash.mjs tests/dispatch-scale.test.mjs
git commit -m "perf(frp): geohash-5 prefilter for dispatch query"
```

---

## Chunk 4: SEO Pivot — Directory Authority Content

Abandon the `[service] [city]` generator pattern (it was fighting iRP; now iRP is gone but the pattern is still weak content by 2025 Google standards). Pivot FRP content to what directories actually rank for: aggregate/comparison/authority.

**Content thesis:** FRP wins when it has *more* real contractor data, more real reviews, and more decisions made for the homeowner than competing results. A city page that lists 5 named contractors with real review excerpts is irreplaceable; a city page that paraphrases a template isn't.

### Task 4.0: Define FRP URL taxonomy + content model (decide before generating)

**Files:**
- Create: `docs/superpowers/runbooks/frp-url-taxonomy.md`

**Why:** Task 4.2 would otherwise bake URL shape into an implementation step. Decide first, build second. Once a URL has external links/rankings, it's expensive to change.

**Locked-in taxonomy:**
- Home: `/`
- Service pillar: `/services/{service}/` (e.g., `/services/water-damage/`) — 7 pages total, one per `$valid_services` entry
- City × service "top N": `/{state-abbr}/{city-slug}/{service}/` (e.g., `/ca/oakland/water-damage/`) — only published when ≥ 3 qualified pros exist
- Contractor profile: `/restoration-pros/{slug}/` (already defined by CPT rewrite)
- Contractor spotlight (featured tier): `/spotlight/{slug}/`
- Content / guides: `/guides/{slug}/`
- Legal: `/privacy/`, `/terms/`

**Anchor rules:** descriptive, varied phrasing; never exact-match keyword stuffing; max 4 internal links per paragraph.

**Explicit decision (supersedes D7 wording):** Yes, the `/{state}/{city}/{service}/` URL shape is structurally similar to the old `[service]-[city]` pages — the **differentiator is the content**: real named contractors, real review excerpts, real schema. Same URL shape with higher quality is fine; low-quality pages with any URL shape are not.

- [ ] **Step 1: Write the taxonomy doc**

- [ ] **Step 2: Commit**

```bash
git add docs/superpowers/runbooks/frp-url-taxonomy.md
git commit -m "docs(frp-seo): lock FRP URL taxonomy before generator tasks"
```

### Task 4.1: Kill iRP-style programmatic generator for FRP

- [ ] **Step 1: Confirm Chunk 0 already archived `city-page-generator.js`. No-op.**

### Task 4.2: New content generator — "Top N contractors in [city] for [service]"

**Files:**
- Create: `scripts/frp-content/top-contractors.mjs`
- Create: `scripts/frp-content/templates/top-contractors.html`
- Test: `tests/frp-content.test.mjs` (new)

**Design:** For each (city, service) pair, query the FRP directory (`/wp-json/frp/v1/search?zip=<centroid>&service=<svc>`), grab top 5 by `google_rating` × `google_review_count`, enrich each with 2–3 real review excerpts (via Places API), generate a 300-word editorial intro with Claude that references the *specific contractors by name and their actual attributes* (years in business, certifications) — not generic filler. Embed LocalBusiness + ItemList + AggregateRating schema.

Page never publishes if fewer than 3 qualified contractors exist — quality gate.

- [ ] **Step 1: Write failing test**

```javascript
test('generator produces page only when ≥3 pros available', async () => {
  const result = await generateTopContractors({ city: 'Tinytown', state: 'CA', service: 'water-damage' });
  assert.equal(result.published, false);
  assert.match(result.reason, /insufficient pros/i);
});

test('generated page references each contractor by name', async () => {
  // seed 5 pros in Oakland, CA for water-damage
  const result = await generateTopContractors({ city: 'Oakland', state: 'CA', service: 'water-damage' });
  assert.equal(result.published, true);
  for (const pro of result.included_pros) {
    assert.ok(result.html.includes(pro.name));
  }
});
```

- [ ] **Step 2: Implement**

```javascript
// scripts/frp-content/top-contractors.mjs
import Anthropic from '@anthropic-ai/sdk';
import { CONFIG } from '../../config/config.js';

const client = new Anthropic({ apiKey: CONFIG.anthropic.apiKey });

export async function generateTopContractors({ city, state, service, zipCentroid }) {
  const pros = await fetchProsInCity({ city, state, service, zipCentroid });
  if (pros.length < 3) return { published: false, reason: 'insufficient pros' };

  const top5 = rankAndLimit(pros, 5);
  const enriched = await Promise.all(top5.map(enrichWithReviews));

  const intro = await generateEditorialIntro(city, state, service, enriched);
  const schema = buildSchemaJsonLd(city, state, service, enriched);
  const html = renderTemplate({ intro, pros: enriched, schema, city, state, service });

  const slug = `top-${service}-contractors-${slugify(city)}-${state.toLowerCase()}`;
  const published = await publishToFrp({ slug, html, title: `Top 5 ${prettyService(service)} Contractors in ${city}, ${state}` });

  return { published: true, slug, included_pros: enriched, html };
}
```

**Helpers — define in the same module or small sibling files:**
- `fetchProsInCity({ city, state, service, zipCentroid })` → array of pros from `/wp-json/frp/v1/search?zip={centroid}&service={svc}`, filtered by `city===state`.
- `rankAndLimit(pros, n)` → sort by `google_rating * log(google_review_count + 1)` desc, take n.
- `enrichWithReviews(pro)` → Task 4.3's enrichment.
- `generateEditorialIntro(city, state, service, pros)` → Claude call with prompt that explicitly names each pro and references their real attributes (years_in_business, certifications).
- `buildSchemaJsonLd(city, state, service, pros)` → Task 4.4's ItemList + LocalBusiness output.
- `renderTemplate({ intro, pros, schema, city, state, service })` → reads `templates/top-contractors.html`, substitutes `{{vars}}`, returns HTML.
- `publishToFrp({ slug, html, title })` → uses `WordPressPublisher('authority')` (from `scripts/wp-publisher.js`).
- `slugify(s)` → lowercase, `[^a-z0-9]+` → `-`, trim dashes.
- `prettyService(s)` → maps `water-damage` → `Water Damage`, etc.

Each helper gets its own small test file. The task above is the integration test; add unit tests for `rankAndLimit` (determinism, tie-breaking) and `slugify` (edge cases: unicode, trailing dashes) as separate commits.

- [ ] **Step 3: Run tests — expect PASS**

- [ ] **Step 4: Commit**

```bash
git add scripts/frp-content/ tests/frp-content.test.mjs
git commit -m "feat(frp-seo): Top-N contractors generator with real data + schema"
```

### Task 4.3: Real review aggregation enrichment

**Files:**
- Create: `scripts/frp-content/enrich-reviews.mjs`
- Modify: `scripts/frp-content/top-contractors.mjs` (use enrichment helper)
- Test: `tests/enrich-reviews.test.mjs`

**Design:** For each pro on a generated page, pull 2–3 most recent 4–5-star Google reviews via Places API (already in config). Sanitize per Task 2.3 rules. Store back onto the pro CPT's `google_reviews` meta so it's fresh and can be reused on the profile page as well.

- [ ] **Step 1: Write failing test**

Covers: returns N reviews, writes to CPT, dedupes by review hash, respects Places API quota.

- [ ] **Step 2: Implement**

- [ ] **Step 3: Run tests — expect PASS**

- [ ] **Step 4: Commit**

```bash
git add scripts/frp-content/enrich-reviews.mjs tests/enrich-reviews.test.mjs
git commit -m "feat(frp-seo): Places API review enrichment per pro"
```

### Task 4.4: Schema.org — LocalBusiness + ItemList + AggregateRating

**Files:**
- Create: `scripts/frp-content/schema.mjs`
- Modify: `wordpress-plugins/frp-directory.php` (inject pro-profile schema on single `restoration_pro` template)
- Test: `tests/schema.test.mjs`

**Design:** On "Top N in X" pages: ItemList of LocalBusiness entries. On profile pages: LocalBusiness + AggregateRating. On city/service pages: BreadcrumbList. Everything as JSON-LD `<script type="application/ld+json">` in the page `<head>`.

- [ ] **Step 1: Write tests**

Validate JSON-LD against schema.org requirements: required fields, AggregateRating `ratingCount` > 0, `address` with locality/region.

- [ ] **Step 2: Implement generators + plugin head injection**

- [ ] **Step 3: Run tests + external validator check**

```bash
curl "https://validator.schema.org/validate?url=https://staging.findrestorationpros.com/restoration-pros/testpro1/"
```
Spot-check output.

- [ ] **Step 4: Commit**

```bash
git add scripts/frp-content/schema.mjs wordpress-plugins/frp-directory.php tests/schema.test.mjs
git commit -m "feat(frp-seo): LocalBusiness + ItemList + AggregateRating JSON-LD"
```

### Task 4.5: Contractor spotlight content (E-E-A-T)

**Files:**
- Create: `scripts/frp-content/spotlight.mjs`
- Create: `scripts/frp-content/templates/spotlight.html`
- Test: `tests/spotlight.test.mjs`

**Design:** Long-form (1200–1800 word) profile per paid contractor: their story, certifications (verified from license_number + CSLB lookup if CA), service list with specifics, 3–5 job case studies drawn from contractor-submitted form (new form field `case_studies_json` under profile edit), embed their 1 YouTube if featured tier. This is the E-E-A-T differentiator. Generation is semi-automated — Claude writes the scaffold from contractor-provided bullets; contractor edits in the dashboard before publish.

Only runs for `listing_tier = featured` (incentivizes upgrade).

- [ ] **Step 1: Write failing tests — four scenarios**

```javascript
test('spotlight refuses to generate for non-featured pro', async () => {
  const pro = await createTestPro({ listing_tier: 'paid' });
  const res = await generateSpotlight(pro.pro_id);
  assert.equal(res.published, false);
  assert.match(res.reason, /featured tier required/i);
});

test('spotlight refuses when pro has no case_studies_json', async () => {
  const pro = await createTestPro({ listing_tier: 'featured', case_studies_json: '' });
  const res = await generateSpotlight(pro.pro_id);
  assert.equal(res.published, false);
  assert.match(res.reason, /no case studies/i);
});

test('spotlight includes pro name + each case study title', async () => {
  const pro = await createTestPro({ listing_tier: 'featured',
    case_studies_json: JSON.stringify([
      { title: 'Sherman Oaks kitchen flood — 2024', summary: 'Responded in 45 min, dried in 3 days.' },
    ]),
  });
  const res = await generateSpotlight(pro.pro_id);
  assert.ok(res.html.includes(pro.name));
  assert.ok(res.html.includes('Sherman Oaks kitchen flood'));
});

test('CSLB license lookup failure degrades gracefully (warns, does not block)', async () => {
  // Mock CSLB endpoint to 500
  const pro = await createTestPro({ listing_tier: 'featured', license_number: 'CSLB-BOGUS' });
  const res = await generateSpotlight(pro.pro_id);
  assert.equal(res.published, true);
  assert.ok(res.warnings.some(w => /cslb/i.test(w)));
});
```

- [ ] **Step 2: Run — expect FAIL on all four**

- [ ] **Step 3: Implement `scripts/frp-content/spotlight.mjs`**

Exported `generateSpotlight(proId)`:
1. Read pro meta; gate on `listing_tier === 'featured'`.
2. Parse `case_studies_json`; gate on ≥1 entry.
3. Attempt CSLB (CA) license lookup; on failure, log warning and continue without the credential block.
4. Fetch top 5 sanitized reviews via Task 4.3's enrichment.
5. Claude prompt: scaffold 1200–1800 word long-form; must weave in contractor name, license #, years, certifications, case study titles verbatim.
6. Render template (`templates/spotlight.html`) with vars.
7. Publish via FRP wp-publisher under `/spotlight/{slug}/`.

- [ ] **Step 4: Run — expect PASS (4/4)**

- [ ] **Step 5: Commit**

```bash
git add scripts/frp-content/spotlight.mjs scripts/frp-content/templates/spotlight.html tests/spotlight.test.mjs
git commit -m "feat(frp-seo): contractor spotlight long-form (featured tier only)"
```

### Task 4.6: Internal linking + topic cluster structure

**Files:**
- Create: `scripts/frp-content/internal-links.mjs`
- Create: `docs/superpowers/runbooks/frp-content-model.md`

**Design:** Hub-and-spoke model — each service (water-damage, fire-damage, etc.) has one "pillar" hub page on FRP (`/services/water-damage/`) that links to every city-level "Top 5" page. Each "Top 5" page links back to the service pillar and across to sibling-city pages. Each pro profile links to the relevant city's "Top 5" page and the service pillar. No orphans; no over-linking (max 4 internal links per paragraph). The generator auto-inserts these links on every rebuild and logs a sitemap-audit summary.

- [ ] **Step 1: Write failing tests for orphan detection + link budget**

```javascript
test('orphan audit flags a page with zero inbound links', async () => {
  const result = await auditInternalLinks({ includeOrphanCheck: true });
  // Seed fixture has /services/water-damage/ with no inbound links from anywhere
  assert.ok(result.orphans.some(url => url.endsWith('/services/water-damage/')));
});

test('link inserter respects 4-links-per-paragraph budget', async () => {
  const html = await insertContextualLinks('<p>water damage fire damage mold mold mold water damage fire</p>');
  // Ensure no more than 4 <a> tags in a single <p>
  const pMatches = html.match(/<p>.*?<\/p>/g) || [];
  for (const p of pMatches) {
    const aCount = (p.match(/<a /g) || []).length;
    assert.ok(aCount <= 4, `paragraph has ${aCount} links: ${p}`);
  }
});
```

- [ ] **Step 2: Write the content model doc**

`docs/superpowers/runbooks/frp-content-model.md` — references Task 4.0 taxonomy; defines hub-and-spoke linking rules and anchor-text guidelines (descriptive, varied, never exact-match spam).

- [ ] **Step 3: Implement `scripts/frp-content/internal-links.mjs`**

Two exports:
- `insertContextualLinks(html, { maxPerParagraph = 4 })` — scans for known entity mentions (service names, city names) and wraps first occurrence per paragraph with an `<a>` pointing to the right hub or spoke. Respects max-per-paragraph.
- `auditInternalLinks({ includeOrphanCheck })` — crawls published FRP URLs, builds in-degree graph, returns `{ orphans: [], underlinked: [], overlinked: [] }`.

- [ ] **Step 4: Run tests — expect PASS**

- [ ] **Step 5: Run audit against staging — expect zero orphans after re-generation**

- [ ] **Step 6: Commit**

```bash
git add scripts/frp-content/internal-links.mjs docs/superpowers/runbooks/frp-content-model.md tests/internal-links.test.mjs
git commit -m "feat(frp-seo): internal link graph + orphan audit"
```

### Task 4.7: Weekly cron — FRP content pipeline

**Files:**
- Create: `scripts/frp-orchestrator.mjs`
- Create: `docs/superpowers/runbooks/weekly-cron.md`
- Modify: `package.json` (add `"run-frp-weekly"` script)

**Design:** Replaces archived `scripts/orchestrator.js`. Runs Mondays 06:00. Steps: (1) pull this week's newly-active pros; (2) enrich their reviews (Task 4.3); (3) regenerate "Top 5" pages for any city×service where roster changed; (4) rebuild internal-link graph; (5) regenerate sitemap; (6) submit to GSC. No more "generate N new pages per week" quota — quality gate in Task 4.2 means pages are only produced when they'll rank.

- [ ] **Step 1: Write failing orchestrator tests**

```javascript
test('orchestrator regenerates only city×service pairs where pro roster changed', async () => {
  // Seed state: /ca/oakland/water-damage/ was last built yesterday; roster unchanged. Should skip.
  // Seed state: /ca/berkeley/mold-remediation/ has a new pro today. Should rebuild.
  const result = await runFrpWeekly({ dryRun: true });
  assert.ok(!result.rebuilt.includes('/ca/oakland/water-damage/'));
  assert.ok( result.rebuilt.includes('/ca/berkeley/mold-remediation/'));
});

test('orchestrator regenerates sitemap and submits to GSC', async () => {
  const result = await runFrpWeekly({ dryRun: true });
  assert.ok(result.sitemapRegenerated);
  assert.ok(result.gscSubmission);
});
```

- [ ] **Step 2: Implement `scripts/frp-orchestrator.mjs`**

Pipeline:
1. Query FRP: pros activated or modified in the past 7 days → dirty (city, service) pairs.
2. For each dirty pair, run `generateTopContractors` (Task 4.2) — skips automatically if < 3 pros.
3. For each newly-active featured pro, run `generateSpotlight` (Task 4.5).
4. Run `auditInternalLinks` (Task 4.6); repair orphans.
5. Regenerate sitemap.xml.
6. Submit updated URLs to GSC via the indexing API (service account already provisioned — re-point from iRP property to FRP property).
7. Emit a summary log to `logs/frp-weekly-YYYY-MM-DD.json`.

- [ ] **Step 3: Write the runbook**

`docs/superpowers/runbooks/weekly-cron.md` — cron schedule, manual-run command, log location, failure-mode playbook (alert via Make.com webhook on non-zero exit).

- [ ] **Step 4: Add `"run-frp-weekly": "node scripts/frp-orchestrator.mjs"` to package.json**

- [ ] **Step 5: Run tests — expect PASS**

- [ ] **Step 6: Commit**

```bash
git add scripts/frp-orchestrator.mjs docs/superpowers/runbooks/weekly-cron.md package.json tests/frp-orchestrator.test.mjs
git commit -m "feat(frp-seo): weekly orchestrator — enrichment-first, quality-gated"
```

---

## Post-launch checklist (not numbered tasks — release gates)

Before going live to real homeowners:

- [ ] All tests in `tests/` pass against staging
- [ ] At least 10 real contractor applications approved (so "Top 5" pages actually have content)
- [ ] Stripe in live mode; one real test subscription charged + refunded successfully
- [ ] Turnstile configured in production (real sitekey/secret, not test-mode)
- [ ] `FRP_TRUSTED_PROXIES` contains live Cloudflare ranges
- [ ] `FRP_PII_KEY` generated and stored in `wp-config.php` (never in `.env` committed to repo)
- [ ] Make.com scenario tested end-to-end with a real lead
- [ ] PII migration script run on existing leads (if any)
- [ ] Google Search Console property verified for findrestorationpros.com
- [ ] iRP → FRP 301 redirects live at DNS/hosting layer
- [ ] `/contractor/dashboard/` WP page created with shortcode
- [ ] `/join/`, `/lead-status/`, `/services/<service>/` pages created/wired
- [ ] Privacy policy + terms pages updated (directory model, PII storage, Stripe subprocessor)

---

## Review/execution notes

- **Chunk sequencing is mandatory.** Chunks 0→1→2→3→4 in order. Chunk 2 depends on Chunk 1's endpoints being in place. Chunk 4 depends on having real pros (Chunks 1.3, 1.5, 1.7, 1.8).
- **Where full TDD is impractical** (Stripe live flows, email delivery, Turnstile in production): the plan substitutes test-mode integration tests + manual verification checklists. Don't skip the manual checks.
- **All tasks touch files in this repo only.** No changes to iRestorationPros.com — it's been decommissioned. The ops team handles DNS, redirects, and the eventual WP-instance shutdown per the runbook.
- **Two plugins you'll be creating:** `frp-billing`, `frp-dashboard`, `frp-emails`, `frp-security`. Plus extending existing `frp-directory`. Keep them as separate plugins so ops can deactivate/reactivate independently.

---

End of plan.
