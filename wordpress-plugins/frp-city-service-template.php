<?php
/**
 * FRP City + Service Landing Page Template
 * Served for /{service-slug}/{city-slug}/ URLs.
 * Renders top pros for that service+city combination — fully server-rendered.
 *
 * Upload to: wp-content/mu-plugins/frp-city-service-template.php
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

$service_slug = get_query_var( 'frp_service_page', '' );
$city_slug    = get_query_var( 'frp_city_page', '' );

if ( ! $service_slug || ! $city_slug ) {
    global $wp_query;
    $wp_query->set_404();
    status_header(404);
    include( get_404_template() );
    exit;
}

// Map URL service slug → CPT service slug + display label
$service_url_map = [
    'water-damage-restoration'  => [ 'slug' => 'water-damage',     'label' => 'Water Damage Restoration' ],
    'fire-damage-restoration'   => [ 'slug' => 'fire-damage',       'label' => 'Fire & Smoke Damage Restoration' ],
    'mold-remediation'          => [ 'slug' => 'mold-remediation',  'label' => 'Mold Remediation' ],
    'storm-damage-repair'       => [ 'slug' => 'storm-damage',      'label' => 'Storm Damage Repair' ],
    'sewage-cleanup'            => [ 'slug' => 'sewage-cleanup',    'label' => 'Sewage Cleanup' ],
    'biohazard-cleanup'         => [ 'slug' => 'biohazard-cleanup', 'label' => 'Biohazard Cleanup' ],
    'structural-restoration'    => [ 'slug' => 'structural',        'label' => 'Structural Restoration' ],
];

if ( ! isset( $service_url_map[ $service_slug ] ) ) {
    global $wp_query;
    $wp_query->set_404();
    status_header(404);
    include( get_404_template() );
    exit;
}

$service_meta  = $service_url_map[ $service_slug ]['slug'];
$service_label = $service_url_map[ $service_slug ]['label'];
$city_display  = ucwords( str_replace( '-', ' ', $city_slug ) );

// Query active pros that offer this service
$pros_query = new WP_Query([
    'post_type'      => 'restoration_pro',
    'post_status'    => 'publish',
    'posts_per_page' => 20,
    'meta_query'     => [
        'relation' => 'AND',
        [
            'key'     => 'services',
            'value'   => $service_meta,
            'compare' => 'LIKE',
        ],
        [
            'key'     => 'listing_status',
            'value'   => 'active',
            'compare' => '=',
        ],
    ],
    'meta_key' => 'google_rating',
    'orderby'  => 'meta_value_num',
    'order'    => 'DESC',
]);

// Filter by city slug match
$pros = [];
if ( $pros_query->have_posts() ) {
    while ( $pros_query->have_posts() ) {
        $pros_query->the_post();
        $pid        = get_the_ID();
        $pro_city   = sanitize_title( get_post_meta( $pid, 'city',   true ) );
        $pro_county = sanitize_title( get_post_meta( $pid, 'county', true ) );
        if ( $pro_city === $city_slug || $pro_county === $city_slug ) {
            $pros[] = [
                'id'           => $pid,
                'name'         => get_the_title(),
                'slug'         => get_post_field( 'post_name', $pid ),
                'phone'        => get_post_meta( $pid, 'phone',               true ),
                'city'         => get_post_meta( $pid, 'city',                true ),
                'rating'       => (float) get_post_meta( $pid, 'google_rating',       true ),
                'review_count' => (int)   get_post_meta( $pid, 'google_review_count', true ),
                'verified'     => (bool)  get_post_meta( $pid, 'verified',            true ),
                'city_slug'    => frp_get_pro_city_slug( $pid ),
            ];
        }
    }
    wp_reset_postdata();
}

// Page metadata
$canonical  = home_url( '/' . $service_slug . '/' . $city_slug . '/' );
$page_title = "{$service_label} in {$city_display}, CA | FindRestorationPros";
$meta_desc  = "Find the best {$service_label} contractors in {$city_display}, California. Verified local pros, free estimates, 24/7 emergency response. Compare ratings and call now.";
$pros_count = count( $pros );

// Schema.org: ItemList
$schema_list = [
    '@context'        => 'https://schema.org',
    '@type'           => 'ItemList',
    'name'            => "{$service_label} Contractors in {$city_display}, CA",
    'description'     => $meta_desc,
    'numberOfItems'   => $pros_count,
    'itemListElement' => [],
];
foreach ( $pros as $i => $pro ) {
    $schema_list['itemListElement'][] = [
        '@type'    => 'ListItem',
        'position' => $i + 1,
        'url'      => home_url( '/pros/' . $pro['city_slug'] . '/' . $pro['slug'] . '/' ),
        'name'     => $pro['name'],
    ];
}

// Schema.org: BreadcrumbList
$breadcrumb_schema = [
    '@context'        => 'https://schema.org',
    '@type'           => 'BreadcrumbList',
    'itemListElement' => [
        [ '@type' => 'ListItem', 'position' => 1, 'name' => 'Home',         'item' => home_url('/') ],
        [ '@type' => 'ListItem', 'position' => 2, 'name' => $service_label, 'item' => home_url('/' . $service_slug . '/') ],
        [ '@type' => 'ListItem', 'position' => 3, 'name' => $city_display,  'item' => $canonical ],
    ],
];
?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo('charset'); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo esc_html( $page_title ); ?></title>
<meta name="description" content="<?php echo esc_attr( $meta_desc ); ?>">
<link rel="canonical" href="<?php echo esc_url( $canonical ); ?>">
<meta property="og:title"       content="<?php echo esc_attr( $page_title ); ?>">
<meta property="og:description" content="<?php echo esc_attr( $meta_desc ); ?>">
<meta property="og:url"         content="<?php echo esc_url( $canonical ); ?>">
<script type="application/ld+json"><?php echo wp_json_encode( $schema_list,       JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ); ?></script>
<script type="application/ld+json"><?php echo wp_json_encode( $breadcrumb_schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ); ?></script>

<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Manrope:wght@700;800&display=swap" rel="stylesheet">

<style>
html,body{margin:0!important;padding:0!important;background:#f8f9ff!important;}
html{margin-top:0!important;}
body.admin-bar{margin-top:0!important;padding-top:0!important;}
body{font-family:'Inter',system-ui,sans-serif!important;}
.site-header,.site-footer,#masthead,#colophon,.wp-site-blocks>header,.wp-site-blocks>footer{display:none!important;}
#wpadminbar{display:none!important;}
img.emoji,img.wp-smiley{display:inline!important;width:1em!important;height:1em!important;max-width:none!important;margin:0 .07em!important;vertical-align:-0.1em!important;}

#frp-service-page{display:block;font-family:'Inter',system-ui,sans-serif;font-size:16px;color:#0b1c30;background:#f8f9ff;line-height:1.5;-webkit-font-smoothing:antialiased;}
#frp-service-page *,#frp-service-page *::before,#frp-service-page *::after{box-sizing:border-box;}
#frp-service-page a{color:#00288e;text-decoration:none;}
#frp-service-page a:hover{text-decoration:underline;}
#frp-service-page h1,#frp-service-page h2{font-family:'Manrope',sans-serif;font-weight:800;line-height:1.2;color:#00175c;margin:0;padding:0;}

#frp-service-page .frp-topnav{background:#00175c;padding:14px 20px;display:flex;align-items:center;justify-content:space-between;}
#frp-service-page .frp-topnav a{color:white!important;font-weight:700;font-size:15px;font-family:'Manrope',sans-serif;text-decoration:none!important;}
#frp-service-page .frp-topnav-right{display:flex;gap:16px;}
#frp-service-page .frp-topnav-right a{color:rgba(255,255,255,.8)!important;font-size:13px;font-weight:500;}
#frp-service-page .frp-emergency-bar{background:#dc2626;color:white;text-align:center;font-size:12px;font-weight:600;padding:7px 16px;letter-spacing:.3px;}

#frp-service-page .frp-wrap{max-width:860px;margin:0 auto;padding:0 16px 60px;}
#frp-service-page .frp-breadcrumb{padding:14px 0;font-size:13px;color:#6b7280;border-bottom:1px solid #e5e7eb;margin-bottom:24px;}
#frp-service-page .frp-breadcrumb a{color:#00288e;}
#frp-service-page .frp-breadcrumb span{margin:0 5px;color:#d1d5db;}

#frp-service-page .frp-pro-card{background:white;border:1px solid #e5e7eb;border-radius:14px;padding:18px 20px;margin-bottom:12px;display:flex;align-items:flex-start;gap:16px;box-shadow:0 1px 4px rgba(0,0,0,.06);}
#frp-service-page .frp-pro-card:hover{box-shadow:0 4px 12px rgba(0,23,92,.1);border-color:#bfdbfe;}
#frp-service-page .frp-pro-info{flex:1;min-width:0;}
#frp-service-page .frp-pro-name{font-family:'Manrope',sans-serif;font-size:17px;font-weight:800;color:#00175c;margin:0 0 3px;}
#frp-service-page .frp-pro-name a{color:#00175c!important;text-decoration:none!important;}
#frp-service-page .frp-pro-name a:hover{color:#00288e!important;}
#frp-service-page .frp-pro-location{font-size:13px;color:#6b7280;margin:0 0 5px;}
#frp-service-page .frp-pro-rating{font-size:13px;color:#d97706;margin:0 0 5px;}
#frp-service-page .frp-pro-rating span{color:#6b7280;}
#frp-service-page .frp-verified{display:inline-flex;align-items:center;gap:3px;background:#f0fdf4;color:#15803d;font-size:11px;font-weight:600;padding:2px 8px;border-radius:20px;border:1px solid #bbf7d0;margin-bottom:6px;}
#frp-service-page .frp-call-btn{display:inline-flex;align-items:center;gap:7px;background:#dc2626;color:white!important;font-weight:700;font-size:14px;padding:12px 20px;border-radius:10px;white-space:nowrap;text-decoration:none!important;flex-shrink:0;align-self:center;}
#frp-service-page .frp-call-btn:hover{background:#b91c1c;}

#frp-service-page .frp-cta-box{margin-top:28px;padding:20px;background:#eff6ff;border:1px solid #bfdbfe;border-radius:14px;text-align:center;}
#frp-service-page .frp-cta-box p{font-family:'Manrope',sans-serif;font-weight:700;font-size:15px;margin:0 0 12px;color:#1e3a8a;}
#frp-service-page .frp-cta-box a{display:inline-block;background:#00288e;color:white!important;padding:12px 28px;border-radius:10px;text-decoration:none!important;font-weight:700;font-size:14px;}
#frp-service-page .frp-cta-box a:hover{background:#00175c;}

#frp-service-page .frp-footer{text-align:center;padding:28px 0 0;font-size:12px;color:#9ca3af;border-top:1px solid #e5e7eb;margin-top:32px;}
#frp-service-page .frp-footer a{color:#6b7280;}

@media(max-width:600px){
  #frp-service-page .frp-pro-card{flex-direction:column;}
  #frp-service-page .frp-call-btn{width:100%;justify-content:center;}
}
</style>
<?php wp_head(); ?>
</head>
<body <?php body_class('frp-city-service-page'); ?>>
<?php wp_body_open(); ?>
<div id="frp-service-page">

<nav class="frp-topnav">
  <a href="<?php echo home_url('/'); ?>">🔧 FindRestorationPros</a>
  <div class="frp-topnav-right">
    <a href="<?php echo home_url('/'); ?>">Find a Pro</a>
    <a href="<?php echo home_url('/join/'); ?>">List Your Business</a>
  </div>
</nav>
<div class="frp-emergency-bar">🚨 24/7 Emergency Dispatch - Call a Pro Now</div>

<div class="frp-wrap">

  <!-- Breadcrumb -->
  <nav class="frp-breadcrumb" aria-label="Breadcrumb">
    <a href="<?php echo home_url('/'); ?>">Home</a>
    <span>›</span>
    <?php echo esc_html($service_label); ?>
    <span>›</span>
    <?php echo esc_html($city_display); ?>
  </nav>

  <h1 style="font-size:26px;font-weight:800;margin:0 0 8px;">
    <?php echo esc_html("{$service_label} in {$city_display}, CA"); ?>
  </h1>
  <p style="color:#4b5563;margin:0 0 24px;">
    <?php if ( $pros_count > 0 ) : ?>
      <?php echo $pros_count; ?> verified contractor<?php echo $pros_count !== 1 ? 's' : ''; ?>
      found in <?php echo esc_html($city_display); ?>.
    <?php else : ?>
      No contractors found in <?php echo esc_html($city_display); ?> for this service yet.
      <a href="<?php echo home_url('/'); ?>" style="color:#00288e;">Search your ZIP code →</a>
    <?php endif; ?>
  </p>

  <!-- Pro listings -->
  <?php foreach ( $pros as $pro ) : ?>
  <div class="frp-pro-card">
    <div class="frp-pro-info">
      <h2 class="frp-pro-name">
        <a href="<?php echo esc_url( home_url('/pros/' . $pro['city_slug'] . '/' . $pro['slug'] . '/') ); ?>"
           >
          <?php echo esc_html($pro['name']); ?>
        </a>
      </h2>
      <?php if ( $pro['city'] ) : ?>
      <p class="frp-pro-location">📍 <?php echo esc_html($pro['city'] . ', CA'); ?></p>
      <?php endif; ?>
      <?php if ( $pro['rating'] && $pro['review_count'] ) : ?>
      <p class="frp-pro-rating">
        ★ <?php echo number_format($pro['rating'],1); ?>
        <span>(<?php echo $pro['review_count']; ?> reviews)</span>
      </p>
      <?php endif; ?>
      <?php if ( $pro['verified'] ) : ?>
      <span class="frp-verified">✓ Verified</span>
      <?php endif; ?>
    </div>
    <?php if ( $pro['phone'] ) : ?>
    <a href="tel:<?php echo esc_attr(preg_replace('/[^0-9]/','',$pro['phone'])); ?>" class="frp-call-btn">
      📞 Call Now
    </a>
    <?php endif; ?>
  </div>
  <?php endforeach; ?>

  <!-- CTA -->
  <div class="frp-cta-box">
    <p>Need help right now?</p>
    <a href="<?php echo home_url('/'); ?>">
      Find Pros Near You →
    </a>
  </div>

  <footer class="frp-footer">
    <p>© <?php echo date('Y'); ?> FindRestorationPros.com &nbsp;·&nbsp;
    <a href="<?php echo home_url('/privacy/'); ?>">Privacy</a> &nbsp;·&nbsp;
    <a href="<?php echo home_url('/terms/'); ?>">Terms</a></p>
  </footer>

</div><!-- .frp-wrap -->
</div><!-- #frp-service-page -->

<?php wp_footer(); ?>
</body>
</html>
