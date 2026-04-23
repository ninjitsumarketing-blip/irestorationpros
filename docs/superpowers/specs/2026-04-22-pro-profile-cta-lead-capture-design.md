# Pro Profile CTA & Lead Capture Design

**Date:** 2026-04-22
**Status:** Draft (pending spec review)

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
| Street address | `property_address` | string | No | Sanitized text, complements ZIP |
| Preferred pro | `preferred_pro_id` | integer | No | Stores pro post ID; signals direct assignment |

**Important — `contact_email` naming:** The `restoration_pro` post type already uses a `contact_email` meta key for the pro's billing/contact email. The lead meta key for the homeowner's email is deliberately named `lead_contact_email` to avoid confusion for future maintainers and Make.com webhook consumers.

**`preferred_pro_id` behaviour:** When set, this field signals that the lead came from a specific pro's profile page. The Make.com webhook receives this value in the payload and handles direct notification to that pro — no matching algorithm is invoked. The existing matcher is bypassed for these leads.

---

## Source Allowlist Change

The existing `frp_create_lead_handler` has a strict `$valid_sources` allowlist:
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
│  [ Call Now ]               │  ← /frp/v1/call?company=[post_id]&...
│                             │
│  [ Request Quote ]          │  ← opens modal (secondary)
│                             │
│  📍 Address                 │
│  🌐 Website                 │
└─────────────────────────────┘
```

Note: `[post_id]` in the Call Now href is the WordPress integer post ID of the `restoration_pro` post. The existing `/frp/v1/call` handler reads this as `$company_id = $request->get_param('company')` and uses it to look up the phone number via `get_post_meta($company_id, 'phone', true)`.

### Modal Form (both tiers)

Triggered by "Request Service" (gated) or "Request Quote" (accessible). Full-screen overlay on mobile, centred modal on desktop.

**Fields and POST values:**

| Label | POST param | Required | Notes |
|---|---|---|---|
| Phone | `phone` | Yes | |
| Email | `lead_contact_email` | Yes | Validated server-side; 400 on invalid |
| ZIP code | `zip` | Yes | Pre-filled from session if available |
| Street address | `property_address` | No | |
| Service type | `service` | Yes | Read from pro's `services` meta (comma-separated string, e.g. `"water-damage,mold-remediation"`). Pre-select if only one value; show dropdown of all values if multiple. Valid values: `water-damage`, `mold-remediation`, `fire-damage`, `storm-damage`, `sewage-cleanup`, `structural`, `biohazard-cleanup` |
| Urgency | `urgency` | Yes | UI: "Right now" → POST: `now`; "Within 24 hours" → POST: `24hrs`; "Within a week" → POST: `older` |
| Property type | `property_type` | Yes | UI: "Residential" → POST: `residential`; "Commercial" → POST: `commercial` |
| Has insurance | `has_insurance` | Yes | UI: "Yes" → POST: `yes`; "No" → POST: `no`; "Not sure" → POST: `unknown` |

Additional hidden fields sent with every submission:
- `source` = `profile_form`
- `preferred_pro_id` = the `restoration_pro` post ID

**Submission behaviour:**
- Submit button is **disabled immediately on first click** and re-enabled only on error response. This prevents double-submission on mobile and slow connections.
- POST to `/wp-json/frp/v1/leads` with `X-FRP-Lead-Token: [window.FRP_LEAD_TOKEN]` header
- On success (HTTP 200): modal closes, sidebar replaces CTA with: *"Request sent — [Pro Name] will be in touch soon."*
- On error (HTTP 4xx/5xx): inline error message shown below the form, form stays open, submit button re-enabled

**Token:** Uses the existing `window.FRP_LEAD_TOKEN` mechanism embedded in the page — no change to security model.

### Mobile Sticky Bar

**Gated tier:** Existing sticky "Call Now" bar replaced with a "Request Service" sticky bar — same fixed-bottom style, opens the modal form on tap. Label is "Request Service" (not "Get Help Now") to distinguish it from the header button.

**Accessible tier:** Existing sticky "Call Now" bar retained, routes through `/frp/v1/call` tracking endpoint.

### "Get Help Now" Header Button

Unchanged for all tiers — remains as the global entry point into the main guided intake wizard.

---

## Technical Changes

### `wordpress-plugins/frp-directory.php`

1. **Add `profile_form` to `$valid_sources` allowlist** in `frp_create_lead_handler`.

2. **Register new meta keys** in `frp_register_lead_meta()`:
   - `lead_contact_email` — type string, single, not shown in REST publicly
   - `property_address` — type string, single, not shown in REST publicly
   - `preferred_pro_id` — type integer, single, not shown in REST publicly

3. **Extend `frp_create_lead_handler`:**
   - Accept `lead_contact_email`: when `source=profile_form`, required and validated with `is_email()` — return 400 if missing or invalid. For all other sources, retain existing silent-clear behaviour.
   - Accept `property_address`: sanitize with `sanitize_text_field()`, store on lead
   - Accept `preferred_pro_id`: cast to `absint()`, verify `get_post_type($id) === 'restoration_pro'`, store on lead meta. Return 400 if provided but invalid.

4. **Extend `frp_fire_lead_webhook` payload** to include `preferred_pro_id` and `property_address` in the data array passed to Make.com. These are new fields not currently in the payload — this is a required code change, not already done.

### `wordpress-plugins/frp-pro-template.php`

1. **Read `listing_tier`** at template load: `$tier = get_post_meta($pro_id, 'listing_tier', true) ?: 'free';`

2. **Tier gate — whitelist approach:**
   ```php
   $is_accessible = in_array($tier, ['paid', 'featured', 'premium'], true);
   ```

3. **Contact & Coverage sidebar — conditional rendering:**
   - If `!$is_accessible`: render "Request Service" button (opens modal), upsell note linking to `home_url('/pricing/')`, **no phone rendered**
   - If `$is_accessible`: render phone number, "Call Now" button with href `/wp-json/frp/v1/call?company=[post_id]&source=profile&path=profile`, "Request Quote" button (opens modal)

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

---

## Verification (Manual)

**Gated tier (free/basic pro):**
- Phone number NOT present anywhere in page source (view-source confirms)
- "Request Service" button visible in sidebar, opens modal
- Modal form has all 8 fields; submit button disables on click
- Valid form submission: lead created in WP Admin with `source=profile_form`, `preferred_pro_id` set, `lead_contact_email` stored
- Missing email → server returns 400, form shows inline error, button re-enables
- Invalid email format → server returns 400, form shows inline error
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
