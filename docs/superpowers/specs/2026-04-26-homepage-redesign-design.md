# Homepage Redesign Design Spec

**Date:** 2026-04-26
**Status:** Approved

---

## Goal

Replace the current search-bar homepage with a dispatch-model homepage that accurately reflects how FindRestorationPros works: homeowners describe their problem, the system matches and routes them to a qualified local contractor. Remove the implied browse/Yelp-style experience. Wire the inline wizard directly into the existing `POST /frp/v1/leads` REST endpoint (`source: guided_flow`).

---

## Problem Statement

The current homepage (`stitch-html/frp-home.html`) presents a text search bar ("Find local restoration experts...") with a "Search Pros" CTA — implying a browse-and-pick model. The actual system is a dispatch/matching model: homeowners submit structured intake data and are algorithmically routed to a qualified pro. This mismatch creates confusion and undersells the platform's value proposition.

Additionally, the current trust bar makes blanket platform-level claims ("IICRC Certified", "Background Checked") that cannot be guaranteed for every pro. The new design removes those claims from the homepage entirely, reserving credential display for individual pro cards where they are earned per-listing.

---

## Approach

**Approach 1: Updated stitch HTML design + new `frp-homepage.php` shortcode (selected)**

- Redesign `stitch-html/frp-home.html` with new copy, layout, and inline wizard UI
- Implement `wordpress-plugins/frp-homepage.php` as a mu-plugin exposing `[frp_home_wizard]` shortcode
- The shortcode renders the full homepage hero with wizard JS wired to `POST /frp/v1/leads` using `window.FRP_LEAD_TOKEN` already injected by WordPress on every page
- Ops assigns `[frp_home_wizard]` as the content of the WordPress front page

---

## Page Structure

Four sections in order:

1. Hero — inline wizard
2. Service Category Grid
3. How It Works
4. Pro Directory Teaser

---

## Section 1: Hero

### Copy

**Eyebrow (above headline):**
None — the headline is self-sufficient.

**Headline:**
> Water damage. Fire. Mold. Storm.
> **We'll connect you with a certified pro in minutes.**

**Subheadline:**
> Describe what happened — we match you with a vetted restoration contractor in your area. No searching, no guessing.

### Wizard UI

The wizard replaces the current search bar in the hero. It is an inline multi-step form — no modal, no navigation away. Steps render inside the hero section; the headline and background image remain fixed throughout.

**Activation:** The hero initially shows the wizard at step 1 (no separate "Get Help Now" button needed — the service cards are immediately visible and actionable).

**Step 1 — What happened? (service type)**
Prompt text: *"What happened to your property?"*
Display: 6–7 large clickable service cards in a grid, each with an icon and label:
- 💧 Water Damage (`water-damage`)
- 🔥 Fire & Smoke (`fire-damage`)
- 🍃 Mold (`mold-remediation`)
- 🌪 Storm Damage (`storm-damage`)
- 🚽 Sewage Cleanup (`sewage-cleanup`)
- 🏗 Structural Damage (`structural`)
- ☣️ Biohazard (`biohazard-cleanup`)

Selecting a card stores the value and slides to step 2.

**Step 2 — Property type**
Prompt: *"What type of property?"*
Options: Residential / Commercial (two large buttons)
Submitted values: `residential` / `commercial`

**Step 3 — Urgency**
Prompt: *"How urgent is it?"*
Options: Right now / Within 24 hours / Within a week (maps to: `now` / `24hrs` / `older`)

**Step 4 — Insurance**
Prompt: *"Do you have homeowner's insurance?"*
Options: Yes / No / Not sure (maps to: `yes` / `no` / `not-sure`)

**Step 5 — Contact info**
Fields:
- Phone number (required)
- Email address (optional — consistent with `guided_flow` source behavior; silently cleared if invalid)
- ZIP code (required)

Submit button: **"Find My Pro →"**

**Submission:** JS collects all five steps and POSTs to `/wp-json/frp/v1/leads` with:
```json
{
  "source": "guided_flow",
  "service": "<step1 value>",
  "property_type": "<step2 value>",
  "urgency": "<step3 value>",
  "has_insurance": "<step4 value>",
  "phone": "<step5 phone>",
  "email": "<step5 email>",
  "zip": "<step5 zip>"
}
```
Header: `X-FRP-Lead-Token: <window.FRP_LEAD_TOKEN>`

**Success state:** Hero replaces wizard with confirmation message:
> ✓ **We're on it.**
> A local restoration pro will contact you shortly. Check your phone.

**Error state:** Inline error message below the submit button. Form remains editable after all errors so the user can correct and resubmit. Specific messages by HTTP status:

| Status | Copy shown to user | Form behavior |
|---|---|---|
| `400` (bad_request) | "Please check your information and try again." | Stays editable |
| `429` (rate_limited) | "Too many requests. Please wait a few minutes and try again." | Submit button disabled for 60 seconds, then re-enabled |
| `403` (forbidden) | "We couldn't verify your request. Please refresh the page and try again." | Stays editable |
| Any other / network error | "Something went wrong. Please try again." | Stays editable |

**Pre-fill from service grid:** Service cards in Section 2 call `window.frpWizardStart(serviceSlug)` — a globally exposed JS function defined by the wizard. The function scrolls to the hero, sets the service to `serviceSlug`, and renders step 2 directly (skipping step 1). Example: `frpWizardStart('water-damage')`. No URL param mechanism — JS function only.

### Background

Existing hero background image (restored interior) and layout are retained. The IICRC eyebrow badge is removed — no platform-level credential claims on the homepage.

---

## Section 2: Service Category Grid

### Copy

**Headline:**
> Every type of damage. One trusted network.

**Subheadline:**
> Certified pros for every restoration scenario — matched to your location and situation.

### Layout

Existing asymmetric bento grid layout retained:
- Water Damage (large featured card, col-span-8)
- Fire & Smoke (col-span-4, primary color)
- Mold Remediation (col-span-4)
- Content Restoration (col-span-4)
- Storm Recovery (col-span-4)

### CTA Change

Each card's "Learn More →" link is replaced with: **"Get Help with [Service] →"**

Clicking this CTA scrolls to the hero and launches the wizard with that service pre-selected at step 1 (jumping to step 2).

---

## Section 3: How It Works

New section. Light background (`surface-container-low`) to visually separate from sections above and below.

### Copy

**Headline:**
> Help in 3 steps

**Step 1 — Describe your situation**
Icon: `assignment` (clipboard)
> Tell us what happened, where you are, and how urgent it is. Takes under 2 minutes.

**Step 2 — We match you**
Icon: `hub` (network nodes)
> Our system finds restoration contractors in your area who handle your specific type of damage.

**Step 3 — A pro contacts you**
Icon: `phone_in_talk`
> Your matched pro reaches out directly — typically within the hour for urgent situations.

**Section CTA:**
> Get matched now → (scrolls to hero, focuses wizard)

### Layout

Three columns, horizontal on desktop, stacked on mobile. Step number displayed prominently above each icon.

---

## Section 4: Pro Directory Teaser

### Copy

**Headline:**
> The network behind the match

**Subheadline:**
> Get matched with a local restoration company for your specific situation.

### Pro Cards

Displays 2–3 real `restoration_pro` posts from the database (paid/featured/premium tier preferred for featured placement). Each card shows:

- Business name
- Services offered
- Coverage area — display logic: if the pro has `service_area_zips` meta set (non-empty), show "Serving N zip codes"; else if `state` meta is set, show "Serving [STATE] area"; else omit the line entirely
- **Credential badges** — displayed only when the pro has `claim_status` meta equal to `claimed`. Pros with `claim_status` of `unclaimed`, `claim_pending`, or `disputed` show no badges. Badges shown are whatever credential meta fields the pro has self-reported (e.g., IICRC Certified, Licensed, Insured).
- **"Featured" label** for paid/featured/premium tier pros (`listing_tier` = `paid`, `featured`, or `premium`)
- CTA: **"View Profile →"** — links to the pro's individual profile page (`/profile/?slug=...` or CPT permalink)

No platform-level trust bar. No blanket claims about verification. Credential details and the self-reported disclaimer live in the Terms of Service, not on this page.

### Badge & Tier Progression

The homepage pro cards reflect the same badge logic used on full profile pages:

| `claim_status` value | `listing_tier` | Badge Display |
|---|---|---|
| `unclaimed` / `claim_pending` / `disputed` | any | No badges |
| `claimed` | `free` / `basic` | Self-reported credentials shown (IICRC, Licensed, Insured, years in business) |
| `claimed` | `paid` / `featured` / `premium` | All badges + "Featured" label + priority placement in teaser |

This progression is the primary incentive for pros to claim their free listing — claiming unlocks credential display sitewide (homepage teaser, directory, search results), and upgrading to paid unlocks additional visibility and direct lead dispatch.

### Zero-Result Empty State

If the `restoration_pro` WP_Query returns 0 results (no published pros with any listing tier), hide the pro cards grid entirely. Show only the section headline, subheadline, and the contractor acquisition CTA below. Do not show placeholder cards or a "no pros found" message.

### Footer CTA (for contractor acquisition)

Below the pro cards, a secondary line targeting contractors:
> **Are you a restoration contractor?** [Claim your listing →](/join/) or [Apply to join →](/join/)

### Browse CTA

> Browse all pros → (links to `/find-pros/`)

---

## Navigation Changes

Add to primary nav (if not already present):
- "Get Help" — scrolls to hero wizard (mobile-friendly anchor)
- "For Pros" — links to `/join/` (contractor acquisition)

---

## Technical Implementation

### Files

| File | Action |
|---|---|
| `stitch-html/frp-home.html` | Update with new copy, layout, wizard UI |
| `wordpress-plugins/frp-homepage.php` | Create — `[frp_home_wizard]` shortcode |

### `frp-homepage.php` Responsibilities

- Register shortcode `[frp_home_wizard]`
- Render full homepage HTML (hero through pro teaser)
- Inline wizard JavaScript:
  - Multi-step state machine (steps 1–5)
  - Pre-fill support via JS call only — `window.frpWizardStart(serviceSlug)` (for service grid CTAs)
  - POST to `rest_url('frp/v1/leads')` (PHP-injected, not hardcoded)
  - `X-FRP-Lead-Token` from `window.FRP_LEAD_TOKEN`
  - Success and error state rendering
- Query 2–3 `restoration_pro` posts for directory teaser (featured/paid tier preferred, falls back to any published pro)
- PHP-inject `rest_url('frp/v1/leads')` as `LEADS_URL` JS variable (same pattern as `frp-pro-template.php`)
- JSON-encode all PHP data passed to JS with `JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT` flags

### Wizard → Lead API Mapping

| Wizard Step | Field | Value |
|---|---|---|
| 1 (service) | `service` | `water-damage` / `fire-damage` / `mold-remediation` / `storm-damage` / `sewage-cleanup` / `structural` / `biohazard-cleanup` |
| 2 (property type) | `property_type` | `residential` / `commercial` |
| 3 (urgency) | `urgency` | `now` / `24hrs` / `older` |
| 4 (insurance) | `has_insurance` | `yes` / `no` / `not-sure` |
| 5 (contact) | `phone`, `email`, `zip` | User input |
| Fixed | `source` | `guided_flow` |

### Rate Limits

Existing rate limit applies: 3 submissions per 15 minutes per IP (`lead_create` bucket in `frp-directory.php`). No changes needed to the backend.

### Ops Steps (post-deployment)

1. Upload `frp-homepage.php` to SiteGround mu-plugins folder
2. Set WordPress front page to a static page with `[frp_home_wizard]` as its content
3. Confirm `window.FRP_LEAD_TOKEN` is present in page source (injected by `frp-directory.php` via `wp_head`)

---

## Out of Scope

- Changes to `POST /frp/v1/leads` backend logic
- New badge verification system (badges display self-reported data as-is; verification workflow is a future task)
- `/find-pros/` directory page redesign
- Individual pro profile page changes (already handled by `frp-pro-template.php`)
- Terms of Service page content (legal team to draft self-reported credential disclaimer)
