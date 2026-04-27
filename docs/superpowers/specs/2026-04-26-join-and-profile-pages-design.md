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
| Services | `services` | checkboxes | No | water-damage, fire-damage, mold-remediation, storm-damage, sewage-cleanup, structural, biohazard-cleanup. Submit as JSON array: `"services": ["water-damage", ...]`. Endpoint reads `get_param('services')` — no bracket notation in the request body. |
| IICRC Certified? | `iicrc_certified` | radio | No | Options: Yes (`value="yes"`) / No (`value="no"`) / In Progress (`value="in_progress"`). Use these exact values — the server validator accepts only `yes`, `no`, `in_progress` (lowercase with underscores) and silently clears anything else. Helper text: *"IICRC certification is displayed on your profile as a badge and improves credibility with homeowners."* |
| License Number | `license_number` | text | No | |
| Years in Business | `years_in_business` | number | No | `absint` server-side |
| Service Area ZIP codes | `service_area_zips` | text | No | Comma-separated. Stored in CPT meta as `zip_codes` (existing backend mapping — no change needed). |

Note: `contact_name` is accepted by the endpoint but is not surfaced on the public join form. It will be stored as an empty string and can be filled by ops if needed.

**Submit button:** "Claim My Free Listing →"

**Below button (two lines):**
> Already in our directory? We'll find your listing and send a verification email to confirm ownership.
> *Paid upgrades available after you claim your listing. [See what's included →](/pricing/)*

No price shown on this page. Pricing is introduced post-claim from the contractor dashboard.

#### Form Submission Behavior

- JS submits to `POST /wp-json/frp/v1/apply`
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

The `/apply` endpoint in `frp-directory.php` uses a three-function chain: `frp_apply_handler` calls `frp_apply_validate()` (returns a structured array) which is then passed to either `frp_apply_insert_new()` or `frp_apply_initiate_claim()`. The `iicrc_certified` field flows through this chain as follows:

**Step 1 — Add to `frp_apply_validate()` return array:**

The function currently returns `compact('business', 'contact', 'email', 'phone', 'license', 'years', 'zips', 'city', 'state', 'services')` — note: `business` (not `business_name`), `contact` (not `contact_name`).

Insert the `$iicrc` assignment among the existing variable assignments (lines 1582–1591, before the validation checks):

```php
$iicrc = sanitize_text_field( (string) ( $r->get_param( 'iicrc_certified' ) ?? '' ) );
// Allowed values: 'yes', 'no', 'in_progress'. Silently ignore anything else.
if ( ! in_array( $iicrc, [ 'yes', 'no', 'in_progress' ], true ) ) {
    $iicrc = '';
}
```

Then add `iicrc` to the `compact()` call: `return compact('business', 'contact', 'email', 'phone', 'license', 'years', 'zips', 'city', 'state', 'services', 'iicrc')`.

**Step 2 — Store in `frp_apply_insert_new()`, and fix phone meta key gap:**

```php
update_post_meta( $pro_id, 'iicrc_certified', $a['iicrc'] );
```

Add this line alongside the other `update_post_meta` calls in `frp_apply_insert_new()`.

**Also add this in `frp_apply_insert_new()`** (alongside the existing `dispatch_phone` write):

```php
update_post_meta( $pro_id, 'phone', $a['phone'] );
```

**Why:** The profile template reads the `phone` meta key (line 42 of `frp-pro-template.php`). The apply endpoint currently only writes `dispatch_phone`. Without this fix, any contractor who joins through the new form will have an empty `phone` meta, meaning their phone number will never appear on their profile page even after upgrading to a paid tier.

**Step 3 — Update existing pro in `frp_apply_initiate_claim()`:**

`$pro_id` is resolved as `$pro_id = $match['pro_id']` (note: `pro_id`, not `id`). However, the function has an early-return path (no on-file email → routes to manual review) immediately after `$pro_id` resolution. Do **not** place the write before that guard — data should only be written when the claim proceeds normally.

The `$applicant` argument is the direct return value of `frp_apply_validate()` (see `frp_apply_handler` line 1657: `$applicant = frp_apply_validate($request)`). It is passed unchanged to `frp_apply_initiate_claim()` at lines 1664 and 1667. Therefore `$applicant['phone']` and `$applicant['iicrc']` (after Step 1 adds `iicrc` to compact) are both valid keys.

Place both writes among the other `update_post_meta` calls (after line 1702, starting around line 1709):

```php
if ( $applicant['iicrc'] !== '' ) {
    update_post_meta( $pro_id, 'iicrc_certified', $applicant['iicrc'] );
}
if ( $applicant['phone'] !== '' ) {
    update_post_meta( $pro_id, 'phone', $applicant['phone'] );
}
```

Only write if non-empty — do not overwrite an existing value with blank. The `phone` write is needed for the same reason as in `frp_apply_insert_new()`: the profile template reads the `phone` meta key, not `dispatch_phone`.

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

**Current state:** The current template (`frp-pro-template.php`) contains NO credential badge rendering — there is no IICRC badge, no Licensed badge, no Insured badge. These blocks need to be **created**, not modified.

**Implementation:**

Add two new PHP variables near the top of the data section (after `$bio`):

```php
$claim_status  = (string) get_post_meta( $pro_id, 'claim_status', true );
$is_claimed    = ( $claim_status === 'claimed' );
$iicrc_status  = (string) get_post_meta( $pro_id, 'iicrc_certified', true );
$certifications = (string) get_post_meta( $pro_id, 'certifications', true );
$years_in_biz  = (int) get_post_meta( $pro_id, 'years_in_business', true );
```

Add badge rendering in the main content column (after the `$bio` paragraph, before the sidebar), guarded by `$is_claimed`:

```php
<?php if ( $is_claimed ) : ?>
    <div class="frp-credential-badges">
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

Add minimal CSS for `.frp-credential-badges` and `.frp-badge` in the `<style>` block — inline pill style, similar to the existing `.frp-meta-row` color palette. Example:

```css
.frp-credential-badges { display: flex; flex-wrap: wrap; gap: .4rem; margin: .75rem 0; }
.frp-badge { display: inline-block; padding: .2rem .6rem; background: #f1f5f9; border-radius: 999px; font-size: .8rem; color: #475569; font-weight: 500; }
.frp-badge--iicrc { background: #dbeafe; color: #1d4ed8; }
.frp-badge--featured { background: #fef9c3; color: #a16207; font-weight: 600; }
```

**Elevated listing badge** — for `paid`/`featured`/`premium` tier pros, render a highlighted badge alongside credentials. This is consistent with the homepage spec which uses the "Featured" label for all three tiers in the pro directory teaser. The existing `$is_accessible` variable (already defined as `in_array($tier, ['paid', 'featured', 'premium'])`) is the correct gate.

Note: The label "Featured" applies to all accessible tiers (`paid`, `featured`, `premium`), not only the `featured` tier specifically. This is intentional — it signals elevated listing status, not tier rank. Consistent with homepage badge logic in `2026-04-26-homepage-redesign-design.md`.

The PHP block (already included above in the `.frp-credential-badges` block) is:

```php
<?php if ( $is_accessible ) : ?>
    <span class="frp-badge frp-badge--featured">⭐ Featured</span>
<?php endif; ?>
```

This renders as the first badge inside `.frp-credential-badges`, only when `$is_claimed` (outer guard) is also true.

The tier-gating for phone/CTAs is unchanged — it remains based on `listing_tier` only.

---

### Change 2: Copy Polish

**No code change required for the current template.** The current `frp-pro-template.php` already implements the correct conditional at lines 211–213:

```php
<?php if ( $bio ) : ?>
    <p><?php echo esc_html( $bio ); ?></p>
<?php endif; ?>
```

There is no hardcoded placeholder copy (no "architectural integrity", "resilient monolith", or similar) and no "Core Expertise" / "Portfolio of Recovery" / "Client Perspectives" section headings in the current template.

**Verification step only:** Confirm this conditional is present. If a future version of the template re-introduces placeholder copy, this spec defines the correct behavior: render `$bio` if set and non-empty; render nothing if empty.

---

### Change 3: Gated Tier Upsell Prompt

**Current copy:**
> "Upgrade your listing so customers can reach you directly. [→ /pricing/]"

**New copy:**
> "Want homeowners to call you directly? Upgrade your listing to show your phone number and receive leads straight to you. [See what's included →](/pricing/)"

This applies only to:
- The sidebar upsell note (`<p class="frp-upsell-note">`) — visible in the `else` branch of `#frp-profile-cta-sidebar`, which renders for any tier NOT in `['paid', 'featured', 'premium']`. This includes both `free` and `basic` tier pros. `basic` ($49/month) does not unlock phone visibility or direct dispatch, so the same upsell copy applies — the upgrade path is `basic → paid`.

Replace lines 241–244 with:

```php
<p class="frp-upsell-note">
    Want homeowners to call you directly? Upgrade your listing to show your phone number and receive leads straight to you.
    <a href="<?php echo esc_url( home_url( '/pricing/' ) ); ?>">See what's included →</a>
</p>
```

The mobile sticky bar (`#frp-profile-sticky-bar`) currently contains only a button — no upsell text. No change needed there.

---

## Consistency with Homepage Spec

Both pages use the same badge/tier logic defined in `2026-04-26-homepage-redesign-design.md`:

| `claim_status` | `listing_tier` | Badges shown |
|---|---|---|
| `unclaimed` / `claim_pending` / `disputed` / empty | any | None |
| `claimed` | `free` / `basic` | Self-reported credentials |
| `claimed` | `paid` / `featured` / `premium` | Self-reported credentials + "Featured" label |

---

## Additional Backend Change: Paid Listing Price

**PHP constant (display price):** In `frp-billing.php`, change the `price_usd` value for the `paid` tier from `19900` to `24900` cents:

```php
'paid' => [ 'label' => 'Paid Listing', 'option' => 'frp_stripe_price_paid', 'price_usd' => 24900 ],
```

This is a display-only change — `price_usd` is returned by the `/billing/catalog` REST endpoint for pricing display in the UI.

**Stripe price ID (ops task, not a code change):** The actual Stripe charge is controlled by the `frp_stripe_price_paid` WordPress option, which stores a Stripe Price object ID (e.g., `price_xxxxx`). Changing `price_usd` does NOT change what Stripe charges. To change the actual billing amount, ops must:
1. Create a new Stripe Price object at $249/month in the Stripe dashboard
2. Update the `frp_stripe_price_paid` WordPress option (via wp-admin → FRP Billing settings) to the new Price ID

**Scope:** The PHP `price_usd` constant change is in scope for this implementation. The Stripe price object creation and option update are ops tasks — not in scope for this implementation but must happen before the new price takes effect for subscribers.

---

## Notes for Engineers

- **`contact_name` field** — `frp_apply_validate()` reads a `contact_name` parameter and `frp_apply_insert_new()` stores it as CPT meta. This field is intentionally absent from the join page form. It will be stored as an empty string for all applications from this page; ops can fill it later if needed.

- **`premium` tier** — `premium` is a valid `listing_tier` value in the tier gate (whitelist includes it) and in the badge logic table above. However, there is currently no `premium` entry in `FRP_TIERS` in `frp-billing.php` — it has no billing path and must be assigned manually via the admin. No code change needed; engineers should not assume `premium` is fully wired end-to-end.

---

## Roadmap Items (Not in This Spec)

- **Zip code slot cap** — limit paid pros per geographic area to reduce lead pool competition. Copy to be added to join page benefits once system is enforced.
- **Contractor dashboard analytics** — separate spec. Baseline (B): call count, lead list, volume chart. Advanced upsell (C): deeper analytics.
- **GoHighLevel (GHL) integration** — premium tier upsell for AI voice, call tracking, CRM automations. Separate spec.
- **CallRail integration** — alternative/simpler call tracking for mid-tier. Separate spec.
