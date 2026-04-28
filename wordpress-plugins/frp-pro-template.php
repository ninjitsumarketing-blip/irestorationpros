<?php
/**
 * Plugin Name: FRP Pro Template
 * Description: Profile page template for restoration_pro single posts. Implements tier-gated CTA hierarchy for lead capture.
 * Version: 1.0.0
 */
if ( ! defined( 'ABSPATH' ) ) exit;

// Remove the legacy redirect that sent restoration_pro singular requests to /profile/?slug=.
// This template now handles those URLs directly via template_include — no redirect needed.
remove_action( 'template_redirect', 'frp_redirect_cpt_permalink' );

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

$claim_status   = (string) get_post_meta( $pro_id, 'claim_status', true );
$is_claimed     = ( $claim_status === 'claimed' );
$iicrc_status   = (string) get_post_meta( $pro_id, 'iicrc_certified', true );
$certifications = (string) get_post_meta( $pro_id, 'certifications', true );
$years_in_biz   = (int)    get_post_meta( $pro_id, 'years_in_business', true );

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

// Call Now URL — use rest_url() not hardcoded /wp-json/; escape only at output
$call_url = rest_url( 'frp/v1/call' ) . '?company=' . $pro_id . '&source=profile&path=profile';

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

/* ── Credential badges ── */
.frp-credential-badges { display: flex; flex-wrap: wrap; gap: .4rem; margin: .75rem 0; }
.frp-badge { display: inline-block; padding: .2rem .6rem; background: #f1f5f9; border-radius: 999px; font-size: .8rem; color: #475569; font-weight: 500; }
.frp-badge--iicrc { background: #dbeafe; color: #1d4ed8; }
.frp-badge--featured { background: #fef9c3; color: #a16207; font-weight: 600; }

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
        <?php
        $cert_list   = $certifications
            ? array_filter( array_map( 'trim', explode( ',', $certifications ) ) )
            : [];
        $has_badges  = $is_accessible || ( $iicrc_status === 'yes' ) || $cert_list || ( $years_in_biz > 0 );
        ?>
        <?php if ( $is_claimed && $has_badges ) : ?>
        <div class="frp-credential-badges">
            <?php if ( $is_accessible ) : ?>
                <span class="frp-badge frp-badge--featured">⭐ Featured</span>
            <?php endif; ?>
            <?php if ( $iicrc_status === 'yes' ) : ?>
                <span class="frp-badge frp-badge--iicrc">✓ IICRC Certified</span>
            <?php endif; ?>
            <?php foreach ( $cert_list as $cert ) : ?>
                <span class="frp-badge"><?php echo esc_html( $cert ); ?></span>
            <?php endforeach; ?>
            <?php if ( $years_in_biz > 0 ) : ?>
                <span class="frp-badge"><?php echo esc_html( $years_in_biz ); ?> yrs in business</span>
            <?php endif; ?>
        </div>
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
                Want homeowners to call you directly? Upgrade your listing to show your phone number and receive leads straight to you.
                <a href="<?php echo esc_url( home_url( '/pricing/' ) ); ?>">See what's included →</a>
            </p>

        <?php endif; ?>
        </div><!-- #frp-profile-cta-sidebar -->
    </aside>

</div><!-- .frp-profile-wrap -->

<!-- ── JSON-LD schema (telephone only for accessible tier — never leak phone for gated pros) ── -->
<script type="application/ld+json"><?php echo wp_json_encode( $schema, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT ); ?></script>

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
                    <input type="text" id="frp-zip" name="zip" required maxlength="5" pattern="[0-9]{5}" inputmode="numeric" placeholder="90210">
                </div>
                <div class="frp-form-group">
                    <label for="frp-address">Street Address</label>
                    <input type="text" id="frp-address" name="property_address" maxlength="200" placeholder="123 Main St">
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
                    <input type="text" id="frp-service" value="<?php echo esc_attr( $single_label ); ?>" readonly>
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

            <div class="frp-form-error" id="frp-form-error" role="alert" aria-live="polite"></div>

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
    var PRO_NAME   = <?php echo wp_json_encode( $pro_name, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT ); ?>;
    var LEADS_URL  = <?php echo wp_json_encode( rest_url( 'frp/v1/leads' ), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT ); ?>;

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

        fetch(LEADS_URL, {
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
                // Success: close modal, reset form, replace sidebar CTA, hide sticky bar trigger
                closeModal();
                e.target.reset();
                var sidebar = document.getElementById('frp-profile-cta-sidebar');
                if (sidebar) {
                    sidebar.innerHTML =
                        '<p class="frp-confirm-msg">Request sent — ' +
                        PRO_NAME + ' will be in touch soon.</p>';
                }
                var stickyBtn = document.querySelector('#frp-profile-sticky-bar .frp-open-modal');
                if (stickyBtn) { stickyBtn.style.display = 'none'; }
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
