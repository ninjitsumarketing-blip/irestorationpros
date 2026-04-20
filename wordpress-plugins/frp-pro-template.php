<?php
/**
 * FRP Pro Profile Template
 * Served for all restoration_pro single post requests via template_include filter.
 * Renders server-side HTML — no JS required. Fully crawlable by Google and AI.
 * Matches frp-profile-rebuilt.html exactly using #frp-app CSS scoping.
 *
 * Upload to: wp-content/mu-plugins/frp-pro-template.php
 */
if ( ! defined( 'ABSPATH' ) ) exit;

// ── MU-PLUGIN GUARD ────────────────────────────────────────────────────────────
// This file is auto-loaded as a mu-plugin on EVERY WordPress request.
// Bail immediately for any non-frontend context and for early bootstrap passes
// before $wp_query is fully initialized (which causes "get() on null" fatals).
if ( is_admin() )                                     return;  // wp-admin screens
if ( wp_doing_ajax() )                                return;  // admin-ajax.php
if ( wp_doing_cron() )                                return;  // WP-Cron
if ( ! did_action( 'parse_query' ) )                  return;  // too early
global $wp_query;
if ( ! ( $wp_query instanceof WP_Query ) )            return;  // $wp_query not ready
// ──────────────────────────────────────────────────────────────────────────────

// Set up the post — required so get_the_ID() / get_the_title() work correctly
global $wp_query;
if ( $wp_query->have_posts() ) {
    $wp_query->the_post();
}

// Pull all meta for this pro
$id           = get_the_ID();
$name         = get_the_title();
$slug         = get_post_field( 'post_name', $id );
$phone        = get_post_meta( $id, 'phone',               true );
$city         = get_post_meta( $id, 'city',                true );
$county       = get_post_meta( $id, 'county',              true );
$state        = get_post_meta( $id, 'state',               true ) ?: 'CA';
$zip_codes    = get_post_meta( $id, 'zip_codes',           true );
$address      = get_post_meta( $id, 'address',             true );
$website      = get_post_meta( $id, 'website',             true );
$services_raw = get_post_meta( $id, 'services',            true );
$description  = get_post_meta( $id, 'description',         true );
$rating       = (float) get_post_meta( $id, 'google_rating',       true );
$review_count = (int)   get_post_meta( $id, 'google_review_count', true );
$lat          = (float) get_post_meta( $id, 'lat',                 true );
$lng          = (float) get_post_meta( $id, 'lng',                 true );
$logo_url     = get_post_meta( $id, 'logo_url',            true );
$years        = (int)   get_post_meta( $id, 'years_in_business',   true );
$certs        = get_post_meta( $id, 'certifications',      true );
$response     = get_post_meta( $id, 'response_time',       true ) ?: '< 1 hr';
$verified     = (bool)  get_post_meta( $id, 'verified',            true );
$place_id     = get_post_meta( $id, 'google_place_id',     true );
$yelp_url     = get_post_meta( $id, 'yelp_url',            true );
$yelp_rating  = (float) get_post_meta( $id, 'yelp_rating',        true );
$yelp_count   = (int)   get_post_meta( $id, 'yelp_review_count',  true );
$youtube_url  = get_post_meta( $id, 'youtube_url',         true );
$license_no   = get_post_meta( $id, 'license_number',      true );

// Service labels
$service_map = [
    'water-damage'      => 'Water Damage Restoration',
    'fire-damage'       => 'Fire & Smoke Damage',
    'mold-remediation'  => 'Mold Remediation',
    'storm-damage'      => 'Storm Damage Repair',
    'sewage-cleanup'    => 'Sewage Cleanup',
    'biohazard-cleanup' => 'Biohazard Cleanup',
    'structural'        => 'Structural Restoration',
    'reconstruction'    => 'Reconstruction',
    'asbestos'          => 'Asbestos Removal',
];
$service_slugs   = array_filter( array_map( 'trim', explode( ',', $services_raw ) ) );
$service_labels  = array_map( fn($s) => $service_map[$s] ?? ucwords( str_replace('-',' ',$s) ), $service_slugs );
$primary_service = $service_labels[0] ?? 'Restoration Services';
$primary_slug    = $service_slugs[0]  ?? '';

// Hero image by primary service
$hero_images = [
    'fire-damage'      => 'https://findrestorationpros.com/wp-content/uploads/2026/04/Fire-Damage-Home.png',
    'water-damage'     => 'https://findrestorationpros.com/wp-content/uploads/2026/04/Water-damage-restoration-services.png',
    'mold-remediation' => 'https://findrestorationpros.com/wp-content/uploads/2026/04/Water-Damage-Restoration-Team.png',
    'storm-damage'     => 'https://findrestorationpros.com/wp-content/uploads/2026/04/Water-damage-restoration.png',
    'sewage-cleanup'   => 'https://findrestorationpros.com/wp-content/uploads/2026/04/Water-damage-kitchen.png',
    'structural'       => 'https://findrestorationpros.com/wp-content/uploads/2026/04/Wtaer-damage-high-end-home.png',
];
$hero_bg = $hero_images[$primary_slug] ?? 'https://findrestorationpros.com/wp-content/uploads/2026/04/Water-Damage-Restoration-Team.png';

// City slug for canonical URL
$city_slug = frp_get_pro_city_slug( $id );
$canonical = home_url( '/pros/' . $city_slug . '/' . $slug . '/' );

// Area served display string
$area_served_display = $city
    ? $city . ( $county ? ', ' . $county . ' County' : '' ) . ', CA'
    : ( $county ? $county . ' County, CA' : 'California' );

// Phone cleaned for tel: links
$phone_clean = preg_replace( '/[^0-9]/', '', $phone );

// Google Maps link
$google_maps_url = $place_id
    ? 'https://maps.google.com/?cid=' . $place_id
    : ( $name ? 'https://www.google.com/search?q=' . urlencode( $name . ' ' . $city ) : '#' );

// FAQ items
$faqs = [
    [
        'q' => "What services does {$name} offer?",
        'a' => $service_labels ? implode( ', ', $service_labels ) . '.' : 'Professional restoration services.',
    ],
    [
        'q' => "Where is {$name} located?",
        'a' => $city ? "{$name} is based in {$city}, {$state}." : "{$name} serves customers across California.",
    ],
    [
        'q' => "Is {$name} licensed and insured?",
        'a' => $verified
            ? "{$name} is a verified restoration contractor on FindRestorationPros.com."
            : "Contact {$name} directly to confirm licensing and insurance details.",
    ],
    [
        'q' => "Does {$name} work with insurance companies?",
        'a' => "{$name} works with most major insurance carriers. Contact them directly to confirm your policy is accepted.",
    ],
    [
        'q' => "What areas does {$name} serve?",
        'a' => "{$name} provides restoration services in {$area_served_display}.",
    ],
];

// Schema.org: LocalBusiness
$schema_address = [
    '@type'           => 'PostalAddress',
    'addressLocality' => $city  ?: '',
    'addressRegion'   => $state ?: 'CA',
    'addressCountry'  => 'US',
];
if ( $address )   $schema_address['streetAddress'] = $address;
if ( $zip_codes ) $schema_address['postalCode']    = strtok( $zip_codes, ',' );

$schema = [
    '@context' => 'https://schema.org',
    '@type'    => 'LocalBusiness',
    'name'     => $name,
    'url'      => $canonical,
    'address'  => $schema_address,
];
if ( $phone )       $schema['telephone']   = $phone;
if ( $description ) $schema['description'] = $description;
if ( $logo_url )    $schema['logo']        = $logo_url;
if ( $website )     $schema['sameAs'][]    = $website;
if ( $place_id )    $schema['sameAs'][]    = 'https://maps.google.com/?cid=' . $place_id;
if ( $lat && $lng ) $schema['geo'] = [ '@type' => 'GeoCoordinates', 'latitude' => $lat, 'longitude' => $lng ];
if ( $rating && $review_count ) {
    $schema['aggregateRating'] = [
        '@type'       => 'AggregateRating',
        'ratingValue' => $rating,
        'reviewCount' => $review_count,
        'bestRating'  => 5,
        'worstRating' => 1,
    ];
}
if ( ! empty( $service_labels ) ) {
    $schema['hasOfferCatalog'] = [
        '@type'           => 'OfferCatalog',
        'name'            => 'Restoration Services',
        'itemListElement' => array_map( fn($s) => [ '@type' => 'Offer', 'itemOffered' => [ '@type' => 'Service', 'name' => $s ] ], $service_labels ),
    ];
}
if ( $city ) $schema['areaServed'] = [ '@type' => 'City', 'name' => $city ];

// Schema.org: FAQ
$faq_schema = [
    '@context'   => 'https://schema.org',
    '@type'      => 'FAQPage',
    'mainEntity' => array_map( fn($f) => [
        '@type'          => 'Question',
        'name'           => $f['q'],
        'acceptedAnswer' => [ '@type' => 'Answer', 'text' => $f['a'] ],
    ], $faqs ),
];

// Schema.org: BreadcrumbList
$breadcrumb_schema = [
    '@context'        => 'https://schema.org',
    '@type'           => 'BreadcrumbList',
    'itemListElement' => [
        [ '@type' => 'ListItem', 'position' => 1, 'name' => 'Home',             'item' => home_url('/') ],
        [ '@type' => 'ListItem', 'position' => 2, 'name' => 'Restoration Pros', 'item' => home_url('/pros/') ],
        [ '@type' => 'ListItem', 'position' => 3, 'name' => $city ?: 'Local',   'item' => home_url('/pros/' . $city_slug . '/') ],
        [ '@type' => 'ListItem', 'position' => 4, 'name' => $name,              'item' => $canonical ],
    ],
];

// Page title and meta description
$page_title = "{$name} | {$primary_service} in " . ( $city ?: 'California' ) . " | FindRestorationPros";
$meta_desc  = $description
    ? wp_trim_words( $description, 25 )
    : "{$name} provides professional {$primary_service} services in " . ( $city ?: 'California' ) . ". " . ( $rating ? number_format($rating,1) . " stars (" . $review_count . " reviews). " : "" ) . "Free estimates. Call now.";
?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo('charset'); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo esc_html( $page_title ); ?></title>
<meta name="description" content="<?php echo esc_attr( $meta_desc ); ?>">
<link rel="canonical" href="<?php echo esc_url( $canonical ); ?>">

<!-- OpenGraph -->
<meta property="og:type"        content="website">
<meta property="og:title"       content="<?php echo esc_attr( $page_title ); ?>">
<meta property="og:description" content="<?php echo esc_attr( $meta_desc ); ?>">
<meta property="og:url"         content="<?php echo esc_url( $canonical ); ?>">
<?php if ( $logo_url ) : ?>
<meta property="og:image" content="<?php echo esc_url( $logo_url ); ?>">
<?php endif; ?>

<!-- Twitter Card -->
<meta name="twitter:card"        content="summary">
<meta name="twitter:title"       content="<?php echo esc_attr( $page_title ); ?>">
<meta name="twitter:description" content="<?php echo esc_attr( $meta_desc ); ?>">

<!-- Schema.org: LocalBusiness -->
<script type="application/ld+json"><?php echo wp_json_encode( $schema,            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ); ?></script>
<!-- Schema.org: FAQ -->
<script type="application/ld+json"><?php echo wp_json_encode( $faq_schema,        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ); ?></script>
<!-- Schema.org: Breadcrumb -->
<script type="application/ld+json"><?php echo wp_json_encode( $breadcrumb_schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ); ?></script>

<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&family=Manrope:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200" rel="stylesheet">

<style>
/* Reset theme chrome */
html,body{margin:0!important;padding:0!important;}
html{margin-top:0!important;}
body.admin-bar{margin-top:0!important;padding-top:0!important;}
.site-header,.site-footer,#masthead,#colophon,.wp-site-blocks>header,.wp-site-blocks>footer{display:none!important;}
#wpadminbar{display:none!important;}

/* Full #frp-app design system */
#frp-app,#frp-app *{box-sizing:border-box;}
#frp-app{--navy:#00175c;--primary-blue:#00288e;--secondary:#0058be;--emergency-red:#dc2626;--surface:#f8f9ff;--surface-low:#eff4ff;--surface-container:#e6eeff;--on-surface:#0b1c30;font-family:'Inter',sans-serif;color:var(--on-surface);background:var(--surface);margin:0;line-height:1.5;}
#frp-app h1,#frp-app h2,#frp-app h3,#frp-app h4,#frp-app h5,#frp-app h6{font-family:'Manrope',sans-serif;}
#frp-app a{text-decoration:none;color:inherit;}
#frp-app img{max-width:100%;height:auto;}
#frp-app .hidden{display:none!important;}
#frp-app img.emoji,#frp-app img.wp-smiley{display:none!important;}
#frp-app .material-symbols-outlined{font-variation-settings:'FILL' 0,'wght' 400,'GRAD' 0,'opsz' 24;vertical-align:middle;line-height:1;}
#frp-app .font-800{font-weight:800;}
#frp-app .backdrop-blur-sm{backdrop-filter:blur(4px);-webkit-backdrop-filter:blur(4px);}
#frp-app .shadow-2xl{box-shadow:0 24px 48px rgba(0,23,92,.26);}
#frp-app .rounded-3xl{border-radius:1.5rem;}
#frp-app .tracking-widest{letter-spacing:.1em;}
#frp-app .leading-snug{line-height:1.375;}
@keyframes frp-spin{to{transform:rotate(360deg)}}
@keyframes frp-bounce{0%,100%{transform:translateY(-2px)}50%{transform:translateY(0)}}
@keyframes frp-pulse{50%{opacity:.5}}
#frp-app .-translate-x-1\/2{transform:translateX(-50%);}
#frp-app .absolute{position:absolute;}
#frp-app .animate-bounce{animation:frp-bounce 1s infinite;}
#frp-app .animate-pulse{animation:frp-pulse 2s cubic-bezier(0.4,0,0.6,1) infinite;}
#frp-app .animate-spin{animation:frp-spin 1s linear infinite;}
#frp-app .backdrop-blur-md{backdrop-filter:blur(12px);-webkit-backdrop-filter:blur(12px);}
#frp-app .bg-amber-400{background:#fbbf24;}
#frp-app .bg-amber-50{background:#fffbeb;}
#frp-app .bg-blue-100{background:#dbeafe;}
#frp-app .bg-blue-50{background:#eff6ff;}
#frp-app .bg-blue-600{background:#2563eb;}
#frp-app .bg-emergency-red{background:#dc2626;}
#frp-app .bg-gradient-to-br{background-image:linear-gradient(to bottom right,var(--frp-from,#00175c),var(--frp-via,var(--frp-to,#00288e)),var(--frp-to,#00288e));}
#frp-app .bg-gray-100{background:#f3f4f6;}
#frp-app .bg-gray-200{background:#e5e7eb;}
#frp-app .bg-green-100{background:#dcfce7;}
#frp-app .bg-green-500{background:#22c55e;}
#frp-app .bg-green-50{background:#f0fdf4;}
#frp-app .bg-green-600{background:#16a34a;}
#frp-app .bg-navy{background:#00175c;}
#frp-app .glass-nav{background:rgba(255,255,255,0.95);backdrop-filter:blur(12px);-webkit-backdrop-filter:blur(12px);border-bottom:1px solid rgba(0,23,92,0.08);}
#frp-app .glass-nav #mobile-menu{display:none;}
#frp-app .glass-nav #mobile-menu.open{display:block;}
#frp-app .bg-primary-blue{background:#00288e;}
#frp-app .bg-red-100{background:#fee2e2;}
#frp-app .bg-red-500{background:#ef4444;}
#frp-app .bg-red-50{background:#fef2f2;}
#frp-app .bg-red-600{background:#dc2626;}
#frp-app .bg-surface-container{background:#e6eeff;}
#frp-app .bg-surface-low{background:#eff4ff;}
#frp-app .bg-surface{background:#f8f9ff;}
#frp-app .bg-white\/10{background:rgba(255,255,255,0.1);}
#frp-app .bg-white\/15{background:rgba(255,255,255,0.15);}
#frp-app .bg-white\/90{background:rgba(255,255,255,0.9);}
#frp-app .bg-white{background:#fff;}
#frp-app .bg-yellow-400{background:#facc15;}
#frp-app .block{display:block;}
#frp-app .border-2{border:2px solid;}
#frp-app .border-amber-200{border-color:#fde68a;}
#frp-app .border-b-4{border-bottom:4px solid;}
#frp-app .border-blue-200{border-color:#bfdbfe;}
#frp-app .border-b{border-bottom:1px solid;}
#frp-app .border-emergency-red{border-color:#dc2626;}
#frp-app .border-gray-100{border-color:#f3f4f6;}
#frp-app .border-gray-200{border-color:#e5e7eb;}
#frp-app .border-green-200{border-color:#bbf7d0;}
#frp-app .border-navy{border-color:#00175c;}
#frp-app .border-primary-blue{border-color:#00288e;}
#frp-app .border-red-200{border-color:#fecaca;}
#frp-app .border-surface-container{border-color:#e6eeff;}
#frp-app .border-t{border-top:1px solid;}
#frp-app .border-white\/10{border-color:rgba(255,255,255,0.1);}
#frp-app .border-white\/20{border-color:rgba(255,255,255,0.2);}
#frp-app .border-white{border-color:#fff;}
#frp-app .border{border:1px solid;}
#frp-app .bottom-0{bottom:0;}
#frp-app .bottom-8{bottom:2rem;}
#frp-app .divide-x>*+*{border-left:1px solid #e5e7eb;}
#frp-app .fixed{position:fixed;}
#frp-app .flex-1{flex:1 1 0%;}
#frp-app .flex-col{flex-direction:column;}
#frp-app .flex-shrink-0{flex-shrink:0;}
#frp-app .flex-wrap{flex-wrap:wrap;}
#frp-app .flex{display:flex;}
#frp-app .font-bold{font-weight:700;}
#frp-app .font-extrabold{font-weight:800;}
#frp-app .font-headline{font-family:'Manrope',sans-serif;}
#frp-app .font-inter{font-family:'Inter',sans-serif;}
#frp-app .font-manrope{font-family:'Manrope',sans-serif;}
#frp-app .font-medium{font-weight:500;}
#frp-app .font-semibold{font-weight:600;}
#frp-app .from-navy{--frp-from:#00175c;}
#frp-app .gap-10{gap:2.5rem;}
#frp-app .gap-1\.5{gap:0.375rem;}
#frp-app .gap-1{gap:0.25rem;}
#frp-app .gap-2\.5{gap:0.625rem;}
#frp-app .gap-2{gap:0.5rem;}
#frp-app .gap-3\.5{gap:0.875rem;}
#frp-app .gap-3{gap:0.75rem;}
#frp-app .gap-4{gap:1rem;}
#frp-app .gap-5{gap:1.25rem;}
#frp-app .gap-6{gap:1.5rem;}
#frp-app .gap-7{gap:1.75rem;}
#frp-app .gap-8{gap:2rem;}
#frp-app .grid-cols-1{grid-template-columns:repeat(1,minmax(0,1fr));}
#frp-app .grid-cols-2{grid-template-columns:repeat(2,minmax(0,1fr));}
#frp-app .grid{display:grid;}
#frp-app .h-0\.5{height:0.125rem;}
#frp-app .h-10{height:2.5rem;}
#frp-app .h-12{height:3rem;}
#frp-app .h-14{height:3.5rem;}
#frp-app .h-16{height:4rem;}
#frp-app .h-1\.5{height:0.375rem;}
#frp-app .h-2{height:0.5rem;}
#frp-app .h-3\.5{height:0.875rem;}
#frp-app .h-3{height:0.75rem;}
#frp-app .h-5{height:1.25rem;}
#frp-app .h-8{height:2rem;}
#frp-app .h-9{height:2.25rem;}
#frp-app .hidden{display:none!important;}
#frp-app .hover\:bg-blue-50:hover{background:#eff6ff;}
#frp-app .hover\:bg-green-600:hover{background:#16a34a;}
#frp-app .hover\:bg-green-700:hover{background:#15803d;}
#frp-app .hover\:bg-navy:hover{background:#00175c;}
#frp-app .hover\:bg-red-700:hover{background:#b91c1c;}
#frp-app .hover\:bg-surface-low:hover{background:#eff4ff;}
#frp-app .hover\:bg-white\/20:hover{background:rgba(255,255,255,0.2);}
#frp-app .hover\:border-gray-300:hover{border-color:#d1d5db;}
#frp-app .hover\:border-primary-blue:hover{border-color:#00288e;}
#frp-app .hover\:shadow-md:hover{box-shadow:0 4px 12px rgba(0,23,92,.10);}
#frp-app .hover\:text-gray-600:hover{color:#4b5563;}
#frp-app .hover\:text-primary-blue:hover{color:#00288e;}
#frp-app .hover\:text-white:hover{color:#fff;}
#frp-app .hover\:underline:hover{text-decoration:underline;}
#frp-app .hover\:opacity-70:hover{opacity:.7;}
#frp-app .hover\:opacity-80:hover{opacity:.8;}
#frp-app .hover\:opacity-90:hover{opacity:.9;}
#frp-app .active\:scale-95:active{transform:scale(.95);}
#frp-app .inline-block{display:inline-block;}
#frp-app .inline-flex{display:inline-flex;}
#frp-app .inset-0{top:0;right:0;bottom:0;left:0;}
#frp-app .italic{font-style:italic;}
#frp-app .items-center{align-items:center;}
#frp-app .items-end{align-items:flex-end;}
#frp-app .items-start{align-items:flex-start;}
#frp-app .justify-between{justify-content:space-between;}
#frp-app .justify-center{justify-content:center;}
#frp-app .leading-relaxed{line-height:1.625;}
#frp-app .leading-tight{line-height:1.25;}
#frp-app .left-0{left:0;}
#frp-app .left-1\/2{left:50%;}
#frp-app .max-w-2xl{max-width:42rem;}
#frp-app .max-w-3xl{max-width:48rem;}
#frp-app .max-w-4xl{max-width:56rem;}
#frp-app .max-w-5xl{max-width:64rem;}
#frp-app .max-w-6xl{max-width:72rem;}
#frp-app .max-w-7xl{max-width:80rem;}
#frp-app .max-w-md{max-width:28rem;}
#frp-app .max-w-sm{max-width:24rem;}
#frp-app .max-w-xl{max-width:36rem;}
#frp-app .max-w-xs{max-width:20rem;}
#frp-app .mb-0\.5{margin-bottom:0.125rem;}
#frp-app .mb-1{margin-bottom:0.25rem;}
#frp-app .mb-10{margin-bottom:2.5rem;}
#frp-app .mb-12{margin-bottom:3rem;}
#frp-app .mb-14{margin-bottom:3.5rem;}
#frp-app .mb-2{margin-bottom:0.5rem;}
#frp-app .mb-20{margin-bottom:5rem;}
#frp-app .mb-3{margin-bottom:0.75rem;}
#frp-app .mb-4{margin-bottom:1rem;}
#frp-app .mb-5{margin-bottom:1.25rem;}
#frp-app .mb-6{margin-bottom:1.5rem;}
#frp-app .mb-8{margin-bottom:2rem;}
#frp-app .min-h-96{min-height:24rem;}
#frp-app .min-h-\[44px\]{min-height:44px;}
#frp-app .min-h-\[48px\]{min-height:48px;}
#frp-app .min-h-\[52px\]{min-height:52px;}
#frp-app .min-h-\[64px\]{min-height:64px;}
#frp-app .min-h-screen{min-height:100vh;}
#frp-app .min-w-0{min-width:0;}
#frp-app .ml-1{margin-left:0.25rem;}
#frp-app .ml-4{margin-left:1rem;}
#frp-app .ml-auto{margin-left:auto;}
#frp-app .mt-0\.5{margin-top:0.125rem;}
#frp-app .mt-1{margin-top:0.25rem;}
#frp-app .mt-1\.5{margin-top:0.375rem;}
#frp-app .mt-2{margin-top:0.5rem;}
#frp-app .mt-3{margin-top:0.75rem;}
#frp-app .mt-4{margin-top:1rem;}
#frp-app .mt-5{margin-top:1.25rem;}
#frp-app .mt-6{margin-top:1.5rem;}
#frp-app .mt-8{margin-top:2rem;}
#frp-app .mt-auto{margin-top:auto;}
#frp-app .mx-auto{margin-left:auto;margin-right:auto;}
#frp-app .outline-none{outline:none;}
#frp-app .overflow-hidden{overflow:hidden;}
#frp-app .overflow-x-auto{overflow-x:auto;}
#frp-app .p-1{padding:0.25rem;}
#frp-app .p-2{padding:0.5rem;}
#frp-app .p-4{padding:1rem;}
#frp-app .p-5{padding:1.25rem;}
#frp-app .p-6{padding:1.5rem;}
#frp-app .p-8{padding:2rem;}
#frp-app .pb-10{padding-bottom:2.5rem;}
#frp-app .pb-12{padding-bottom:3rem;}
#frp-app .pb-20{padding-bottom:5rem;}
#frp-app .pb-3{padding-bottom:0.75rem;}
#frp-app .pb-4{padding-bottom:1rem;}
#frp-app .pb-6{padding-bottom:1.5rem;}
#frp-app .pb-8{padding-bottom:2rem;}
#frp-app .pt-14{padding-top:3.5rem;}
#frp-app .pt-16{padding-top:4rem;}
#frp-app .pt-2{padding-top:0.5rem;}
#frp-app .pt-24{padding-top:6rem;}
#frp-app .pt-4{padding-top:1rem;}
#frp-app .pt-5{padding-top:1.25rem;}
#frp-app .pt-6{padding-top:1.5rem;}
#frp-app .px-2{padding-left:0.5rem;padding-right:0.5rem;}
#frp-app .px-2\.5{padding-left:0.625rem;padding-right:0.625rem;}
#frp-app .px-3{padding-left:0.75rem;padding-right:0.75rem;}
#frp-app .px-4{padding-left:1rem;padding-right:1rem;}
#frp-app .px-5{padding-left:1.25rem;padding-right:1.25rem;}
#frp-app .px-6{padding-left:1.5rem;padding-right:1.5rem;}
#frp-app .px-8{padding-left:2rem;padding-right:2rem;}
#frp-app .px-10{padding-left:2.5rem;padding-right:2.5rem;}
#frp-app .py-0\.5{padding-top:0.125rem;padding-bottom:0.125rem;}
#frp-app .py-1{padding-top:0.25rem;padding-bottom:0.25rem;}
#frp-app .py-1\.5{padding-top:0.375rem;padding-bottom:0.375rem;}
#frp-app .py-2{padding-top:0.5rem;padding-bottom:0.5rem;}
#frp-app .py-2\.5{padding-top:0.625rem;padding-bottom:0.625rem;}
#frp-app .py-3{padding-top:0.75rem;padding-bottom:0.75rem;}
#frp-app .py-4{padding-top:1rem;padding-bottom:1rem;}
#frp-app .py-5{padding-top:1.25rem;padding-bottom:1.25rem;}
#frp-app .py-6{padding-top:1.5rem;padding-bottom:1.5rem;}
#frp-app .py-10{padding-top:2.5rem;padding-bottom:2.5rem;}
#frp-app .py-12{padding-top:3rem;padding-bottom:3rem;}
#frp-app .py-16{padding-top:4rem;padding-bottom:4rem;}
#frp-app .py-20{padding-top:5rem;padding-bottom:5rem;}
#frp-app .py-24{padding-top:6rem;padding-bottom:6rem;}
#frp-app .relative{position:relative;}
#frp-app .right-0{right:0;}
#frp-app .right-4{right:1rem;}
#frp-app .rounded{border-radius:.25rem;}
#frp-app .rounded-2xl{border-radius:1rem;}
#frp-app .rounded-full{border-radius:9999px;}
#frp-app .rounded-lg{border-radius:.5rem;}
#frp-app .rounded-xl{border-radius:.75rem;}
#frp-app .self-center{align-self:center;}
#frp-app .self-start{align-self:flex-start;}
#frp-app .shadow-lg{box-shadow:0 10px 25px rgba(0,23,92,.18);}
#frp-app .shadow-sm{box-shadow:0 1px 2px rgba(0,0,0,.05);}
#frp-app .shadow-xl{box-shadow:0 20px 40px rgba(0,23,92,.22);}
#frp-app .space-y-1>*+*{margin-top:0.25rem;}
#frp-app .space-y-2>*+*{margin-top:0.5rem;}
#frp-app .space-y-3>*+*{margin-top:0.75rem;}
#frp-app .space-y-4>*+*{margin-top:1rem;}
#frp-app .space-y-6>*+*{margin-top:1.5rem;}
#frp-app .space-y-10>*+*{margin-top:2.5rem;}
#frp-app .sticky{position:sticky;}
#frp-app .text-2xl{font-size:1.5rem;line-height:2rem;}
#frp-app .text-3xl{font-size:1.875rem;line-height:2.25rem;}
#frp-app .text-4xl{font-size:2.25rem;line-height:2.5rem;}
#frp-app .text-5xl{font-size:3rem;line-height:1;}
#frp-app .text-amber-700{color:#b45309;}
#frp-app .text-amber-800{color:#92400e;}
#frp-app .text-base{font-size:1rem;line-height:1.5rem;}
#frp-app .text-blue-100{color:#dbeafe;}
#frp-app .text-blue-200{color:#bfdbfe;}
#frp-app .text-blue-300{color:#93c5fd;}
#frp-app .text-blue-400{color:#60a5fa;}
#frp-app .text-blue-600{color:#2563eb;}
#frp-app .text-blue-700{color:#1d4ed8;}
#frp-app .text-center{text-align:center;}
#frp-app .text-gray-300{color:#d1d5db;}
#frp-app .text-gray-400{color:#9ca3af;}
#frp-app .text-gray-500{color:#6b7280;}
#frp-app .text-gray-600{color:#4b5563;}
#frp-app .text-gray-700{color:#374151;}
#frp-app .text-green-600{color:#16a34a;}
#frp-app .text-green-700{color:#15803d;}
#frp-app .text-lg{font-size:1.125rem;line-height:1.75rem;}
#frp-app .text-navy{color:#00175c;}
#frp-app .text-on-surface{color:#0b1c30;}
#frp-app .text-primary-blue{color:#00288e;}
#frp-app .text-red-600{color:#dc2626;}
#frp-app .text-red-700{color:#b91c1c;}
#frp-app .text-sm{font-size:.875rem;line-height:1.25rem;}
#frp-app .text-white{color:#fff;}
#frp-app .text-white\/50{color:rgba(255,255,255,0.5);}
#frp-app .text-white\/75{color:rgba(255,255,255,0.75);}
#frp-app .text-white\/80{color:rgba(255,255,255,0.8);}
#frp-app .text-white\/90{color:rgba(255,255,255,0.9);}
#frp-app .text-xl{font-size:1.25rem;line-height:1.75rem;}
#frp-app .text-xs{font-size:.75rem;line-height:1rem;}
#frp-app .text-yellow-400{color:#facc15;}
#frp-app .to-secondary{--frp-to:#0058be;}
#frp-app .top-0{top:0;}
#frp-app .top-4{top:1rem;}
#frp-app .top-8{top:2rem;}
#frp-app .top-16{top:4rem;}
#frp-app .tracking-wide{letter-spacing:.025em;}
#frp-app .tracking-wider{letter-spacing:.05em;}
#frp-app .transition{transition:all .2s ease;}
#frp-app .transition-all{transition:all .2s ease;}
#frp-app .transition-colors{transition:color .2s ease,background-color .2s ease,border-color .2s ease;}
#frp-app .transition-shadow{transition:box-shadow .2s ease;}
#frp-app .underline{text-decoration:underline;}
#frp-app .uppercase{text-transform:uppercase;}
#frp-app .via-primary{--frp-via:#00288e;}
#frp-app .w-2{width:0.5rem;}
#frp-app .w-8{width:2rem;}
#frp-app .w-9{width:2.25rem;}
#frp-app .w-10{width:2.5rem;}
#frp-app .w-12{width:3rem;}
#frp-app .w-14{width:3.5rem;}
#frp-app .w-16{width:4rem;}
#frp-app .w-full{width:100%;}
#frp-app .w-1\/2{width:50%;}
#frp-app .whitespace-nowrap{white-space:nowrap;}
#frp-app .z-10{z-index:10;}
#frp-app .z-40{z-index:40;}
#frp-app .z-50{z-index:50;}
#frp-app .z-\[100\]{z-index:100;}
#frp-app .z-\[200\]{z-index:200;}
@media(min-width:640px){
  #frp-app .sm\:block{display:block;}
  #frp-app .sm\:flex-row{flex-direction:row;}
  #frp-app .sm\:gap-6{gap:1.5rem;}
  #frp-app .sm\:grid-cols-2{grid-template-columns:repeat(2,minmax(0,1fr));}
  #frp-app .sm\:hidden{display:none!important;}
  #frp-app .sm\:items-center{align-items:center;}
  #frp-app .sm\:justify-between{justify-content:space-between;}
  #frp-app .sm\:px-6{padding-left:1.5rem;padding-right:1.5rem;}
  #frp-app .sm\:text-xl{font-size:1.25rem;line-height:1.75rem;}
  #frp-app .sm\:w-auto{width:auto;}
}
@media(min-width:768px){
  #frp-app .md\:block{display:block;}
  #frp-app .md\:col-span-1{grid-column:span 1/span 1;}
  #frp-app .md\:col-span-2{grid-column:span 2/span 2;}
  #frp-app .md\:flex{display:flex;}
  #frp-app .md\:flex-row{flex-direction:row;}
  #frp-app .md\:grid-cols-2{grid-template-columns:repeat(2,minmax(0,1fr));}
  #frp-app .md\:grid-cols-3{grid-template-columns:repeat(3,minmax(0,1fr));}
  #frp-app .md\:hidden{display:none!important;}
  #frp-app .md\:inline-flex{display:inline-flex;}
  #frp-app .md\:items-end{align-items:flex-end;}
  #frp-app .md\:justify-between{justify-content:space-between;}
  #frp-app .md\:pb-0{padding-bottom:0;}
  #frp-app .md\:text-5xl{font-size:3rem;line-height:1;}
}
@media(min-width:1024px){
  #frp-app .lg\:col-span-2{grid-column:span 2/span 2;}
  #frp-app .lg\:gap-12{gap:3rem;}
  #frp-app .lg\:grid-cols-3{grid-template-columns:repeat(3,minmax(0,1fr));}
  #frp-app .lg\:px-8{padding-left:2rem;padding-right:2rem;}
}
*{box-sizing:border-box;margin:0;padding:0;}
body{font-family:'Inter',sans-serif;color:#0b1c30;background:#f8f9ff;}
h1,h2,h3,h4{font-family:'Manrope',sans-serif;}
.material-symbols-outlined{font-variation-settings:'FILL' 0,'wght' 400,'GRAD' 0,'opsz' 24;vertical-align:middle;line-height:1;}
</style>

<?php wp_head(); ?>
</head>
<body <?php body_class('frp-pro-page'); ?>>
<?php wp_body_open(); ?>

<div id="frp-app">

<nav class="glass-nav fixed top-0 left-0 right-0 w-full z-50">
  <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
    <div class="flex items-center justify-between" style="height:4rem;">
      <a href="<?php echo home_url('/'); ?>" class="flex items-center gap-2 flex-shrink-0">
        <div class="w-8 h-8 bg-navy rounded-lg flex items-center justify-center">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none">
            <path d="M3 12L12 3L21 12V20C21 20.5523 20.5523 21 20 21H4C3.44772 21 3 20.5523 3 20V12Z" fill="white" opacity="0.9"/>
            <path d="M9 21V15C9 14.4477 9.44772 14 10 14H14C14.5523 14 15 14.4477 15 15V21" fill="#00175c"/>
            <path d="M12 7C13.6569 7 15 8.34315 15 10C15 11.6569 13.6569 13 12 13C10.3431 13 9 11.6569 9 10C9 8.34315 10.3431 7 12 7Z" fill="#dc2626"/>
          </svg>
        </div>
        <span class="font-manrope font-extrabold text-navy text-lg hidden sm:block">Find Restoration Pros</span>
        <span class="font-manrope font-extrabold text-navy text-base sm:hidden">FRP</span>
      </a>
      <div class="hidden md:flex items-center gap-6">
        <a href="<?php echo home_url('/services/'); ?>" class="text-sm font-medium text-on-surface hover:text-primary-blue transition-colors">Services</a>
        <a href="<?php echo home_url('/pros/'); ?>" class="text-sm font-medium text-on-surface hover:text-primary-blue transition-colors">Find a Pro</a>
        <a href="<?php echo home_url('/join/'); ?>" class="text-sm font-medium text-on-surface hover:text-primary-blue transition-colors">Join as a Pro</a>
      </div>
      <div class="flex items-center gap-3">
        <a href="<?php echo home_url('/'); ?>" class="bg-emergency-red text-white font-semibold text-sm px-4 py-2 rounded-lg hover:bg-red-700 transition-colors min-h-\[44px\] flex items-center">Get Help Now</a>
        <button onclick="document.getElementById('mobile-menu').classList.toggle('open')" class="md:hidden p-2 rounded-lg hover:bg-surface-low transition-colors" aria-label="Menu">
          <svg width="22" height="22" fill="none" viewBox="0 0 24 24" stroke="#00175c" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M4 6h16M4 12h16M4 18h16"/>
          </svg>
        </button>
      </div>
    </div>
    <div id="mobile-menu" class="md:hidden border-t border-surface-container pb-4 pt-2">
      <div class="flex flex-col gap-1">
        <a href="<?php echo home_url('/services/'); ?>" class="px-3 py-3 text-sm font-medium text-on-surface hover:bg-surface-low rounded-lg transition-colors">Services</a>
        <a href="<?php echo home_url('/pros/'); ?>" class="px-3 py-3 text-sm font-medium text-on-surface hover:bg-surface-low rounded-lg transition-colors">Find a Pro</a>
        <a href="<?php echo home_url('/join/'); ?>" class="px-3 py-3 text-sm font-medium text-on-surface hover:bg-surface-low rounded-lg transition-colors">Join as a Pro</a>
      </div>
    </div>
  </div>
</nav>

<!-- Mobile sticky call bar -->
<?php if ( $phone ) : ?>
<div class="fixed bottom-0 left-0 right-0 z-50 md:hidden">
  <a href="<?php echo esc_url( home_url('/wp-json/frp/v1/call?company=' . $id . '&source=profile&path=profile') ); ?>"
     onclick="showCallModal()"
     class="w-full bg-green-600 text-white py-4 text-base font-extrabold font-headline flex items-center justify-center gap-2">
    <span class="material-symbols-outlined">phone</span> Call Now — Free
  </a>
</div>
<?php endif; ?>

<main class="pt-16 pb-20 md:pb-0">

  <!-- Hero -->
  <section class="relative min-h-96 flex items-end" style="background-image:url('<?php echo esc_url($hero_bg); ?>');background-size:cover;background-position:center;">
    <div class="absolute inset-0" style="background:linear-gradient(to top, rgba(0,23,92,0.95) 0%, rgba(0,23,92,0.5) 60%, rgba(0,23,92,0.15) 100%)"></div>
    <div class="relative z-10 w-full max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 pb-10 pt-24">
      <div class="flex flex-col md:flex-row md:items-end md:justify-between gap-6">
        <div>
          <?php if ( $verified ) : ?>
          <div class="flex flex-wrap gap-2 mb-3">
            <span class="inline-flex items-center gap-1 bg-green-500 text-white text-xs font-semibold px-3 py-1 rounded-full">
              <span class="material-symbols-outlined" style="font-size:14px">verified</span> Verified
            </span>
          </div>
          <?php endif; ?>
          <h1 class="text-4xl md:text-5xl font-headline font-extrabold text-white mb-2"><?php echo esc_html($name); ?></h1>
          <p class="text-white/80 text-lg">
            <?php if ( $city ) : ?>
            <span><?php echo esc_html($city . ', ' . $state); ?></span>
            <?php endif; ?>
            <?php if ( $response ) : ?>
            <span class="ml-4">· <?php echo esc_html($response); ?> response</span>
            <?php endif; ?>
          </p>
        </div>
        <?php if ( $phone ) : ?>
        <a href="<?php echo esc_url( home_url('/wp-json/frp/v1/call?company=' . $id . '&source=profile&path=profile') ); ?>"
           onclick="showCallModal(); return false;"
           class="hidden md:flex items-center gap-3 bg-green-500 hover:bg-green-600 text-white px-8 py-4 rounded-xl text-lg font-extrabold font-headline transition-all shadow-lg whitespace-nowrap">
          <span class="material-symbols-outlined text-2xl">phone</span> Call Now — Free
        </a>
        <?php endif; ?>
      </div>
    </div>
  </section>

  <!-- Stats bar -->
  <section class="bg-white border-b border-gray-100">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
      <div class="grid divide-x" style="grid-template-columns: repeat(<?php echo ($yelp_rating ? 4 : 3); ?>, minmax(0,1fr));">
        <?php if ( $yelp_rating ) : ?>
        <div class="py-5 text-center">
          <div class="text-xs font-semibold text-gray-400 uppercase tracking-wide mb-1">Yelp</div>
          <div class="text-2xl font-headline font-extrabold" style="color:#0b1c30"><?php echo number_format($yelp_rating,1); ?> ★</div>
          <div class="text-xs text-gray-500 mt-0.5"><?php echo $yelp_count; ?> reviews</div>
        </div>
        <?php endif; ?>
        <div class="py-5 text-center">
          <div class="text-xs font-semibold text-gray-400 uppercase tracking-wide mb-1">Google</div>
          <div class="text-2xl font-headline font-extrabold" style="color:#0b1c30">
            <?php echo $rating ? number_format($rating,1) . ' ★' : '—'; ?>
          </div>
          <div class="text-xs text-gray-500 mt-0.5"><?php echo $review_count ? $review_count . ' reviews' : 'No reviews yet'; ?></div>
        </div>
        <div class="py-5 text-center">
          <div class="text-xs font-semibold text-gray-400 uppercase tracking-wide mb-1">Response</div>
          <div class="text-2xl font-headline font-extrabold" style="color:#0b1c30"><?php echo esc_html($response ?: '< 1 hr'); ?></div>
          <div class="text-xs text-gray-500 mt-0.5">Emergency dispatch</div>
        </div>
        <div class="py-5 text-center">
          <div class="text-xs font-semibold text-gray-400 uppercase tracking-wide mb-1">Experience</div>
          <div class="text-2xl font-headline font-extrabold" style="color:#0b1c30"><?php echo $years ? $years . ' yrs' : '—'; ?></div>
          <div class="text-xs text-gray-500 mt-0.5">In business</div>
        </div>
      </div>
    </div>
  </section>

  <!-- Main content -->
  <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-10">
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-10">

      <!-- Left column -->
      <div class="lg:col-span-2 space-y-10">

        <!-- Services -->
        <?php if ( ! empty($service_labels) ) : ?>
        <section>
          <h2 class="text-xl font-headline font-extrabold mb-4" style="color:#0b1c30">Services Offered</h2>
          <div class="flex flex-wrap gap-2">
            <?php foreach ( $service_labels as $svc ) : ?>
            <span class="inline-flex items-center px-4 py-2 rounded-full text-sm font-semibold bg-blue-50 text-primary-blue border border-blue-200"><?php echo esc_html($svc); ?></span>
            <?php endforeach; ?>
          </div>
        </section>
        <?php endif; ?>

        <!-- Featured Video (if youtube_url present) -->
        <?php if ( $youtube_url ) :
          // Convert watch URL to embed URL
          $yt_embed = preg_replace('/watch\?v=/', 'embed/', $youtube_url);
          $yt_embed = preg_replace('/youtu\.be\//', 'www.youtube.com/embed/', $yt_embed);
        ?>
        <section>
          <h2 class="text-xl font-headline font-extrabold mb-4" style="color:#0b1c30">Watch Our Work</h2>
          <div class="rounded-2xl overflow-hidden" style="position:relative;padding-bottom:56.25%;height:0;background:#000">
            <iframe src="<?php echo esc_url($yt_embed); ?>" frameborder="0" allowfullscreen
              style="position:absolute;top:0;left:0;width:100%;height:100%;border-radius:1rem"></iframe>
          </div>
        </section>
        <?php endif; ?>

        <!-- About -->
        <?php if ( $description ) : ?>
        <section>
          <h2 class="text-xl font-headline font-extrabold mb-4" style="color:#0b1c30">About <?php echo esc_html($name); ?></h2>
          <div class="space-y-3 leading-relaxed" style="color:#444652">
            <?php echo wpautop( esc_html($description) ); ?>
          </div>
        </section>
        <?php endif; ?>

        <!-- Reviews -->
        <section>
          <h2 class="text-xl font-headline font-extrabold mb-6" style="color:#0b1c30">Reviews</h2>
          <div class="grid grid-cols-1 <?php echo $yelp_url ? 'md:grid-cols-2' : ''; ?> gap-6">

            <?php if ( $yelp_url ) : ?>
            <!-- Yelp column -->
            <div>
              <div class="flex items-center gap-2 mb-4 pb-3 border-b border-gray-100">
                <span class="font-headline font-extrabold text-sm text-gray-700">Top Reviews · Yelp</span>
              </div>
              <div class="rounded-xl p-5 text-center" style="background:#fff7f0;border:1px solid #fddcbe">
                <div class="text-3xl mb-2">⭐</div>
                <p class="font-semibold mb-3" style="color:#0b1c30">
                  <?php echo $yelp_count ? $yelp_count . ' reviews on Yelp' : 'Reviews on Yelp'; ?>
                </p>
                <a href="<?php echo esc_url($yelp_url); ?>" target="_blank" rel="noopener"
                   class="inline-flex items-center gap-2 text-sm font-semibold px-4 py-2 rounded-lg"
                   style="background:#fddcbe;color:#c2410c">
                  View on Yelp <span class="material-symbols-outlined text-base">open_in_new</span>
                </a>
              </div>
            </div>
            <?php endif; ?>

            <!-- Google column -->
            <div>
              <div class="flex items-center gap-2 mb-4 pb-3 border-b border-gray-100">
                <span class="font-headline font-extrabold text-sm text-gray-700">Google Reviews</span>
              </div>
              <div class="rounded-xl p-5 text-center" style="background:#f8f9ff;border:1px solid #e6eeff">
                <div class="text-3xl mb-2">⭐</div>
                <p class="font-semibold mb-3" style="color:#0b1c30">
                  <?php echo $review_count ? $review_count . ' reviews on Google' : 'Reviews on Google'; ?>
                  <?php echo $rating ? ' · ' . number_format($rating,1) . ' stars' : ''; ?>
                </p>
                <a href="<?php echo esc_url($google_maps_url); ?>" target="_blank" rel="noopener"
                   class="inline-flex items-center gap-2 text-sm font-semibold px-4 py-2 rounded-lg"
                   style="background:#e6eeff;color:#00288e">
                  View on Google Maps <span class="material-symbols-outlined text-base">open_in_new</span>
                </a>
              </div>
            </div>

          </div>
        </section>

        <!-- Browse other pros CTA -->
        <div class="rounded-2xl p-6" style="background:#eff4ff">
          <p class="text-sm mb-2" style="color:#444652">Not the right fit?</p>
          <a href="<?php echo home_url('/search/?city=' . urlencode($city)); ?>"
             class="inline-flex items-center gap-2 font-semibold hover:underline" style="color:#1d4ed8">
            Browse other restoration pros near you <span class="material-symbols-outlined text-base">arrow_forward</span>
          </a>
        </div>

      </div><!-- /left column -->

      <!-- Sidebar -->
      <div class="space-y-6">

        <!-- Call CTA card (desktop) -->
        <?php if ( $phone ) : ?>
        <div class="hidden md:block rounded-2xl p-6 text-center" style="background:#f0fdf4;border:1px solid #bbf7d0">
          <p class="text-sm mb-1" style="color:#444652">Ready to get help?</p>
          <p class="text-lg font-headline font-extrabold mb-4" style="color:#0b1c30">Call <?php echo esc_html($name); ?></p>
          <a href="<?php echo esc_url( home_url('/wp-json/frp/v1/call?company=' . $id . '&source=profile&path=profile') ); ?>"
             onclick="showCallModal(); return false;"
             class="w-full bg-green-600 hover:bg-green-700 text-white py-4 rounded-xl font-headline font-extrabold text-lg flex items-center justify-center gap-2 transition-colors">
            <span class="material-symbols-outlined">phone</span> Call Now — Free
          </a>
          <p class="text-xs mt-3" style="color:#444652">Direct call · No middleman · No referral fee</p>
        </div>
        <?php endif; ?>

        <!-- Contact & Coverage -->
        <div class="bg-white rounded-2xl p-6 space-y-3" style="border:1px solid #e6eeff">
          <h3 class="font-headline font-extrabold" style="color:#0b1c30">Contact &amp; Coverage</h3>
          <div class="space-y-3 text-sm">
            <?php if ( $phone ) : ?>
            <div class="flex items-center gap-2">
              <span class="material-symbols-outlined text-primary-blue" style="font-size:18px">phone</span>
              <a href="tel:<?php echo esc_attr($phone_clean); ?>" class="hover:underline text-primary-blue font-medium"><?php echo esc_html($phone); ?></a>
            </div>
            <?php endif; ?>
            <?php if ( $address ) : ?>
            <div class="flex items-start gap-2">
              <span class="material-symbols-outlined text-gray-400" style="font-size:18px;margin-top:1px">location_on</span>
              <span style="color:#444652"><?php echo esc_html($address); ?></span>
            </div>
            <?php endif; ?>
            <?php if ( $city ) : ?>
            <div class="flex items-center gap-2">
              <span class="material-symbols-outlined text-gray-400" style="font-size:18px">map</span>
              <span style="color:#444652"><?php echo esc_html($city . ', ' . $state); ?></span>
            </div>
            <?php endif; ?>
            <?php if ( $zip_codes ) : ?>
            <div class="flex items-start gap-2">
              <span class="material-symbols-outlined text-gray-400" style="font-size:18px;margin-top:1px">pin_drop</span>
              <span style="color:#444652">Serves: <?php echo esc_html($zip_codes); ?></span>
            </div>
            <?php endif; ?>
            <?php if ( $website ) : ?>
            <div class="flex items-center gap-2">
              <span class="material-symbols-outlined text-gray-400" style="font-size:18px">language</span>
              <a href="<?php echo esc_url($website); ?>" target="_blank" rel="noopener" class="hover:underline text-primary-blue">
                <?php echo esc_html(preg_replace('#^https?://#', '', rtrim($website, '/'))); ?>
              </a>
            </div>
            <?php endif; ?>
            <?php if ( $license_no ) : ?>
            <div class="flex items-center gap-2">
              <span class="material-symbols-outlined text-gray-400" style="font-size:18px">badge</span>
              <span style="color:#444652">License #<?php echo esc_html($license_no); ?></span>
            </div>
            <?php endif; ?>
          </div>
        </div>

        <!-- Certifications -->
        <?php if ( $certs ) :
          $cert_list = array_filter( array_map( 'trim', explode(',', $certs) ) );
        ?>
        <div class="bg-white rounded-2xl p-6" style="border:1px solid #e6eeff">
          <h3 class="font-headline font-extrabold mb-4" style="color:#0b1c30">Certifications</h3>
          <div class="space-y-2 text-sm">
            <?php foreach ( $cert_list as $cert ) : ?>
            <div class="flex items-center gap-2">
              <span class="material-symbols-outlined text-green-600" style="font-size:16px">verified</span>
              <span style="color:#444652"><?php echo esc_html($cert); ?></span>
            </div>
            <?php endforeach; ?>
          </div>
        </div>
        <?php endif; ?>

        <!-- Second opinion CTA -->
        <div class="text-white rounded-2xl p-6 text-center" style="background:#00175c">
          <h3 class="font-headline font-extrabold mb-2">Need a second opinion?</h3>
          <p class="text-sm mb-4" style="color:rgba(255,255,255,0.7)">You have the right to choose your own contractor. Compare 2–3 estimates.</p>
          <a href="<?php echo home_url('/search/?city=' . urlencode($city)); ?>"
             class="inline-block bg-white font-bold text-sm px-5 py-2.5 rounded-lg hover:opacity-90 transition-colors" style="color:#00175c">
            Browse other pros →
          </a>
        </div>

      </div><!-- /sidebar -->
    </div><!-- /grid -->
  </div><!-- /max-w-7xl -->

</main>

<!-- Post-call Modal -->
<div id="call-modal" class="fixed inset-0 z-\[100\] hidden items-center justify-center p-4" style="display:none;">
  <div class="absolute inset-0 backdrop-blur-sm" style="background:rgba(0,0,0,0.5)" onclick="closeCallModal()"></div>
  <div class="relative bg-white rounded-2xl p-8 max-w-md w-full shadow-2xl">
    <button onclick="closeCallModal()" class="absolute top-4 right-4 text-gray-400 hover:text-gray-600">
      <span class="material-symbols-outlined">close</span>
    </button>
    <div class="text-3xl mb-3 text-center">📞</div>
    <h3 class="text-xl font-headline font-extrabold text-center mb-2">We'll check in to make sure you got help</h3>
    <p class="text-sm text-center mb-6" style="color:#444652">Enter your contact info and we'll follow up to make sure you're taken care of.</p>
    <div id="modal-form-area">
      <div class="space-y-3 mb-4">
        <input id="modal-email" type="email" placeholder="Email address"
          class="w-full border border-gray-200 rounded-lg px-4 py-3 text-sm outline-none"
          style="width:100%;border:1px solid #e5e7eb;border-radius:.5rem;padding:.75rem 1rem;font-size:.875rem;">
        <input id="modal-phone" type="tel" placeholder="Phone number (optional)"
          style="width:100%;border:1px solid #e5e7eb;border-radius:.5rem;padding:.75rem 1rem;font-size:.875rem;">
      </div>
      <button onclick="submitCallModal()" class="w-full text-white py-3 rounded-xl font-extrabold text-sm mb-3 hover:opacity-90 transition-colors"
        style="background:#00175c;width:100%;border:none;cursor:pointer;">Yes, follow up with me</button>
      <button onclick="closeCallModal()" class="w-full text-sm py-2 hover:opacity-70"
        style="background:none;border:none;cursor:pointer;color:#444652;width:100%;">Skip — I'm all set</button>
    </div>
    <div id="modal-success" class="hidden text-center py-4">
      <div class="text-4xl mb-3">✅</div>
      <p class="font-headline font-extrabold">Got it! We'll check in soon.</p>
      <p class="text-sm mt-2" style="color:#444652">You'll hear from us within 24 hours.</p>
    </div>
  </div>
</div>

<footer class="bg-navy text-white px-4 pt-14 pb-6">
  <div class="max-w-6xl mx-auto">
    <div class="grid grid-cols-1 sm:grid-cols-2 gap-10 mb-10">
      <div>
        <div class="flex items-center gap-2 mb-4">
          <div class="w-8 h-8 rounded-lg flex items-center justify-center border" style="background:rgba(255,255,255,0.1);border-color:rgba(255,255,255,0.2)">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none">
              <path d="M3 12L12 3L21 12V20C21 20.5523 20.5523 21 20 21H4C3.44772 21 3 20.5523 3 20V12Z" fill="white" opacity="0.8"/>
              <path d="M9 21V15C9 14.4477 9.44772 14 10 14H14C14.5523 14 15 14.4477 15 15V21" fill="#00175c"/>
              <path d="M12 7C13.6569 7 15 8.34315 15 10C15 11.6569 13.6569 13 12 13C10.3431 13 9 11.6569 9 10C9 8.34315 10.3431 7 12 7Z" fill="#dc2626"/>
            </svg>
          </div>
          <span class="font-manrope font-extrabold text-xl text-white">Find Restoration Pros</span>
        </div>
        <p class="text-sm leading-relaxed max-w-xs" style="color:#bfdbfe">Connecting homeowners and property managers with vetted local restoration experts — 24 hours a day, 7 days a week.</p>
      </div>
      <div class="grid grid-cols-2 gap-6">
        <div>
          <h4 class="font-manrope font-bold text-sm uppercase tracking-wider mb-3" style="color:#93c5fd">Directory</h4>
          <ul class="space-y-2">
            <li><a href="<?php echo home_url('/services/'); ?>" class="text-sm hover:text-white transition-colors" style="color:#bfdbfe">Services</a></li>
            <li><a href="<?php echo home_url('/pros/'); ?>" class="text-sm hover:text-white transition-colors" style="color:#bfdbfe">Find a Pro</a></li>
            <li><a href="<?php echo home_url('/join/'); ?>" class="text-sm hover:text-white transition-colors" style="color:#bfdbfe">Join as a Pro</a></li>
          </ul>
        </div>
        <div>
          <h4 class="font-manrope font-bold text-sm uppercase tracking-wider mb-3" style="color:#93c5fd">Legal</h4>
          <ul class="space-y-2">
            <li><a href="<?php echo home_url('/privacy/'); ?>" class="text-sm hover:text-white transition-colors" style="color:#bfdbfe">Privacy Policy</a></li>
            <li><a href="<?php echo home_url('/terms/'); ?>" class="text-sm hover:text-white transition-colors" style="color:#bfdbfe">Terms of Service</a></li>
            <li><a href="<?php echo home_url('/contact/'); ?>" class="text-sm hover:text-white transition-colors" style="color:#bfdbfe">Contact Us</a></li>
          </ul>
        </div>
      </div>
    </div>
    <div class="border-t pt-5 flex flex-col sm:flex-row items-center justify-between gap-2" style="border-color:rgba(255,255,255,0.1)">
      <p class="text-xs" style="color:#93c5fd">&copy; <?php echo date('Y'); ?> FindRestorationPros.com. All rights reserved.</p>
      <p class="text-xs" style="color:#60a5fa">Not an insurance company. We do not provide restoration services directly.</p>
    </div>
  </div>
</footer>

</div><!-- #frp-app -->

<script>
var FRP_COMPANY_ID = <?php echo (int) $id; ?>;
var FRP_COMPANY_NAME = '<?php echo esc_js($name); ?>';
var FRP_CALL_URL = '<?php echo esc_js( home_url('/wp-json/frp/v1/call?company=' . $id . '&source=profile&path=profile') ); ?>';

function showCallModal() {
  var m = document.getElementById('call-modal');
  m.style.display = 'flex';
  // Trigger the actual call
  window.location.href = FRP_CALL_URL;
  setTimeout(function() {
    // Modal stays open after redirect completes
  }, 500);
}

function closeCallModal() {
  var m = document.getElementById('call-modal');
  m.style.display = 'none';
}

function submitCallModal() {
  var email = document.getElementById('modal-email').value.trim();
  var phone = document.getElementById('modal-phone').value.trim();
  if (!email && !phone) { alert('Please enter an email or phone number, or click Skip.'); return; }
  document.getElementById('modal-form-area').style.display = 'none';
  document.getElementById('modal-success').style.display = 'block';
  // Log lead via REST API
  if (email || phone) {
    fetch('<?php echo esc_js( home_url('/wp-json/frp/v1/lead') ); ?>', {
      method: 'POST',
      headers: {'Content-Type': 'application/json'},
      body: JSON.stringify({company_id: FRP_COMPANY_ID, email: email, phone: phone, source: 'profile-modal'})
    }).catch(function(){});
  }
  setTimeout(closeCallModal, 3000);
}
</script>

<?php wp_footer(); ?>
</body>
</html>
