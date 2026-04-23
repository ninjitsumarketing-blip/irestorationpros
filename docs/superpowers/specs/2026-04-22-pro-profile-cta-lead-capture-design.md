# Pro Profile CTA & Lead Capture Design

**Date:** 2026-04-22
**Status:** Draft (third spec review pass in progress)

---

## Goal

Redesign the pro profile page CTA hierarchy to funnel visitors through FRP's lead capture system rather than allowing untracked direct contact. Gate direct phone access by listing tier, giving paid/featured pros a clear upgrade benefit while ensuring FRP captures and tracks every contact attempt.

---

## Background

The current profile page exposes the pro's phone number as the primary contact action. Users who call directly bypass FRP's system — no lead record is created, no conversion is tracked, and no per-lead revenue is captured. This design replaces that with a tier-gated model where the contact path depends on the pro's subscription level.

**Revenue model:** FRP charges pros both a monthly subscription (by tier) and per lead (Phase 2). The profile page is a key lead capture point.

---

## Tier Vocabulary

The `listing_tier` post meta on `restoration_pro` posts uses these stored values in the live database:

| UI name | Meta value stored in DB | Contact behaviour |
|---|---|---|
| Free / Unsubscribed | `free` (or empty string / not set) | Hard gated — phone hidden, form required |
| Basic | `basic` | Hard gated — phone hidden, form required |
| Paid | `paid` | Accessible — phone visible, call tracked |
| Featured | `featured` | Accessible — phone visible, call tracked |
| Premium (legacy label) | `premium` | Accessible — phone visible, call tracked |

**Gating rule:** Any `listing_tier` value that is NOT `paid`, `featured`, or `premium` is treated as gated. This means `free`, `basic`, empty string, and any unknown value all result in the hard gate. The template should use a whitelist approach: `if (in_array($tier, ['paid', 'featured', 'premium']))` shows the phone; otherwise gates it.

**Note on tier vocabulary:** The billing plugin (`frp-billing.php`) uses `basic/paid/featured` as Stripe price IDs. The profile template reads the `listing_tier` post meta which currently uses `free` for new unsubscribed pros. When Task 1.7 (Stripe webhook) is built, it will write the appropriate value (`basic`, `paid`, or `featured`) to `listing_tier` on checkout completion. Until then, most pros will have `listing_tier = 'free'` and will be hard gated.

---

## Business Rules

**Free / Basic pros (gated):**
- Phone number is **never rendered in the page HTML** — not even in a hidden element
- Phone number must also be omitted from the JSON-LD schema. Output the `LocalBusiness` schema inline via a `<script type="application/ld+json">` tag using `wp_head` (or directly in the template `<head>`). Only include the `telephone` key when `$is_accessible` is true. Minimum required fields:
  ```php
  $schema = [
      '@context' => 'https://schema.org',
      '@type'    => 'LocalBusiness',
      'name'     => get_the_title( $pro_id ),
      'url'      => get_permalink( $pro_id ),
  ];
  if ( $is_accessible && $phone ) {
      $schema['telephone'] = $phone;
  }
  echo '<script type="application/ld+json">' . wp_json_encode( $schema ) . '</script>';
  ```
- The only contact action is the "Request Service" modal form
- Every contact attempt creates an `frp_lead` record
- A subtle upsell note is shown: *"Upgrade your listing so customers can reach you directly"*

**Paid / Featured / Premium pros (accessible):**
- Phone number is visible in the Contact & Coverage sidebar
- "Call Now" button routes through the existing `/frp/v1/call` tracking endpoint, then forwards to `tel:[phone]`
- A "Request Quote" modal form is also available as a secondary action
- Every call click is logged regardless of whether a form is submitted

---

## Lead Schema Extension

Three new fields added to the `frp_lead` post type and `/frp/v1/leads` endpoint:

| Field | Meta key | Type | Required | Notes |
|---|---|---|---|---|
| Email | `lead_contact_email` | string | Yes when `source=profile_form` | Named `lead_contact_email` to avoid confusion with `contact_email` on `restoration_pro` posts (which stores the pro's billing email). Validated with `is_email()`. |
| Street address | `property_address` | string | No | Sanitized text, complements ZIP. Note: `lead_address` is already registered in `frp_register_lead_meta()` but is never written by any current handler. `property_address` is a new, distinct key used exclusively by the profile form. Do not reuse `lead_address`. |
| Preferred pro | `preferred_pro_id` | integer | **Yes** for `source=profile_form` | Stores pro post ID; signals direct assignment. Return 400 if absent or if `get_post_type($id) !== 'restoration_pro'`. |

**Important — `contact_email` naming:** The `restoration_pro` post type already uses a `contact_email` meta key for the pro's billing/contact email. The lead meta key for the homeowner's email is deliberately named `lead_contact_email` to avoid confusion for future maintainers and Make.com webhook consumers.

**`preferred_pro_id` behaviour:** When set, this field signals that the lead came from a specific pro's profile page. The Make.com webhook receives this value in the payload and handles direct notification to that pro — no matching algorithm is invoked. The existing matcher is bypassed for these leads.

**Rate limiting for `profile_form`:** A homeowner may legitimately submit to several pros in one session (e.g., requesting quotes from 3 pros). `profile_form` submissions must use a **separate rate-limit bucket** (`lead_create_profile`) with a higher threshold: **10 submissions per 15 minutes per IP**.

**Implementation:** The existing unconditional `frp_check_rate_limit('lead_create', 3, ...)` call at line 1072 of `frp_lead_create_handler` must be **deleted entirely**. In its place, insert the following conditional block **after the `$source` assignment block at lines 1083–1084** (because `$source` must exist before the conditional can run), **before the `$is_emergency` assignment**:
```php
// Rate limiting — separate buckets by source
$rl_bucket = ( $source === 'profile_form' ) ? 'lead_create_profile' : 'lead_create';
$rl_limit  = ( $source === 'profile_form' ) ? 10 : 3;
if ( ! frp_check_rate_limit( $rl_bucket, $rl_limit, 15 * MINUTE_IN_SECONDS ) ) {
    return new WP_Error( 'rate_limited', 'Too many requests. Please try again shortly.', [ 'status' => 429 ] );
}
```

**Duplicate detection for `profile_form`:** For `source=profile_form`, bypass the existing 24-hour phone/email duplicate detection entirely. Each pro-specific form submission is an independent intent signal — the same homeowner submitting to two different pros' profile pages must generate two separate `frp_lead` records.

The bypass is inserted at step 5 in `frp_lead_create_handler` by wrapping the duplicate check in a source condition:
```php
// 5. Duplicate detection (skip for profile_form — each pro-specific submission is independent)
if ( $source !== 'profile_form' ) {
    $dup_id = frp_find_duplicate_lead( $phone, $email );
    if ( $dup_id ) {
        // ... existing duplicate return logic ...
    }
}
```

**`lead_email` dual-write and POST param reconciliation:** The modal form POSTs the homeowner's email under the key `lead_contact_email`. The handler must read it via `$request->get_param('lead_contact_email')` (not `email`) for `profile_form` submissions. After reading and validating, store the value in *both* `lead_contact_email` (new meta key) and `lead_email` (the existing meta key). Also pass it as `email` in the `$lead_data` array given to `frp_fire_lead_webhook()`, so the Make.com webhook payload remains consistent with all other sources. For non-`profile_form` sources, the handler continues to read `email` as before.

**Dispatch result for `preferred_pro_id` leads:** When `preferred_pro_id` is present and valid, skip `frp_find_dispatch_pros()`. The existing dispatch block at step 11 in `frp_lead_create_handler` conditionally calls `frp_find_dispatch_pros()` — extend that condition to also skip for `profile_form`:
```php
if ( $zip && $source !== 'followup_modal' && $source !== 'profile_form' ) {
    $dispatch_result = frp_find_dispatch_pros( $zip, $service );
}
```

For `profile_form`, construct the dispatch result with the preferred pro's contact details (fetched from post meta) so that `frp_fire_lead_webhook()` can populate the `dispatched_pros` array in the Make.com payload (the webhook builder reads `name`, `dispatch_phone`, and `dispatch_email` from each pro in `$dispatch_result['pros']`).

**Exact placement in step 11 — the correct ordering is:**

1. The default `$dispatch_result` initialization at line 1220 stays as the first line of step 11.
2. The `if ($zip && $source !== 'followup_modal')` condition is extended to also exclude `profile_form`:
   ```php
   if ( $zip && $source !== 'followup_modal' && $source !== 'profile_form' ) {
       $dispatch_result = frp_find_dispatch_pros( $zip, $service );
   }
   ```
3. Immediately after that `if` block (after line 1222), insert the `profile_form` dispatch-result override:
   ```php
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
4. The existing `update_post_meta( $post_id, 'lead_assigned_pros', wp_json_encode( array_column( $dispatch_result['pros'], 'post_id' ) ) )` at line 1225 runs unchanged — for `profile_form` it correctly stores `wp_json_encode([$preferred_pro_id])`.

**Step-10 `lead_assigned_pros` interaction:** The existing step-10 block (lines 1212–1214) writes `lead_assigned_pros` only when `$assigned_pro` (the `followup_modal` field) is nonzero. For `profile_form`, `$assigned_pro` will be 0 (the form does not send `assigned_pro`), so the step-10 block does not fire. There is no conflict.

`lead_assigned_pros` is always stored as `wp_json_encode([...ids...])` — never a plain integer string.

---

## Source Allowlist Change

The existing `frp_lead_create_handler` has a strict `$valid_sources` allowlist:
```php
$valid_sources = [ 'guided_flow', 'followup_modal', 'emergency_flow' ];
```

**Required change:** Add `'profile_form'` to this allowlist. Without this, `source=profile_form` is silently coerced to `guided_flow` and the `contact_email` required-validation rule is unreachable.

---

## Email Validation Behaviour Change

The existing handler silently clears invalid emails rather than rejecting them:
```php
if ( $email && ! is_email( $email ) ) {
    $email = ''; // silently clear
}
```

**Required change for `profile_form` only:** When `source=profile_form`, return HTTP 400 if `lead_contact_email` is missing or fails `is_email()` validation. For all other sources (`guided_flow`, `followup_modal`, `emergency_flow`), retain the existing silent-clear behaviour for backwards compatibility.

**Validation order and `$email` variable assignment:** Phone validation runs first (existing line 1108 check). Immediately after (still inside step 3 of the handler, before the duplicate check at step 5), add the following block to overwrite the `$email` variable for `profile_form`:
```php
// profile_form sends email under 'lead_contact_email' key; overwrite $email for downstream use
if ( $source === 'profile_form' ) {
    $email = sanitize_email( $request->get_param( 'lead_contact_email' ) ?? '' );
    if ( ! $email || ! is_email( $email ) ) {
        return new WP_Error( 'bad_request', 'A valid email address is required.', [ 'status' => 400 ] );
    }
}
```
This overwrites (not supplements) the `$email` variable initially set at line 1099 from `$request->get_param('email')`. After this block, `$email` holds the validated homeowner email for `profile_form` submissions. All downstream code (duplicate detection skip, `lead_email` meta write, webhook `$lead_data['email']`) operates on this same `$email` variable — no additional variable is needed.

---

## UI / UX

### Desktop — Right Sidebar

**Gated tier (free / basic / unknown):**
```
┌─────────────────────────────┐
│  Request Service            │
│  [Pro Name] is ready to help│
│                             │
│  [ Request Service → ]      │  ← opens modal
│                             │
│  Upgrade your listing →     │  ← links to home_url('/pricing/')
└─────────────────────────────┘
```

**Accessible tier (paid / featured / premium):**
```
┌─────────────────────────────┐
│  Contact & Coverage         │
│                             │
│  📞 (555) 555-5555          │  ← visible phone number
│  [ Call Now ]               │  ← /frp/v1/call?company=[post_id]&source=profile&path=profile
│                             │
│  [ Request Quote ]          │  ← opens modal (secondary)
│                             │
│  📍 Address                 │
│  🌐 Website                 │
└─────────────────────────────┘
```

Note: `[post_id]` in the Call Now href is the WordPress integer post ID of the `restoration_pro` post. The existing `/frp/v1/call` handler reads this as `$company_id = $request->get_param('company')` and uses it to look up the phone number via `get_post_meta($company_id, 'phone', true)`.

**"Call Now" button interaction model:** The button is a plain `<a href="...">` anchor — no JavaScript fetch. The browser navigates to the `/frp/v1/call` endpoint, which logs the click to Make.com and issues an HTTP 302 redirect to `tel:[phone]`. The OS then opens the phone dialer. No JavaScript is required for this flow.

### Modal Form (both tiers)

Triggered by "Request Service" (gated) or "Request Quote" (accessible). Full-screen overlay on mobile, centred modal on desktop.

**Fields and POST values:**

| Label | POST param | Required | Notes |
|---|---|---|---|
| Phone | `phone` | Yes | |
| Email | `lead_contact_email` | Yes | Validated server-side; 400 on invalid |
| ZIP code | `zip` | Yes (client-side only) | ZIP remains server-side optional for `profile_form` (same as all other sources — the existing handler at line 1114 only validates format if present). The modal enforces it client-side via the `required` attribute. No pre-fill from session — leave blank by default (see Out of Scope). No server-side changes to ZIP handling are needed. |
| Street address | `property_address` | No | |
| Service type | `service` | Yes | Read from pro's `services` meta (comma-separated string, e.g. `"water-damage,mold-remediation"`). **Single service:** render a read-only `<input type="text">` displaying the human-readable service label alongside a `<input type="hidden" name="service" value="[slug]">` — the user sees what they are requesting but cannot change it. **Multiple services:** show a `<select name="service">` dropdown listing all of the pro's service values. **Empty/absent `services` meta:** show a `<select name="service">` dropdown of all 7 valid values with a blank prompt (`<option value="">Select a service…</option>`) as the first option. Valid slugs: `water-damage`, `mold-remediation`, `fire-damage`, `storm-damage`, `sewage-cleanup`, `structural`, `biohazard-cleanup` |
| Urgency | `urgency` | Yes | UI: "Right now" → POST: `now`; "Within 24 hours" → POST: `24hrs`; "Within a week" → POST: `older` |
| Property type | `property_type` | Yes | UI: "Residential" → POST: `residential`; "Commercial" → POST: `commercial` |
| Has insurance | `has_insurance` | Yes | UI: "Yes" → POST: `yes`; "No" → POST: `no`; "Not sure" → POST: `not-sure` |

Additional hidden fields sent with every submission:
- `source` = `profile_form`
- `preferred_pro_id` = the `restoration_pro` post ID

**Submission behaviour:**
- Submit button is **disabled immediately on first click** and re-enabled only on error response. This prevents double-submission on mobile and slow connections.
- POST to `/wp-json/frp/v1/leads` with `X-FRP-Lead-Token: window.FRP_LEAD_TOKEN` header. The fetch call must include this header explicitly:
  ```javascript
  fetch('/wp-json/frp/v1/leads', {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'X-FRP-Lead-Token': window.FRP_LEAD_TOKEN,
    },
    body: JSON.stringify(formData),
  });
  ```
- On success (HTTP 200): modal closes, JavaScript replaces the inner HTML of `<div id="frp-profile-cta-sidebar">` with: *"Request sent — [Pro Name] will be in touch soon."*
- On error (HTTP 4xx/5xx): inline error message shown below the form, form stays open, submit button re-enabled. Display the server's `message` field from the JSON response body if present; otherwise fall back to: *"Something went wrong. Please try again."*

**Token:** `window.FRP_LEAD_TOKEN` is injected into the page via the `wp_head` hook in `frp-directory.php`. The profile template calls `wp_head()`, so the token is available on profile pages without any additional wiring. Do not attempt to inject the token manually — it is already emitted globally.

### Mobile Sticky Bar

Since `frp-pro-template.php` is a new file, the sticky bar must be built from scratch. Use this structure:

**Gated tier:**
```html
<div id="frp-profile-sticky-bar" class="frp-sticky-bar">
  <button type="button" id="frp-sticky-request-btn">Request Service</button>
</div>
```
CSS: `position: fixed; bottom: 0; left: 0; right: 0; z-index: 999; background: #fff; padding: 12px 16px; box-shadow: 0 -2px 8px rgba(0,0,0,.12);`. Visible on mobile only (`display:none` on screens ≥ 768 px). Tapping the button opens the modal (same JavaScript that handles the sidebar "Request Service" button). Label is "Request Service" — **not** "Get Help Now" (which is the header button) and not "Call Now".

**Accessible tier:**
```html
<div id="frp-profile-sticky-bar" class="frp-sticky-bar">
  <a href="<?php echo esc_url( rest_url('frp/v1/call') ); ?>?company=<?php echo $pro_id; ?>&source=profile&path=profile">
    Call Now
  </a>
</div>
```
Same CSS. Routes through `/frp/v1/call` endpoint (302 → `tel:[phone]`).

### "Get Help Now" Header Button

Unchanged for all tiers — remains as the global entry point into the main guided intake wizard.

---

## Technical Changes

### `wordpress-plugins/frp-directory.php`

1. **Add `profile_form` to `$valid_sources` allowlist** in `frp_lead_create_handler`.

2. **Register new meta keys** in `frp_register_lead_meta()`:
   - `lead_contact_email` — type string, single, not shown in REST publicly
   - `property_address` — type string, single, not shown in REST publicly
   - `preferred_pro_id` — type integer, single, not shown in REST publicly

3. **Extend `frp_lead_create_handler` — step 3 (input reading) additions:**

   In step 3, immediately after the `$assigned_pro` read at line 1105, add:
   ```php
   $preferred_pro_id = absint( $request->get_param( 'preferred_pro_id' ) ?? 0 );
   $property_address = sanitize_text_field( $request->get_param( 'property_address' ) ?? '' );
   ```

   Then, still in step 3, add the `profile_form` email override and `preferred_pro_id` validation (see Email Validation Behaviour Change section). For `preferred_pro_id`, the "absent" condition is `$preferred_pro_id === 0` (since `absint('')` and `absint(null)` both return `0`):
   ```php
   if ( $source === 'profile_form' ) {
       if ( ! $preferred_pro_id || get_post_type( $preferred_pro_id ) !== 'restoration_pro' ) {
           return new WP_Error( 'bad_request', 'A valid pro ID is required.', [ 'status' => 400 ] );
       }
   }
   ```

   **`$meta_map` additions (step 10):** Add the following keys to the `$meta_map` array inside the step-10 block:
   ```php
   'property_address'  => $property_address,
   'lead_contact_email' => $email, // for profile_form, $email has been overwritten with the validated lead_contact_email value
   ```
   `lead_contact_email` is added unconditionally — for non-`profile_form` sources it will store an empty string, which is acceptable. The existing `'lead_email' => $email` line already handles the `lead_email` write; adding `lead_contact_email` is the only new line needed.

4. **Extend `frp_fire_lead_webhook` payload:** Two changes are needed:

   **At the call site** in `frp_lead_create_handler` (lines 1231–1241), add `preferred_pro_id` and `property_address` to the `$lead_data` array:
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
   **Inside `frp_fire_lead_webhook`** (do not change the function signature — only add to the `$payload` array body), add these two keys:
   ```php
   'preferred_pro_id' => $lead_data['preferred_pro_id'] ?? 0,
   'property_address' => $lead_data['property_address'] ?? '',
   ```
   The Make.com webhook will receive them as `preferred_pro_id` (integer, `0` for non-`profile_form` leads — Make.com should filter on `preferred_pro_id > 0` or `source === 'profile_form'` to identify direct-assignment leads) and `property_address` (string) in the payload.

5. **Update admin meta box label for `listing_tier`**: in `frp-directory.php`, search for the string `'Tier — free / featured / premium'` (note: uses an em-dash U+2014, not a hyphen) — it appears in the `$sections` array inside the meta box rendering callback at approximately line 2161. Change the label to `'Tier — free / basic / paid / featured / premium'` to reflect all five valid values.

### `wordpress-plugins/frp-pro-template.php`

**File status: new file to be created.** `frp-pro-template.php` does not currently exist in the repository. Implement it as a **WordPress mu-plugin** (deployed alongside `frp-directory.php` in `wp-content/mu-plugins/`). It hooks into `template_include` to serve the profile template for `restoration_pro` single posts, following the same mu-plugin pattern as `frp-directory.php`. Example hook:
```php
add_filter( 'template_include', function( $template ) {
    if ( is_singular( 'restoration_pro' ) ) {
        return __DIR__ . '/frp-pro-template-view.php'; // separate view file for the HTML
    }
    return $template;
} );
```
All pro profile template logic described in this spec goes into this new file (and its view file).

1. **Read `listing_tier`** at template load: `$tier = get_post_meta($pro_id, 'listing_tier', true) ?: 'free';`

2. **Tier gate — whitelist approach:**
   ```php
   $is_accessible = in_array($tier, ['paid', 'featured', 'premium'], true);
   ```

3. **Contact & Coverage sidebar — conditional rendering:**
   - If `!$is_accessible`: render "Request Service" button (opens modal), upsell note linking to `home_url('/pricing/')`, **no phone rendered**
   - If `$is_accessible`: render phone number, "Call Now" `<a>` anchor with `href` generated via PHP as `esc_url( rest_url('frp/v1/call') ) . '?company=' . $pro_id . '&source=profile&path=profile'` — use `rest_url()`, not a hardcoded `/wp-json/` prefix; "Request Quote" button (opens modal)

4. **Modal HTML:** Injected once at bottom of template, hidden by default (`display:none`). Contains the full 8-field form. JavaScript handles:
   - Open/close (triggered by both "Request Service" and "Request Quote" buttons)
   - Submit with button-disable guard
   - Fetch POST to `/wp-json/frp/v1/leads`
   - Success state: close modal, update sidebar HTML
   - Error state: show inline message, re-enable button

5. **Mobile sticky bar:** Conditional on `$is_accessible` — gated gets "Request Service" → modal; accessible retains existing "Call Now" → `/frp/v1/call`.

6. **Upsell link:** Use `home_url('/pricing/')` (not a hardcoded `/pricing/` string) so it works on any WordPress install configuration.

---

## Out of Scope

- **Per-lead billing triggers** — `preferred_pro_id` stored but no charge fired on lead creation. Phase 2 when Stripe webhook + contractor dashboard are live (Tasks 1.7–1.8).
- **Turnstile captcha** — Task 2.1. Profile form uses existing `X-FRP-Lead-Token` in the interim.
- **Email confirmation to homeowner** — Phase 2, requires email infrastructure.
- **Make.com workflow changes** — Routing notification to the specific pro via `preferred_pro_id` is a Make.com configuration task, not a code change in this spec.
- **Plumber CPT profile pages** — Applies to `restoration_pro` only.
- **`pricing/` page content** — The upsell link destination. Linked as a placeholder; page content is a separate task.
- **ZIP pre-fill from session** — The guided intake wizard stores ZIP via an internal mechanism not part of this spec. Pre-filling ZIP from session/storage in the profile modal is a future enhancement. Leave the ZIP field blank by default.

---

## Verification (Manual)

**Gated tier (free/basic pro):**
- Phone number NOT present anywhere in page source (view-source confirms) — including the `application/ld+json` block in `<head>` (no `telephone` field present)
- "Request Service" button visible in sidebar, opens modal
- Modal form has all 8 fields; submit button disables on click
- Valid form submission: lead created in WP Admin with `source=profile_form`, `preferred_pro_id` set, `lead_contact_email` stored. To confirm private meta fields: in WP Admin → open the `frp_lead` post → Screen Options → enable "Custom Fields" panel → verify `lead_contact_email` and `property_address` appear with correct values
- Missing email → server returns 400, form shows inline error, button re-enables
- Invalid email format → server returns 400, form shows inline error
- Submit with an invalid `preferred_pro_id` (non-existent post ID, or wrong post type) → server returns 400
- Submit form with `property_address` populated → confirm value stored on `frp_lead` post meta and present as `property_address` in Make.com webhook payload
- In Make.com webhook log for a `profile_form` submission: confirm `preferred_pro_id` is present (integer), and `dispatched_pros` array contains exactly one entry with the pro's `business_name`, `phone`, and `contact_email`
- Mobile: sticky "Request Service" bar opens modal
- Upsell link present and resolves to pricing page URL

**Accessible tier (paid/featured/premium pro):**
- Phone number visible in sidebar
- "Call Now" button routes through `/frp/v1/call` endpoint (confirm Make.com webhook log receives the event)
- "Request Quote" button opens same modal
- Mobile: sticky "Call Now" bar present and routes through tracking

**Backwards compatibility:**
- Existing guided flow (`source=guided_flow`) still accepts missing email without returning 400
- Pro posts with `listing_tier = 'free'` (majority of live pros) correctly trigger the hard gate
