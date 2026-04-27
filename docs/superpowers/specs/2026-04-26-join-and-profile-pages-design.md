# Join Page & Pro Profile Page Design Spec

**Date:** 2026-04-26
**Status:** Approved

---

## Goal

1. Redesign the **Join as a Pro** page (`/join/`) to reflect accurate branding, honest copy, and the free-claim-first value proposition — serving both new contractors and existing contractors claiming a listing.
2. Update the **pro profile page** (`frp-pro-template.php`) to enforce `claim_status`-gated badge display, remove elevated placeholder copy, and update the gated-tier upsell prompt.

Both pages must be consistent with the homepage redesign spec (`2026-04-26-homepage-redesign-design.md`) in tone, badge logic, and tier positioning.

---

## Out of Scope

- Contractor dashboard analytics — separate spec and brainstorm
- GoHighLevel (GHL) integration for premium tier — future roadmap, noted
- CallRail integration — future roadmap, noted
- Zip code slot limits / guaranteed lead pool caps — not enforced yet; copy withheld until system is built
- `/pricing/` page content — referenced by both pages but designed separately
- New `listing_tier` values beyond free/basic/paid/featured/premium

---

## Part 1: Join as a Pro Page

### Approach

Single page, single form. The `/apply` endpoint already handles both new applications and existing listing claims via the matcher algorithm — contractors do not need to choose a path. One line below the form acknowledges both cases transparently.

### Files

| File | Action |
|---|---|
| `stitch-html/frp-join.html` | Full redesign |
| `wordpress-plugins/frp-join.php` | Create — `[frp_join_form]` shortcode |
| `wordpress-plugins/frp-directory.php` | Add `iicrc_certified` field to `/apply` handler |

---

### Section 1: Hero & Form

**Page title:** For Pros | Find Restoration Pros

**Headline:**
> Claim your free listing and start receiving leads from homeowners in your area.

**Subheadline:**
> Get your restoration business in front of people searching for help right now. Free to claim, no commitment required.

#### Form Fields

The form submits to `POST /wp-json/frp/v1/apply`. All fields match endpoint parameter names exactly.

| Label | Parameter | Type | Required | Notes |
|---|---|---|---|---|
| Business Name | `business_name` | text | Yes | |
| Phone | `dispatch_phone` | tel | Yes | Regex validated server-side |
| Email | `contact_email` | email | Yes | |
| State | `state` | select | Yes | All 50 US states + DC |
| Services | `services[]` | checkboxes | No | water-damage, fire-damage, mold-remediation, storm-damage, sewage-cleanup, structural, biohazard-cleanup |
| IICRC Certified? | `iicrc_certified` | radio | No | Options: Yes / No / In Progress. Helper text: *"IICRC certification is displayed on your profile as a badge and improves credibility with homeowners."* |
| License Number | `license_number` | text | No | |
| Years in Business | `years_in_business` | number | No | `absint` server-side |
| Service Area ZIP codes | `service_area_zips` | text | No | Comma-separated |

**Submit button:** "Claim My Free Listing →"

**Below button (two lines):**
> Already in our directory? We'll find your listing and send a verification email to confirm ownership.
> *Paid upgrades available after you claim your listing. [See what's included →](/pricing/)*

No price shown on this page. Pricing is introduced post-claim from the contractor dashboard.

#### Form Submission Behavior

- JS submits to `POST /wp-json/frp/v1/leads` — correction: `POST /wp-json/frp/v1/apply`
- On `200`: Replace form with success message: *"Application received. Check your email — we'll be in touch within 1–2 business days."* If the matcher found an existing listing, the email will contain a claim verification link instead.
- On `400`: Show inline field-level error (e.g., "Phone number is invalid")
- On `429`: Show: *"Too many applications from this connection. Please try again later."* Disable submit for 60 seconds.
- On any other error: *"Something went wrong. Please try again."*

---

### Section 2: What You Get

Three benefit cards below the form. Light background to visually separate from the form section.

**Section headline:** (no headline — cards speak for themselves)

**Card 1 — Your listing, your credentials**
Icon: `verified` (checkmark badge)
> Once claimed, your business name, services, and credentials appear on your profile. IICRC certification and other self-reported credentials are displayed as badges to homeowners searching in your area.

**Card 2 — Get matched with homeowners**
Icon: `notifications_active`
> When a homeowner submits a restoration request matching your service type and location, you get notified. Free listings receive leads routed through our matching system.

**Card 3 — Paid listing: more visibility, direct contact**
Icon: `trending_up`
> Paid listings unlock your phone number on your profile so homeowners can call you directly. When a homeowner contacts you from your profile page, that lead comes to you alone. Paid listings are also highlighted in directory search results and featured on the homepage.

**No prices on this page.** The contractor sees pricing after claiming, from the dashboard upgrade flow.

---

### Backend Change: `iicrc_certified` Field

The `/apply` endpoint (`frp_apply_handler` in `frp-directory.php`) needs one addition:

**Read and store:**
```php
$iicrc = sanitize_text_field( (string) ( $r->get_param( 'iicrc_certified' ) ?? '' ) );
// Allowed values: 'yes', 'no', 'in_progress'. Silently ignore anything else.
if ( ! in_array( $iicrc, [ 'yes', 'no', 'in_progress' ], true ) ) {
    $iicrc = '';
}
```

**Store on new draft pro post:**
```php
update_post_meta( $pro_id, 'iicrc_certified', $iicrc );
```

For claim flows (where the pro already exists), update the meta on the existing pro post if `$iicrc` is non-empty.

**No validation required** — field is optional. Missing or invalid values are silently cleared.

---

## Part 2: Pro Profile Page Updates

Three targeted changes to `wordpress-plugins/frp-pro-template.php`. No structural changes — the tier-gating logic, modal form, and JS are all correct and stay as-is.

### Files

| File | Action |
|---|---|
| `wordpress-plugins/frp-pro-template.php` | Modify — badge gate, copy polish, upsell text |

---

### Change 1: Badge Display Gate (`claim_status` check)

**Current behavior:** Credential badges (IICRC, Licensed, Insured) are displayed based on whether the pro has those meta fields set, with no check on claim status.

**New behavior:** Badges only render when `claim_status` meta equals `'claimed'`. Pros with `claim_status` of `unclaimed`, `claim_pending`, `disputed`, or empty string show no credential badges.

**Implementation:**
```php
$claim_status = (string) get_post_meta( $pro_id, 'claim_status', true );
$is_claimed   = ( $claim_status === 'claimed' );
```

All badge rendering blocks are wrapped in `if ( $is_claimed ) { ... }`.

The tier-gating for phone/CTAs is unchanged — it remains based on `listing_tier` only.

---

### Change 2: Copy Polish

Remove hardcoded elevated placeholder copy. The profile page renders the pro's actual `description` meta field for the business description. Where placeholder text currently exists (e.g., "Certified specialists in structural recovery and environmental remediation. We serve the greater metropolitan area with 24/7 emergency response protocols and a commitment to architectural integrity."), replace with a conditional:

- If `description` meta is set and non-empty: render it
- If empty: render nothing (no placeholder copy)

Section headings that use elevated language:
- "Core Expertise" → keep as-is (functional, not editorial)
- "Portfolio of Recovery" → keep as-is (acceptable)
- "Client Perspectives" → keep as-is (acceptable)

Remove any hardcoded copy that references "architectural integrity", "resilient monolith", or similar editorial voice not tied to the pro's actual data.

---

### Change 3: Gated Tier Upsell Prompt

**Current copy:**
> "Upgrade your listing so customers can reach you directly. [→ /pricing/]"

**New copy:**
> "Want homeowners to call you directly? Upgrade your listing to show your phone number and receive leads straight to you. [See what's included →](/pricing/)"

This applies to both:
- The sidebar upsell note (visible below the "Request Service" button for free/basic tier pros)
- The mobile sticky bar equivalent (if upsell text appears there)

---

## Consistency with Homepage Spec

Both pages use the same badge/tier logic defined in `2026-04-26-homepage-redesign-design.md`:

| `claim_status` | `listing_tier` | Badges shown |
|---|---|---|
| `unclaimed` / `claim_pending` / `disputed` / empty | any | None |
| `claimed` | `free` / `basic` | Self-reported credentials |
| `claimed` | `paid` / `featured` / `premium` | Self-reported credentials + "Featured" label |

---

## Roadmap Items (Not in This Spec)

- **Zip code slot cap** — limit paid pros per geographic area to reduce lead pool competition. Copy to be added to join page benefits once system is enforced.
- **Contractor dashboard analytics** — separate spec. Baseline (B): call count, lead list, volume chart. Advanced upsell (C): deeper analytics.
- **GoHighLevel (GHL) integration** — premium tier upsell for AI voice, call tracking, CRM automations. Separate spec.
- **CallRail integration** — alternative/simpler call tracking for mid-tier. Separate spec.
- **Paid listing price** — confirmed at $249/month. Update `frp-billing.php` constant `price_usd` for the `paid` tier from `19900` to `24900` cents as part of this implementation.
