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
