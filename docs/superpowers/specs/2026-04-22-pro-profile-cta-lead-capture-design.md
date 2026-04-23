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

## Business Rules

### Listing Tiers

| Tier | Post meta value | Contact behaviour |
|---|---|---|
| Basic | `basic` | Hard gated — phone hidden, form required |
| Paid | `paid` | Accessible — phone visible, call tracked |
| Featured | `featured` | Accessible — phone visible, call tracked |

**Basic pros:** The phone number is never rendered in the page HTML. The only contact action is the "Request Service" modal form. Every contact attempt is captured as an `frp_lead`.

**Paid / Featured pros:** Phone number is visible in the sidebar. "Call Now" button routes through the existing `/frp/v1/call` tracking endpoint (logs the lead event to Make.com webhook) before forwarding to `tel:`. A "Request Quote" form modal is also available as a secondary action. Every call attempt is logged regardless of whether a form is submitted.

**Upgrade incentive:** Basic pros can be shown a small note: *"Upgrade your listing so customers can reach you directly."* This surfaces the value of upgrading without being aggressive.

---

## Lead Schema Extension

Three new fields added to the `frp_lead` post type and `/frp/v1/leads` endpoint:

| Field | Meta key | Type | Required | Notes |
|---|---|---|---|---|
| Email | `contact_email` | string | Yes (profile form) | Validated as email address |
| Street address | `property_address` | string | No | Sanitized text, complements ZIP |
| Preferred pro | `preferred_pro_id` | integer | No | Stores pro post ID; bypasses matcher |

When `preferred_pro_id` is set, the lead is pre-assigned to that pro. The Make.com webhook receives the full lead payload including the preferred pro ID and handles direct notification to that pro — no matching algorithm needed.

---

## UI / UX

### Desktop — Right Sidebar

**Basic tier:**
```
┌─────────────────────────────┐
│  Request Service            │
│  [Pro Name] is ready to help│
│                             │
│  [ Request Service → ]      │  ← opens modal
│                             │
│  Upgrade to get direct calls│  ← subtle upsell link
└─────────────────────────────┘
```

**Paid / Featured tier:**
```
┌─────────────────────────────┐
│  Contact & Coverage         │
│                             │
│  📞 (555) 555-5555          │  ← visible phone
│  [ Call Now ]               │  ← routes through /frp/v1/call
│                             │
│  [ Request Quote ]          │  ← opens modal (secondary)
│                             │
│  📍 Address                 │
│  🌐 Website                 │
└─────────────────────────────┘
```

### Modal Form (both tiers)

Triggered by "Request Service" (basic) or "Request Quote" (paid/featured). Full-screen overlay on mobile, centred modal on desktop.

**Fields:**
1. Phone (required)
2. Email (required)
3. ZIP code (required, pre-filled if available from session)
4. Street address (optional)
5. Service type (required, pre-filled to pro's service if they offer only one; dropdown if multiple)
6. Urgency — Now / Within 24 hrs / Within a week (required)
7. Property type — Residential / Commercial (required)
8. Has insurance — Yes / No / Not sure (required)

**Submission:**
- POST to `/wp-json/frp/v1/leads` with `source=profile_form` and `preferred_pro_id=[pro post ID]`
- On success: modal closes, sidebar shows confirmation state: *"Request sent — [Pro Name] will be in touch soon."*
- On error: inline error message, form stays open

**Token:** Uses the existing `X-FRP-Lead-Token` mechanism (fetched from `window.FRP_LEAD_TOKEN`) for request authentication — no change to existing security model.

### Mobile Sticky Bar

**Basic tier:** Sticky bottom bar replaced with "Get Help Now" → opens modal form.

**Paid / Featured tier:** Existing sticky "Call Now" bar retained, routes through `/frp/v1/call` tracking endpoint.

### "Get Help Now" Header Button

Unchanged — remains as the global fallback into the main guided intake wizard for all tiers.

---

## Technical Changes

### `wordpress-plugins/frp-directory.php`

1. **Register new meta keys** in `frp_register_lead_meta()`:
   - `contact_email` — type string, single, not shown in REST publicly
   - `property_address` — type string, single, not shown in REST publicly
   - `preferred_pro_id` — type integer, single, not shown in REST publicly

2. **Extend `/frp/v1/leads` endpoint** (`frp_create_lead_handler`):
   - Accept `contact_email` — validate with `is_email()`, return 400 if invalid when provided
   - Accept `property_address` — sanitize with `sanitize_text_field()`
   - Accept `preferred_pro_id` — cast to int, verify post exists and is `restoration_pro` type, store on lead meta
   - `contact_email` is required when `source=profile_form` (not required for existing guided flow or other sources — backwards compatible)

### `wordpress-plugins/frp-pro-template.php`

1. **Read `listing_tier`** post meta at template load time.

2. **Tier gate logic** in Contact & Coverage sidebar:
   - If `basic` (or empty/unset — treat unknown as basic): render "Request Service" button, hide phone
   - If `paid` or `featured`: render phone number, "Call Now" button (href = `/wp-json/frp/v1/call?company={id}&source=profile&path=profile`), "Request Quote" button

3. **Upsell note for basic:** Small text link below the form button — *"Upgrade your listing"* — links to a `/pricing/` page (or `#` placeholder until pricing page exists).

4. **Modal HTML:** Injected once at bottom of template, hidden by default. Contains the full 8-field form. JS handles open/close, fetch submission, success/error states.

5. **Mobile sticky bar:** Conditional on tier — basic gets "Get Help Now" → modal; paid/featured retain existing "Call Now" → `/frp/v1/call`.

---

## Out of Scope

- **Per-lead billing triggers** — `preferred_pro_id` is stored but no charge is fired on lead creation. This is Phase 2 once the Stripe webhook + contractor dashboard are live (Tasks 1.7–1.8).
- **Turnstile captcha** — Added in Task 2.1. Profile form uses existing `X-FRP-Lead-Token` mechanism in the interim.
- **Pro notification system** — Make.com webhook already receives the full lead payload. Routing the notification to the specific pro via `preferred_pro_id` is a Make.com workflow configuration, not a code change.
- **Email confirmation to homeowner** — Phase 2, requires email infrastructure.
- **Plumber CPT profile pages** — Plumbers are a container-only CPT for now; this design applies to `restoration_pro` only.

---

## Verification (Manual)

- Basic pro profile: phone not visible in page source, "Request Service" button opens modal, form submits successfully, lead created in WP Admin with correct meta
- Paid/Featured pro profile: phone visible, "Call Now" routes through `/frp/v1/call` endpoint (check Make.com webhook log), "Request Quote" opens modal
- `preferred_pro_id` stored on lead: check via WP Admin → Leads → lead post meta
- Mobile basic: sticky bar shows "Get Help Now", opens modal
- Mobile paid: sticky bar shows "Call Now", routes through tracking
- Backwards compatibility: existing guided flow (`source=guided_flow`) unaffected by new required `contact_email` for `profile_form` source only
