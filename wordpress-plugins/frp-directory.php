<?php
/**
 * Plugin Name: FRP Directory
 * Description: Restoration Pro CPT, meta fields, and REST API endpoints for findrestorationpros.com
 * Version: 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;

// ─────────────────────────────────────────────────────────────
// RATE LIMITER — transient-based, per IP
// ─────────────────────────────────────────────────────────────
function frp_check_rate_limit( $action, $limit, $window ) {
    $ip  = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    $key = 'frp_rl_' . $action . '_' . md5( $ip );
    $count = (int) get_transient( $key );
    if ( $count >= $limit ) {
        return false;
    }
    set_transient( $key, $count + 1, $window );
    return true;
}

// ─────────────────────────────────────────────────────────────
// 1. REGISTER CUSTOM POST TYPE
// ─────────────────────────────────────────────────────────────
function frp_register_cpt() {
    register_post_type( 'restoration_pro', [
        'labels'       => [
            'name'          => 'Restoration Pros',
            'singular_name' => 'Restoration Pro',
            'add_new_item'  => 'Add New Pro',
            'edit_item'     => 'Edit Pro',
            'search_items'  => 'Search Pros',
        ],
        'public'        => true,
        'has_archive'   => true,
        'rewrite'       => [ 'slug' => 'restoration-pros', 'with_front' => false ],
        'show_in_rest'  => true,
        'rest_base'     => 'restoration_pro',
        'supports'      => [ 'title', 'editor', 'thumbnail', 'custom-fields' ],
        'menu_icon'     => 'dashicons-businessman',
        'show_in_menu'  => true,
    ] );
}
add_action( 'init', 'frp_register_cpt' );

// ─────────────────────────────────────────────────────────────
// 1b. REGISTER LEAD CPT — private, admin-only, no public URLs
// ─────────────────────────────────────────────────────────────
function frp_register_lead_cpt() {
    register_post_type( 'frp_lead', [
        'labels'       => [
            'name'          => 'Leads',
            'singular_name' => 'Lead',
            'add_new_item'  => 'Add New Lead',
            'edit_item'     => 'Edit Lead',
            'search_items'  => 'Search Leads',
            'not_found'     => 'No leads found.',
            'all_items'     => 'All Leads',
        ],
        'public'        => false,
        'show_ui'       => true,
        'show_in_menu'  => true,
        'show_in_rest'  => false,
        'supports'      => [ 'title', 'custom-fields' ],
        'menu_icon'     => 'dashicons-email-alt',
        'capabilities'  => [
            'edit_post'          => 'manage_options',
            'edit_posts'         => 'manage_options',
            'edit_others_posts'  => 'manage_options',
            'publish_posts'      => 'manage_options',
            'read_post'          => 'manage_options',
            'read_private_posts' => 'manage_options',
            'delete_post'        => 'manage_options',
        ],
        'map_meta_cap'  => true,
    ] );
}
add_action( 'init', 'frp_register_lead_cpt' );

// ─────────────────────────────────────────────────────────────
// 1c. REGISTER frp_lead META FIELDS — all private (show_in_rest=false)
// ─────────────────────────────────────────────────────────────
function frp_register_lead_meta() {
    $string_fields = [
        'lead_name', 'lead_email', 'lead_phone', 'lead_zip',
        'lead_address', 'lead_city', 'lead_service', 'lead_urgency',
        'lead_property_type', 'lead_has_insurance', 'lead_scope',
        'lead_status', 'lead_source', 'lead_assigned_pros',
        'lead_update_token', 'lead_update_token_expiry',
        'lead_page_url', 'dispatch_tier', 'date_submitted',
    ];
    foreach ( $string_fields as $key ) {
        register_post_meta( 'frp_lead', $key, [
            'show_in_rest'  => false,
            'single'        => true,
            'type'          => 'string',
            'auth_callback' => function() { return current_user_can( 'manage_options' ); },
        ] );
    }
    register_post_meta( 'frp_lead', 'lead_score', [
        'show_in_rest'  => false,
        'single'        => true,
        'type'          => 'integer',
        'auth_callback' => function() { return current_user_can( 'manage_options' ); },
    ] );
    register_post_meta( 'frp_lead', 'lead_make_sent', [
        'show_in_rest'  => false,
        'single'        => true,
        'type'          => 'integer',
        'auth_callback' => function() { return current_user_can( 'manage_options' ); },
    ] );
}
add_action( 'init', 'frp_register_lead_meta' );

// ─────────────────────────────────────────────────────────────
// 2. REGISTER ALL META FIELDS (show_in_rest = true)
//    Scripts write to these via WP REST API meta block.
//    ACF field group maps to these same keys for admin UI.
// ─────────────────────────────────────────────────────────────
function frp_register_meta_fields() {
    $text_fields = [
        'phone', 'website', 'address', 'city', 'county', 'state',
        'zip_codes', 'logo_url', 'hero_image_url', 'description',
        'yelp_id', 'google_place_id', 'response_time', 'certifications',
        'joined_source', 'listing_status', 'listing_tier', 'listing_expires',
        'yelp_reviews',    // stored as JSON string
        'google_reviews',  // stored as JSON string
        'services',        // stored as comma-separated string
        'youtube_url',     // featured-tier: YouTube video URL
        'featured_tagline', // featured-tier: short custom headline shown on cards/profile
        'coord_source',    // how coords were resolved: geocode|places-api|zip-centroid|city-centroid
    ];

    // Internal/billing fields — NOT exposed to the public REST API
    $private_fields = [ 'contact_name', 'contact_email' ];
    foreach ( $private_fields as $key ) {
        register_post_meta( 'restoration_pro', $key, [
            'show_in_rest'  => false,
            'single'        => true,
            'type'          => 'string',
            'auth_callback' => function() { return current_user_can( 'edit_posts' ); },
        ] );
    }

    $number_fields = [
        'yelp_rating', 'yelp_review_count',
        'google_rating', 'google_review_count',
        'years_in_business', 'service_radius_miles',
    ];

    $float_fields = [ 'lat', 'lng' ];

    foreach ( $float_fields as $key ) {
        register_post_meta( 'restoration_pro', $key, [
            'show_in_rest'  => true,
            'single'        => true,
            'type'          => 'number',
            'auth_callback' => '__return_true',
        ] );
    }

    $bool_fields = [ 'is_paid_listing', 'verified' ];

    $date_fields = [ 'date_seeded', 'last_synced' ];

    foreach ( $text_fields as $key ) {
        register_post_meta( 'restoration_pro', $key, [
            'show_in_rest'  => true,
            'single'        => true,
            'type'          => 'string',
            'auth_callback' => '__return_true',
        ] );
    }
    foreach ( $number_fields as $key ) {
        register_post_meta( 'restoration_pro', $key, [
            'show_in_rest'  => true,
            'single'        => true,
            'type'          => 'number',
            'auth_callback' => '__return_true',
        ] );
    }
    foreach ( $bool_fields as $key ) {
        register_post_meta( 'restoration_pro', $key, [
            'show_in_rest'  => true,
            'single'        => true,
            'type'          => 'boolean',
            'auth_callback' => '__return_true',
        ] );
    }
    foreach ( $date_fields as $key ) {
        register_post_meta( 'restoration_pro', $key, [
            'show_in_rest'  => true,
            'single'        => true,
            'type'          => 'string',
            'auth_callback' => '__return_true',
        ] );
    }
}
add_action( 'init', 'frp_register_meta_fields' );

// ─────────────────────────────────────────────────────────────
// COORD VALIDATION — reject missing/zero coordinates
// ─────────────────────────────────────────────────────────────
function frp_has_valid_coords( $lat, $lng ) {
    return is_numeric( $lat ) && is_numeric( $lng ) &&
           abs( (float) $lat ) > 0.0001 &&
           abs( (float) $lng ) > 0.0001;
}

// ─────────────────────────────────────────────────────────────
// HAVERSINE DISTANCE — returns miles between two lat/lng points
// ─────────────────────────────────────────────────────────────
function frp_haversine( $lat1, $lng1, $lat2, $lng2 ) {
    $R    = 3958.8; // Earth radius in miles
    $dLat = deg2rad( $lat2 - $lat1 );
    $dLng = deg2rad( $lng2 - $lng1 );
    $a    = sin( $dLat / 2 ) * sin( $dLat / 2 ) +
            cos( deg2rad( $lat1 ) ) * cos( deg2rad( $lat2 ) ) *
            sin( $dLng / 2 ) * sin( $dLng / 2 );
    return $R * 2 * atan2( sqrt( $a ), sqrt( 1 - $a ) );
}

// ZIP → lat/lng centroid lookup (major California ZIPs)
function frp_zip_to_coords( $zip ) {
    $table = [
        // Los Angeles
        '90001' => [33.9731,-118.2479], '90002' => [33.9493,-118.2462],
        '90003' => [33.9637,-118.2731], '90004' => [34.0764,-118.3085],
        '90005' => [34.0583,-118.3008], '90006' => [34.0480,-118.2939],
        '90007' => [34.0235,-118.2837], '90008' => [34.0068,-118.3436],
        '90010' => [34.0620,-118.3124], '90011' => [33.9987,-118.2587],
        '90012' => [34.0586,-118.2383], '90013' => [34.0436,-118.2441],
        '90014' => [34.0432,-118.2516], '90015' => [34.0382,-118.2671],
        '90016' => [34.0188,-118.3547], '90017' => [34.0521,-118.2693],
        '90018' => [34.0143,-118.3208], '90019' => [34.0478,-118.3469],
        '90020' => [34.0681,-118.3068], '90021' => [34.0342,-118.2378],
        '90022' => [34.0229,-118.1528], '90023' => [34.0217,-118.1888],
        '90024' => [34.0617,-118.4417], '90025' => [34.0447,-118.4516],
        '90026' => [34.0774,-118.2607], '90027' => [34.1049,-118.2951],
        '90028' => [34.0980,-118.3279], '90029' => [34.0891,-118.2984],
        '90031' => [34.0798,-118.2066], '90032' => [34.0840,-118.1731],
        '90033' => [34.0464,-118.2060], '90034' => [34.0225,-118.3972],
        '90035' => [34.0531,-118.3808], '90036' => [34.0686,-118.3471],
        '90037' => [33.9949,-118.2828], '90038' => [34.0927,-118.3185],
        '90039' => [34.1071,-118.2595], '90040' => [33.9964,-118.1530],
        '90041' => [34.1319,-118.2082], '90042' => [34.1133,-118.1886],
        '90043' => [33.9861,-118.3352], '90044' => [33.9528,-118.2968],
        '90045' => [33.9554,-118.4124], '90046' => [34.1045,-118.3654],
        '90047' => [33.9609,-118.3100], '90048' => [34.0771,-118.3780],
        '90049' => [34.0800,-118.4749], '90056' => [33.9869,-118.3751],
        '90057' => [34.0620,-118.2853], '90058' => [33.9831,-118.2156],
        '90059' => [33.9295,-118.2536], '90061' => [33.9215,-118.2724],
        '90062' => [34.0001,-118.3101], '90063' => [34.0293,-118.1710],
        '90064' => [34.0337,-118.4259], '90065' => [34.1094,-118.2322],
        '90066' => [34.0012,-118.4297], '90067' => [34.0573,-118.4147],
        '90068' => [34.1158,-118.3382], '90069' => [34.0908,-118.3800],
        '90071' => [34.0527,-118.2540], '90077' => [34.0875,-118.4694],
        '90094' => [33.9763,-118.4202],
        // Long Beach
        '90801' => [33.7701,-118.1937], '90802' => [33.7704,-118.1929],
        '90803' => [33.7575,-118.1222], '90804' => [33.7871,-118.1556],
        '90805' => [33.8476,-118.1710], '90806' => [33.7906,-118.1945],
        '90807' => [33.8224,-118.2009], '90808' => [33.8275,-118.1221],
        '90810' => [33.8055,-118.2199], '90813' => [33.7804,-118.1876],
        '90814' => [33.7710,-118.1384], '90815' => [33.7864,-118.1057],
        // Pasadena
        '91101' => [34.1478,-118.1445], '91103' => [34.1658,-118.1642],
        '91104' => [34.1624,-118.1195], '91105' => [34.1355,-118.1701],
        '91106' => [34.1409,-118.1123], '91107' => [34.1599,-118.0680],
        // Glendale
        '91201' => [34.1629,-118.2793], '91202' => [34.1697,-118.2615],
        '91203' => [34.1457,-118.2651], '91204' => [34.1271,-118.2632],
        '91205' => [34.1346,-118.2430], '91206' => [34.1559,-118.2214],
        '91207' => [34.1710,-118.2399], '91208' => [34.1869,-118.2259],
        // Burbank
        '91501' => [34.1760,-118.3085], '91502' => [34.1730,-118.3295],
        '91504' => [34.1962,-118.3219], '91505' => [34.1798,-118.3497],
        '91506' => [34.1733,-118.3215],
        // El Monte / SGV
        '91731' => [34.0686,-118.0276], '91732' => [34.0800,-118.0170],
        '91733' => [34.0591,-118.0462],
        // West Covina / Covina
        '91790' => [34.0711,-117.9365], '91791' => [34.0779,-117.9188],
        '91792' => [34.0549,-117.9330], '91723' => [34.0885,-117.8902],
        '91724' => [34.0924,-117.8665],
        // Baldwin Park / La Puente
        '91706' => [34.0853,-117.9609], '91744' => [34.0320,-117.9495],
        // Whittier
        '90601' => [33.9792,-118.0328], '90602' => [33.9763,-118.0135],
        '90603' => [33.9676,-117.9804], '90604' => [33.9535,-118.0138],
        '90605' => [33.9538,-117.9784],
        // Arcadia / Monrovia / Azusa / Glendora
        '91006' => [34.1397,-118.0353], '91007' => [34.1272,-118.0596],
        '91016' => [34.1442,-117.9995], '91702' => [34.1336,-117.9076],
        '91741' => [34.1361,-117.8653],
        // Downey
        '90240' => [33.9401,-118.1331], '90241' => [33.9371,-118.1263],
        '90242' => [33.9222,-118.1361],
        // Inglewood / Hawthorne / Torrance
        '90301' => [33.9617,-118.3531], '90302' => [33.9729,-118.3596],
        '90303' => [33.9473,-118.3445], '90304' => [33.9336,-118.3455],
        '90305' => [33.9541,-118.3689],
        '90250' => [33.9164,-118.3526], '90260' => [33.8979,-118.3290],
        '90261' => [33.8979,-118.3290],
        '90501' => [33.8358,-118.3406], '90502' => [33.8296,-118.3113],
        '90503' => [33.8353,-118.3751], '90504' => [33.8575,-118.3474],
        '90505' => [33.8062,-118.3753], '90506' => [33.8575,-118.3474],
        // Orange County
        '92801' => [33.8366,-117.9143], '92802' => [33.8082,-117.9218],
        '92804' => [33.8333,-117.9592], '92805' => [33.8355,-117.8927],
        '92806' => [33.8494,-117.8533], '92807' => [33.8719,-117.8118],
        '92808' => [33.8958,-117.7861],
        '92626' => [33.6694,-117.8766], '92627' => [33.6396,-117.9153],
        '92628' => [33.6411,-117.9187],
        '92630' => [33.6557,-117.6888], '92651' => [33.6089,-117.8232],
        '92653' => [33.6846,-117.8265], '92656' => [33.6397,-117.7531],
        '92657' => [33.6013,-117.8651], '92660' => [33.6197,-117.8722],
        '92661' => [33.6061,-117.9155], '92663' => [33.6256,-117.9312],
        '92701' => [33.7455,-117.8677], '92703' => [33.7414,-117.9011],
        '92704' => [33.7243,-117.9122], '92705' => [33.7574,-117.8260],
        '92706' => [33.7704,-117.8769], '92707' => [33.7124,-117.8696],
        '92708' => [33.7022,-117.9558],
        '92840' => [33.7739,-117.9395], '92841' => [33.7847,-117.9727],
        '92843' => [33.7508,-117.9421], '92844' => [33.7391,-117.9584],
        '92845' => [33.7955,-117.9798],
        '92861' => [33.8159,-117.8274], '92865' => [33.8388,-117.8419],
        '92866' => [33.7879,-117.8437], '92867' => [33.7944,-117.8019],
        '92868' => [33.7982,-117.8730], '92869' => [33.7709,-117.7942],
        // San Diego
        '92101' => [32.7157,-117.1611], '92103' => [32.7450,-117.1627],
        '92104' => [32.7383,-117.1255], '92105' => [32.7279,-117.0993],
        '92106' => [32.7217,-117.2290], '92107' => [32.7419,-117.2381],
        '92108' => [32.7787,-117.1264], '92109' => [32.7925,-117.2366],
        '92110' => [32.7628,-117.2007], '92111' => [32.8021,-117.1639],
        '92113' => [32.6942,-117.1143], '92114' => [32.6894,-117.0619],
        '92115' => [32.7540,-117.0720], '92116' => [32.7573,-117.1199],
        '92117' => [32.8165,-117.1926], '92118' => [32.6731,-117.1406],
        '92119' => [32.7836,-117.0219], '92120' => [32.7934,-117.0737],
        '92121' => [32.8882,-117.2247], '92122' => [32.8590,-117.2185],
        '92123' => [32.8041,-117.1270], '92124' => [32.8311,-117.0849],
        '92126' => [32.9126,-117.1276], '92127' => [33.0217,-117.1101],
        '92128' => [33.0014,-117.0614], '92129' => [32.9619,-117.1302],
        '92130' => [32.9506,-117.2083], '92131' => [32.8965,-117.0721],
        '92132' => [32.7122,-117.1750], '92134' => [32.7267,-117.1477],
        '92139' => [32.6673,-117.0498], '92154' => [32.5741,-117.0480],
        // Riverside
        '92501' => [33.9533,-117.3962], '92503' => [33.9188,-117.4372],
        '92504' => [33.9528,-117.4196], '92505' => [33.9251,-117.4704],
        '92506' => [33.9188,-117.3665], '92507' => [33.9773,-117.3473],
        '92508' => [33.9123,-117.3159], '92509' => [34.0007,-117.4280],
        // San Bernardino
        '92401' => [34.1083,-117.2898], '92404' => [34.1469,-117.2593],
        '92405' => [34.1361,-117.3044], '92407' => [34.1906,-117.3469],
        '92408' => [34.0820,-117.2654], '92410' => [34.0951,-117.2839],
        // Corona / Murrieta
        '92879' => [33.8753,-117.5664], '92880' => [33.9135,-117.5416],
        '92881' => [33.8515,-117.5217], '92882' => [33.8621,-117.5882],
        '92562' => [33.5539,-117.2139], '92563' => [33.5652,-117.1491],
        // Fontana / Rancho Cucamonga / Ontario
        '92335' => [34.0922,-117.4350], '92336' => [34.1173,-117.4634],
        '92337' => [34.0654,-117.3988],
        '91730' => [34.1064,-117.5931], '91737' => [34.1475,-117.5847],
        '91739' => [34.1635,-117.5313],
        '91761' => [34.0633,-117.6509], '91762' => [34.0754,-117.6812],
        '91764' => [34.0847,-117.6262],
        // Moreno Valley
        '92551' => [33.9425,-117.2297], '92553' => [33.9291,-117.2589],
        '92555' => [33.9555,-117.1942], '92557' => [33.9685,-117.2040],
    ];
    return isset( $table[ $zip ] ) ? $table[ $zip ] : null;
}

// ─────────────────────────────────────────────────────────────
// 3. REST ENDPOINT: /wp-json/frp/v1/search
//    GET ?zip=90210&service=water-damage
//    Returns array of matching companies, paid listings first.
// ─────────────────────────────────────────────────────────────
function frp_register_rest_routes() {

    register_rest_route( 'frp/v1', '/search', [
        'methods'             => 'GET',
        'callback'            => 'frp_search_handler',
        'permission_callback' => '__return_true',
        'args' => [
            'zip'     => [ 'required' => true,  'sanitize_callback' => 'sanitize_text_field' ],
            'service' => [ 'required' => false, 'sanitize_callback' => 'sanitize_text_field' ],
            'radius'  => [ 'required' => false, 'sanitize_callback' => 'absint' ],
        ],
    ] );

    // ── Publish page HTML directly into Elementor widget ──────────────────────
    // The WP REST API /wp/v2/pages meta endpoint cannot write _elementor_data
    // (Elementor v4 blocks it). This endpoint writes it server-side via PHP.
    register_rest_route( 'frp/v1', '/publish-page', [
        'methods'             => 'POST',
        'callback'            => 'frp_publish_page_handler',
        'permission_callback' => function( WP_REST_Request $request ) {
            if ( ! defined( 'FRP_PUBLISH_SECRET' ) ) {
                return new WP_Error( 'forbidden', 'Publish secret not configured', [ 'status' => 403 ] );
            }
            $secret = $request->get_header( 'X-FRP-Secret' );
            if ( ! $secret || ! hash_equals( (string) FRP_PUBLISH_SECRET, (string) $secret ) ) {
                return new WP_Error( 'forbidden', 'Invalid or missing secret', [ 'status' => 403 ] );
            }
            return current_user_can( 'edit_pages' );
        },
        'args' => [
            'slug'          => [ 'required' => true,  'sanitize_callback' => 'sanitize_text_field' ],
            'elementor_data'=> [ 'required' => true ],
        ],
    ] );

    register_rest_route( 'frp/v1', '/contact', [
        'methods'             => 'POST',
        'callback'            => 'frp_contact_handler',
        'permission_callback' => '__return_true',
    ] );

    register_rest_route( 'frp/v1', '/apply', [
        'methods'             => 'POST',
        'callback'            => 'frp_apply_handler',
        'permission_callback' => '__return_true',
    ] );

    register_rest_route( 'frp/v1', '/call', [
        'methods'             => 'GET',
        'callback'            => 'frp_call_handler',
        'permission_callback' => '__return_true',
        'args' => [
            'company' => [ 'required' => true,  'sanitize_callback' => 'absint' ],
            'zip'     => [ 'required' => false, 'sanitize_callback' => 'sanitize_text_field' ],
            'service' => [ 'required' => false, 'sanitize_callback' => 'sanitize_text_field' ],
            'source'  => [ 'required' => false, 'sanitize_callback' => 'sanitize_text_field' ],
            'path'    => [ 'required' => false, 'sanitize_callback' => 'sanitize_text_field' ],
        ],
    ] );
}
add_action( 'rest_api_init', 'frp_register_rest_routes' );

function frp_publish_page_handler( WP_REST_Request $request ) {
    $slug           = $request->get_param( 'slug' );
    $elementor_data = $request->get_param( 'elementor_data' );

    $pages = get_posts( [
        'post_type'   => 'page',
        'name'        => $slug,
        'post_status' => [ 'publish', 'draft' ],
        'numberposts' => 1,
    ] );

    if ( empty( $pages ) ) {
        return new WP_Error( 'not_found', "Page with slug '{$slug}' not found", [ 'status' => 404 ] );
    }

    $post_id = $pages[0]->ID;

    // wp_slash prevents WordPress from stripping backslashes in JSON
    update_post_meta( $post_id, '_elementor_data', wp_slash( $elementor_data ) );
    update_post_meta( $post_id, '_elementor_edit_mode', 'builder' );
    update_post_meta( $post_id, '_elementor_template_type', 'wp-page' );
    update_post_meta( $post_id, '_elementor_version', '3.18.0' );

    // Clear Elementor's CSS cache so it regenerates for this page
    if ( class_exists( '\Elementor\Plugin' ) && isset( \Elementor\Plugin::$instance->files_manager ) ) {
        \Elementor\Plugin::$instance->files_manager->clear_cache();
    }

    return rest_ensure_response( [ 'success' => true, 'post_id' => $post_id, 'slug' => $slug ] );
}

// ZIP prefix → county lookup. Specific overlaps checked first to avoid
// unreachable branches. 923-924 = San Bernardino, 925 = Riverside.
// 945 = Contra Costa, 946 = Alameda.
function frp_zip_to_county( $zip ) {
    $prefix = (int) substr( $zip, 0, 3 );

    // Specific overlapping prefixes — must come before broad ranges
    if ( $prefix === 923 || $prefix === 924 ) return 'San Bernardino';
    if ( $prefix === 925 ) return 'Riverside';
    if ( $prefix === 945 ) return 'Contra Costa';
    if ( $prefix === 946 ) return 'Alameda';

    // Broad ranges
    if ( $prefix >= 900 && $prefix <= 918 ) return 'Los Angeles';
    if ( $prefix >= 919 && $prefix <= 922 ) return 'San Diego';
    if ( $prefix >= 926 && $prefix <= 928 ) return 'Orange';
    if ( $prefix >= 930 && $prefix <= 931 ) return 'Ventura';
    if ( $prefix >= 932 && $prefix <= 933 ) return 'Kern';
    if ( $prefix >= 936 && $prefix <= 938 ) return 'Fresno';
    if ( $prefix === 941 ) return 'San Francisco';
    if ( $prefix >= 950 && $prefix <= 951 ) return 'Santa Clara';
    if ( $prefix >= 956 && $prefix <= 958 ) return 'Sacramento';

    return null;
}

// ─────────────────────────────────────────────────────────────
// CONTACT FORM HANDLER
// ─────────────────────────────────────────────────────────────
function frp_contact_handler( WP_REST_Request $request ) {
    if ( ! frp_check_rate_limit( 'contact', 5, 300 ) ) {
        return new WP_Error( 'rate_limited', 'Too many requests', [ 'status' => 429 ] );
    }

    $body = $request->get_json_params();
    $name    = sanitize_text_field( $body['name']    ?? '' );
    $email   = sanitize_email(      $body['email']   ?? '' );
    $subject = sanitize_text_field( $body['subject'] ?? '' );
    $message = sanitize_textarea_field( $body['message'] ?? '' );

    if ( ! $name || ! is_email( $email ) || ! $subject || ! $message ) {
        return new WP_Error( 'missing_fields', 'All fields are required', [ 'status' => 422 ] );
    }

    $sent = wp_mail(
        get_option( 'admin_email' ),
        '[FRP Contact] ' . $subject . ' — ' . $name,
        "From: {$name} <{$email}>\n\n{$message}",
        [ 'Reply-To: ' . $name . ' <' . $email . '>' ]
    );

    if ( ! $sent ) {
        return new WP_Error( 'mail_failed', 'Could not send email', [ 'status' => 500 ] );
    }

    return rest_ensure_response( [ 'success' => true ] );
}

// ─────────────────────────────────────────────────────────────
// JOIN FORM HANDLER — creates pending restoration_pro CPT entry
// ─────────────────────────────────────────────────────────────
function frp_apply_handler( WP_REST_Request $request ) {
    if ( ! frp_check_rate_limit( 'apply', 5, 300 ) ) {
        return new WP_Error( 'rate_limited', 'Too many requests', [ 'status' => 429 ] );
    }

    $body = $request->get_json_params();
    if ( ! $body ) {
        return new WP_Error( 'bad_request', 'Invalid JSON body', [ 'status' => 400 ] );
    }

    $s = 'sanitize_text_field';

    $company_name = $s( $body['company_name'] ?? '' );
    if ( ! $company_name ) {
        return new WP_Error( 'missing_field', 'company_name is required', [ 'status' => 422 ] );
    }

    // Create the post as draft (pending review before going active)
    $post_id = wp_insert_post( [
        'post_type'   => 'restoration_pro',
        'post_title'  => $company_name,
        'post_status' => 'draft',
        'meta_input'  => [
            'contact_name'     => $s( $body['contact_name']    ?? '' ),
            'contact_email'    => sanitize_email( $body['email'] ?? '' ),
            'phone'            => $s( $body['phone']           ?? '' ),
            'website'          => esc_url_raw( $body['website'] ?? '' ),
            'address'          => $s( $body['address']         ?? '' ),
            'city'             => $s( $body['city']            ?? '' ),
            'state'            => $s( $body['state']           ?? '' ),
            'zip_codes'        => $s( $body['zip_codes_served'] ?? $body['zip_code'] ?? '' ),
            'services'         => $s( $body['services']        ?? '' ),
            'certifications'   => $s( $body['certifications']  ?? '' ),
            'description'      => sanitize_textarea_field( $body['description'] ?? '' ),
            'years_in_business'=> absint( $body['years_in_business'] ?? 0 ),
            'listing_status'   => 'pending',
            'listing_tier'     => 'free',
            'joined_source'    => 'join-form',
            'date_applied'     => gmdate( 'Y-m-d' ),
        ],
    ] );

    if ( is_wp_error( $post_id ) ) {
        return new WP_Error( 'insert_failed', $post_id->get_error_message(), [ 'status' => 500 ] );
    }

    // Notify admin
    wp_mail(
        get_option( 'admin_email' ),
        'New Pro Application: ' . $company_name,
        "A new restoration pro has applied to be listed.\n\n" .
        "Company: {$company_name}\n" .
        "Contact: " . ( $body['contact_name'] ?? '' ) . "\n" .
        "Email: " . ( $body['email'] ?? '' ) . "\n" .
        "Phone: " . ( $body['phone'] ?? '' ) . "\n" .
        "City: " . ( $body['city'] ?? '' ) . "\n\n" .
        "Review at: " . admin_url( 'post.php?post=' . $post_id . '&action=edit' )
    );

    return rest_ensure_response( [
        'success' => true,
        'post_id' => $post_id,
        'message' => 'Application received. We will review and activate your listing within 24 hours.',
    ] );
}

function frp_search_handler( WP_REST_Request $request ) {
    if ( ! frp_check_rate_limit( 'search', 30, 60 ) ) {
        return new WP_Error( 'rate_limited', 'Too many requests', [ 'status' => 429 ] );
    }

    $zip        = $request->get_param( 'zip' );
    $service    = $request->get_param( 'service' );
    $radius_req = (int) ( $request->get_param( 'radius' ) ?: 0 );

    // Short-lived cache: skip expensive query+sort for repeated searches
    $cache_key = 'frp_search_' . md5( $zip . '|' . $service . '|' . $radius_req );
    $cached    = get_transient( $cache_key );
    if ( $cached !== false ) {
        return rest_ensure_response( $cached );
    }

    // Resolve search center coordinates from ZIP
    $search_coords = frp_zip_to_coords( $zip );

    // Three-tier location matching:
    // 1. If we have coords: county-wide pull, then post-filter by Haversine distance
    // 2. Exact ZIP match OR county match (covers all cities in the county)
    $county = frp_zip_to_county( $zip );

    if ( $county ) {
        $location_clause = [
            'relation' => 'OR',
            [
                'key'     => 'zip_codes',
                'value'   => $zip,
                'compare' => 'LIKE',
            ],
            [
                'key'     => 'county',
                'value'   => $county,
                'compare' => 'LIKE',
            ],
        ];
    } else {
        $location_clause = [
            'key'     => 'zip_codes',
            'value'   => $zip,
            'compare' => 'LIKE',
        ];
    }

    $meta_query = [
        'relation' => 'AND',
        $location_clause,
        [
            'key'     => 'listing_status',
            'value'   => 'active',
            'compare' => '=',
        ],
    ];

    if ( $service ) {
        $meta_query[] = [
            'key'     => 'services',
            'value'   => $service,
            'compare' => 'LIKE',
        ];
    }

    // Pull a larger set so Haversine filtering doesn't leave us empty
    $query = new WP_Query( [
        'post_type'      => 'restoration_pro',
        'posts_per_page' => 250,
        'post_status'    => 'publish',
        'meta_query'     => $meta_query,
        'orderby'        => [ 'meta_value' => 'DESC', 'title' => 'ASC' ],
        'meta_key'       => 'is_paid_listing',
    ] );

    $results = [];
    foreach ( $query->posts as $post ) {
        $id      = $post->ID;
        $is_paid = (bool) get_post_meta( $id, 'is_paid_listing', true );

        // Enforce expiration: treat listing as free if expires date has passed
        if ( $is_paid ) {
            $expires = get_post_meta( $id, 'listing_expires', true );
            if ( $expires && strtotime( $expires ) < current_time( 'timestamp' ) ) {
                $is_paid = false;
            }
        }

        $company_lat = get_post_meta( $id, 'lat', true );
        $company_lng = get_post_meta( $id, 'lng', true );

        $has_valid_company_coords = frp_has_valid_coords( $company_lat, $company_lng );

        // Compute distance if we have a valid search ZIP centroid
        $distance_miles = null;
        if ( $search_coords ) {
            // Company must have valid coords to appear in a distance-based search
            if ( ! $has_valid_company_coords ) {
                continue;
            }

            $distance_miles = round(
                frp_haversine(
                    $search_coords[0],
                    $search_coords[1],
                    (float) $company_lat,
                    (float) $company_lng
                ),
                1
            );

            $company_radius = (int) get_post_meta( $id, 'service_radius_miles', true );
            if ( $company_radius <= 0 ) {
                $company_radius = 25;
            }

            if ( $distance_miles > $company_radius ) {
                continue;
            }

            if ( $radius_req > 0 && $distance_miles > $radius_req ) {
                continue;
            }
        }

        // Yelp data is a paid-tier feature — only decode if listing is active paid
        $yelp_reviews_raw = $is_paid ? get_post_meta( $id, 'yelp_reviews', true ) : '';
        $yelp_reviews     = $yelp_reviews_raw ? json_decode( $yelp_reviews_raw, true ) : [];

        $results[] = [
            'id'                  => $id,
            'name'                => $post->post_title,
            'slug'                => $post->post_name,
            'phone'               => get_post_meta( $id, 'phone', true ),
            'city'                => get_post_meta( $id, 'city', true ),
            'county'              => get_post_meta( $id, 'county', true ),
            'yelp_rating'         => $is_paid ? (float) get_post_meta( $id, 'yelp_rating', true ) : null,
            'yelp_review_count'   => $is_paid ? (int)   get_post_meta( $id, 'yelp_review_count', true ) : null,
            'yelp_id'             => $is_paid ? get_post_meta( $id, 'yelp_id', true ) : '',
            'google_rating'       => (float) get_post_meta( $id, 'google_rating', true ),
            'google_review_count' => (int)   get_post_meta( $id, 'google_review_count', true ),
            'yelp_reviews'        => $yelp_reviews,
            'is_paid_listing'     => $is_paid,
            'services'            => get_post_meta( $id, 'services', true ),
            'response_time'       => get_post_meta( $id, 'response_time', true ),
            'logo_url'            => get_post_meta( $id, 'logo_url', true ),
            'years_in_business'   => (int)   get_post_meta( $id, 'years_in_business', true ),
            'certifications'      => get_post_meta( $id, 'certifications', true ),
            'verified'            => (bool)  get_post_meta( $id, 'verified', true ),
            'description'         => get_post_meta( $id, 'description', true ),
            'listing_tier'        => get_post_meta( $id, 'listing_tier', true ) ?: 'free',
            'youtube_url'         => $is_paid ? get_post_meta( $id, 'youtube_url', true ) : '',
            'featured_tagline'    => $is_paid ? get_post_meta( $id, 'featured_tagline', true ) : '',
            'distance_miles'      => $distance_miles,
        ];
    }

    // Sort: paid listings first, then by distance (nearest first), then by Google rating
    usort( $results, function( $a, $b ) {
        if ( $a['is_paid_listing'] !== $b['is_paid_listing'] ) {
            return $b['is_paid_listing'] <=> $a['is_paid_listing'];
        }
        $da = $a['distance_miles'] ?? PHP_INT_MAX;
        $db = $b['distance_miles'] ?? PHP_INT_MAX;
        if ( $da !== $db ) return $da <=> $db;
        return $b['google_rating'] <=> $a['google_rating'];
    } );

    // Cache and return top 20 after sorting
    $final = array_slice( $results, 0, 20 );
    set_transient( $cache_key, $final, 5 * MINUTE_IN_SECONDS );
    return rest_ensure_response( $final );
}

function frp_call_handler( WP_REST_Request $request ) {
    if ( ! frp_check_rate_limit( 'call', 20, 60 ) ) {
        return new WP_Error( 'rate_limited', 'Too many requests', [ 'status' => 429 ] );
    }

    $company_id   = $request->get_param( 'company' );
    $zip          = $request->get_param( 'zip' )     ?? '';
    $service      = $request->get_param( 'service' ) ?? '';
    $source       = $request->get_param( 'source' )  ?? '';
    $path         = $request->get_param( 'path' )    ?? '';

    $phone        = get_post_meta( $company_id, 'phone', true );
    $company_name = get_the_title( $company_id );

    if ( ! $phone ) {
        return new WP_Error( 'no_phone', 'Phone number not found', [ 'status' => 404 ] );
    }

    // Fire Make.com webhook — non-blocking (does not delay the redirect)
    $webhook_url = defined( 'FRP_MAKE_WEBHOOK' ) ? FRP_MAKE_WEBHOOK : get_option( 'frp_make_webhook', '' );
    if ( $webhook_url ) {
        wp_remote_post( $webhook_url, [
            'blocking' => false,
            'headers'  => [ 'Content-Type' => 'application/json' ],
            'body'     => wp_json_encode( [
                'event'        => 'call_click',
                'company_id'   => $company_id,
                'company_name' => $company_name,
                'zip_searched' => $zip,
                'damage_type'  => $service,
                'path'         => $path,
                'source_page'  => $source,
                'timestamp'    => gmdate( 'c' ),
            ] ),
        ] );
    }

    // Strip non-numeric characters from phone, redirect
    $clean_phone = preg_replace( '/[^0-9+]/', '', $phone );
    wp_redirect( 'tel:' . $clean_phone, 302 );
    exit;
}

// NOTE: No WordPress-level CSS enqueue needed.
// Each FRP page uses the Elementor-safe pattern: scoped #frp-app CSS,
// no external frameworks, no CDN dependencies.

// ─────────────────────────────────────────────────────────────
// 5. REDIRECT CPT PERMALINK → /profile/?slug=
//    Prevents the blank WordPress template from showing.
//    e.g. /restoration-pros/company-name/ → /profile/?slug=company-name
// ─────────────────────────────────────────────────────────────
function frp_redirect_cpt_permalink() {
    if ( is_singular( 'restoration_pro' ) ) {
        $slug = get_post_field( 'post_name', get_the_ID() );
        wp_redirect( home_url( '/profile/?slug=' . $slug ), 301 );
        exit;
    }
}
add_action( 'template_redirect', 'frp_redirect_cpt_permalink' );

// ─────────────────────────────────────────────────────────────
// 5b. RANKMATH SEO — title / description / focus keyword for CPT
//     These filters fire for sitemaps, social shares, and any
//     future CPT template rendering (if redirect is ever removed).
//     If the post already has a saved rank_math_title meta, RankMath
//     uses that; these filters are the fallback for older posts.
// ─────────────────────────────────────────────────────────────
function frp_service_label( $services ) {
    $map = [
        'water-damage'      => 'Water Damage Restoration',
        'fire-damage'       => 'Fire Damage Restoration',
        'mold-remediation'  => 'Mold Remediation',
        'storm-damage'      => 'Storm Damage Repair',
        'sewage-cleanup'    => 'Sewage Cleanup',
        'biohazard-cleanup' => 'Biohazard Cleanup',
        'structural'        => 'Structural Restoration',
    ];
    $first = trim( explode( ',', $services )[0] );
    return $map[ $first ] ?? ucwords( str_replace( '-', ' ', $first ) );
}

add_filter( 'rank_math/frontend/title', 'frp_rankmath_title' );
function frp_rankmath_title( $title ) {
    if ( ! is_singular( 'restoration_pro' ) ) return $title;
    $id   = get_the_ID();
    // Use stored meta if already set by seeder
    $stored = get_post_meta( $id, 'rank_math_title', true );
    if ( $stored ) return $stored;
    $city  = get_post_meta( $id, 'city', true );
    $svcs  = get_post_meta( $id, 'services', true );
    return get_the_title() . ' | ' . frp_service_label( $svcs ) . ' in ' . $city . ', CA | FindRestorationPros';
}

add_filter( 'rank_math/frontend/description', 'frp_rankmath_description' );
function frp_rankmath_description( $desc ) {
    if ( ! is_singular( 'restoration_pro' ) ) return $desc;
    $id    = get_the_ID();
    $stored = get_post_meta( $id, 'rank_math_description', true );
    if ( $stored ) return $stored;
    $city  = get_post_meta( $id, 'city', true );
    $svcs  = get_post_meta( $id, 'services', true );
    $label = strtolower( frp_service_label( $svcs ) );
    return get_the_title() . ' provides expert ' . $label . ' services in ' . $city . ', CA. Get a free estimate from a trusted local restoration professional.';
}

// ─────────────────────────────────────────────────────────────
// 6. ADMIN META BOX — view & edit all company fields in WP admin
//    Shows on the restoration_pro edit screen.
// ─────────────────────────────────────────────────────────────
function frp_add_meta_box() {
    add_meta_box(
        'frp_company_data',
        '📋 Company Data',
        'frp_render_meta_box',
        'restoration_pro',
        'normal',
        'high'
    );
}
add_action( 'add_meta_boxes', 'frp_add_meta_box' );

function frp_render_meta_box( $post ) {
    wp_nonce_field( 'frp_save_meta', 'frp_meta_nonce' );
    $meta = get_post_meta( $post->ID );
    $get  = fn( $key ) => isset( $meta[ $key ][0] ) ? esc_attr( $meta[ $key ][0] ) : '';

    $sections = [
        '⭐ Featured Listing (Paid)' => [
            'listing_status'      => [ 'Status — active / inactive / pending', 'text' ],
            'listing_tier'        => [ 'Tier — free / featured / premium', 'text' ],
            'is_paid_listing'     => [ 'Is Paid Listing (1 = yes, 0 = no)', 'text' ],
            'listing_expires'     => [ 'Listing Expires (YYYY-MM-DD)', 'text' ],
            'featured_tagline'    => [ 'Featured Tagline (shown on card + profile)', 'text' ],
            'youtube_url'         => [ 'YouTube Video URL (featured tier only)', 'url' ],
        ],
        'Contact & Billing (Internal)' => [
            'contact_name'        => [ 'Contact Name (owner)', 'text' ],
            'contact_email'       => [ 'Contact Email (billing)', 'text' ],
            'phone'               => [ 'Business Phone', 'text' ],
            'website'             => [ 'Website', 'url' ],
        ],
        'Location & Coverage' => [
            'address'             => [ 'Address', 'text' ],
            'city'                => [ 'City', 'text' ],
            'county'              => [ 'County', 'text' ],
            'state'               => [ 'State', 'text' ],
            'zip_codes'           => [ 'ZIP Codes Served (comma-separated)', 'text' ],
            'service_radius_miles'=> [ 'Service Radius (miles)', 'number' ],
        ],
        'Profile Details' => [
            'verified'            => [ 'Verified (1/0)', 'text' ],
            'response_time'       => [ 'Response Time (e.g. 60 min)', 'text' ],
            'years_in_business'   => [ 'Years in Business', 'number' ],
            'certifications'      => [ 'Certifications (IICRC, BBB, Licensed…)', 'text' ],
        ],
        'Services' => [
            'services'            => [ 'Services (comma-separated slugs, e.g. water-damage,mold-remediation)', 'text' ],
        ],
        'Google Data' => [
            'google_place_id'     => [ 'Google Place ID', 'text' ],
            'google_rating'       => [ 'Google Rating', 'text' ],
            'google_review_count' => [ 'Google Review Count', 'text' ],
            'lat'                 => [ 'Latitude', 'text' ],
            'lng'                 => [ 'Longitude', 'text' ],
            'coord_source'        => [ 'Coord Source (geocode / places-api / zip-centroid / city-centroid)', 'text' ],
        ],
        'Yelp Data' => [
            'yelp_id'             => [ 'Yelp Business ID', 'text' ],
            'yelp_rating'         => [ 'Yelp Rating', 'text' ],
            'yelp_review_count'   => [ 'Yelp Review Count', 'text' ],
        ],
        'Images' => [
            'logo_url'            => [ 'Logo URL', 'url' ],
            'hero_image_url'      => [ 'Hero Image URL', 'url' ],
        ],
        'Metadata' => [
            'joined_source'       => [ 'Source (google-places-seeder / manual)', 'text' ],
            'date_seeded'         => [ 'Date Seeded', 'text' ],
            'last_synced'         => [ 'Last Synced', 'text' ],
        ],
    ];

    echo '<style>
        .frp-meta-section { margin-bottom: 24px; }
        .frp-meta-section h3 { font-size: 12px; font-weight: 600; text-transform: uppercase;
            letter-spacing: .05em; color: #666; margin: 0 0 10px; padding-bottom: 6px;
            border-bottom: 1px solid #eee; }
        .frp-meta-section.frp-featured { background: #fffbeb; border: 2px solid #f59e0b;
            border-radius: 8px; padding: 16px; margin-bottom: 24px; }
        .frp-meta-section.frp-featured h3 { color: #92400e; border-bottom-color: #fcd34d; }
        .frp-meta-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }
        .frp-meta-field label { display: block; font-size: 12px; color: #555; margin-bottom: 3px; font-weight: 500; }
        .frp-meta-field input, .frp-meta-field textarea { width: 100%; border: 1px solid #ddd;
            border-radius: 4px; padding: 6px 8px; font-size: 13px; }
        .frp-meta-field input:focus { border-color: #00288e; outline: none; box-shadow: 0 0 0 2px rgba(0,40,142,.1); }
        .frp-meta-full { grid-column: 1 / -1; }
    </style>';

    foreach ( $sections as $section_name => $fields ) {
        $is_featured_section = str_starts_with( $section_name, '⭐' );
        echo '<div class="frp-meta-section' . ( $is_featured_section ? ' frp-featured' : '' ) . '">';
        echo '<h3>' . esc_html( $section_name ) . '</h3>';
        echo '<div class="frp-meta-grid">';
        foreach ( $fields as $key => [ $label, $type ] ) {
            $value = $get( $key );
            $full  = in_array( $key, [ 'services', 'address', 'certifications', 'featured_tagline', 'youtube_url', 'zip_codes' ] ) ? ' frp-meta-full' : '';
            echo '<div class="frp-meta-field' . $full . '">';
            echo '<label for="frp_' . esc_attr( $key ) . '">' . esc_html( $label ) . '</label>';
            echo '<input type="' . esc_attr( $type ) . '" id="frp_' . esc_attr( $key ) . '" name="frp_meta[' . esc_attr( $key ) . ']" value="' . $value . '" />';
            echo '</div>';
        }
        echo '</div></div>';
    }

    // Description as full-width textarea
    echo '<div class="frp-meta-section"><h3>Description</h3>';
    echo '<textarea name="frp_meta[description]" rows="4" style="width:100%;border:1px solid #ddd;border-radius:4px;padding:6px 8px;font-size:13px;">' . esc_textarea( $get( 'description' ) ) . '</textarea>';
    echo '</div>';

    // Google reviews (read-only JSON display)
    $reviews_raw = get_post_meta( $post->ID, 'google_reviews', true );
    if ( $reviews_raw ) {
        echo '<div class="frp-meta-section"><h3>Google Reviews (read-only — updated by sync)</h3>';
        $reviews = json_decode( $reviews_raw, true );
        if ( is_array( $reviews ) ) {
            foreach ( $reviews as $r ) {
                echo '<div style="background:#f9f9f9;border:1px solid #eee;border-radius:4px;padding:8px 10px;margin-bottom:8px;font-size:12px;">';
                echo '<strong>' . esc_html( $r['author'] ?? '' ) . '</strong> — ' . esc_html( $r['rating'] ?? '' ) . '★<br/>';
                echo esc_html( $r['text'] ?? '' );
                echo '</div>';
            }
        }
        echo '</div>';
    }
}

function frp_save_meta_box( $post_id ) {
    if ( ! isset( $_POST['frp_meta_nonce'] ) || ! wp_verify_nonce( $_POST['frp_meta_nonce'], 'frp_save_meta' ) ) return;
    if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) return;
    if ( ! current_user_can( 'edit_post', $post_id ) ) return;
    if ( ! isset( $_POST['frp_meta'] ) ) return;

    $url_fields     = [ 'website', 'logo_url', 'hero_image_url', 'youtube_url' ];
    $numeric_fields = [ 'years_in_business', 'service_radius_miles', 'google_rating',
                        'google_review_count', 'yelp_rating', 'yelp_review_count', 'lat', 'lng' ];

    foreach ( $_POST['frp_meta'] as $key => $raw ) {
        $key = sanitize_key( $key );

        if ( $key === 'contact_email' ) {
            $value = sanitize_email( $raw );
        } elseif ( $key === 'description' ) {
            $value = sanitize_textarea_field( $raw );
        } elseif ( in_array( $key, $url_fields, true ) ) {
            $value = esc_url_raw( $raw );
        } elseif ( in_array( $key, $numeric_fields, true ) ) {
            $value = is_numeric( $raw ) ? $raw + 0 : '';
        } else {
            $value = sanitize_text_field( $raw );
        }

        update_post_meta( $post_id, $key, $value );
    }
}
add_action( 'save_post_restoration_pro', 'frp_save_meta_box' );

// ─────────────────────────────────────────────────────────────
// 7. ADMIN COLUMNS — show key fields in the CPT list view
// ─────────────────────────────────────────────────────────────
function frp_add_admin_columns( $columns ) {
    $new = [];
    foreach ( $columns as $key => $val ) {
        $new[ $key ] = $val;
        if ( $key === 'title' ) {
            $new['frp_city']    = 'City';
            $new['frp_services']= 'Services';
            $new['frp_status']  = 'Status';
            $new['frp_tier']    = 'Tier';
            $new['frp_rating']  = 'Google ★';
            $new['frp_phone']   = 'Phone';
        }
    }
    return $new;
}
add_filter( 'manage_restoration_pro_posts_columns', 'frp_add_admin_columns' );

function frp_render_admin_columns( $column, $post_id ) {
    switch ( $column ) {
        case 'frp_city':
            echo esc_html( get_post_meta( $post_id, 'city', true ) );
            break;
        case 'frp_services':
            $svcs = get_post_meta( $post_id, 'services', true );
            $labels = [
                'water-damage'   => '💧 Water',
                'fire-damage'    => '🔥 Fire',
                'mold-remediation'=> '🍃 Mold',
                'storm-damage'   => '⛈ Storm',
                'sewage-cleanup' => '🪠 Sewage',
                'structural'     => '🏠 Structural',
            ];
            $out = [];
            foreach ( explode( ',', $svcs ) as $s ) {
                $s = trim( $s );
                $out[] = $labels[ $s ] ?? $s;
            }
            echo implode( ' ', $out );
            break;
        case 'frp_status':
            $status = get_post_meta( $post_id, 'listing_status', true );
            $color  = $status === 'active' ? '#16a34a' : '#dc2626';
            echo '<span style="color:' . $color . ';font-weight:600">' . esc_html( $status ?: '—' ) . '</span>';
            break;
        case 'frp_tier':
            $tier = get_post_meta( $post_id, 'listing_tier', true );
            $color = $tier === 'featured' ? '#b45309' : '#666';
            echo '<span style="color:' . $color . ';font-weight:600">' . esc_html( $tier ?: 'free' ) . '</span>';
            break;
        case 'frp_rating':
            $r = get_post_meta( $post_id, 'google_rating', true );
            $c = get_post_meta( $post_id, 'google_review_count', true );
            echo $r ? esc_html( $r ) . '★ (' . esc_html( $c ) . ')' : '—';
            break;
        case 'frp_phone':
            echo esc_html( get_post_meta( $post_id, 'phone', true ) ?: '—' );
            break;
    }
}
add_action( 'manage_restoration_pro_posts_custom_column', 'frp_render_admin_columns', 10, 2 );

// ─────────────────────────────────────────────────────────────
// LEAD ADMIN COLUMNS
// ─────────────────────────────────────────────────────────────
add_filter( 'manage_frp_lead_posts_columns', 'frp_lead_admin_columns' );
function frp_lead_admin_columns( $cols ) {
    return [
        'cb'              => $cols['cb'],
        'title'           => 'Job',
        'frp_lead_score'  => 'Score',
        'frp_lead_svc'    => 'Service',
        'frp_lead_zip'    => 'ZIP',
        'frp_lead_urg'    => 'Urgency',
        'frp_lead_phone'  => 'Phone',
        'frp_lead_status' => 'Status',
        'frp_lead_page'   => 'Source Page',
        'date'            => 'Date',
    ];
}

add_action( 'manage_frp_lead_posts_custom_column', 'frp_lead_render_columns', 10, 2 );
function frp_lead_render_columns( $col, $post_id ) {
    switch ( $col ) {
        case 'frp_lead_score':
            $score = (int) get_post_meta( $post_id, 'lead_score', true );
            if ( $score >= 70 ) {
                $color = '#dc2626'; $label = '🔴';
            } elseif ( $score >= 40 ) {
                $color = '#d97706'; $label = '🟡';
            } else {
                $color = '#16a34a'; $label = '🟢';
            }
            echo '<span style="color:' . $color . ';font-weight:700">' . $label . ' ' . $score . '/90</span>';
            break;
        case 'frp_lead_svc':
            $svc = get_post_meta( $post_id, 'lead_service', true );
            echo esc_html( frp_lead_service_label( $svc ) );
            break;
        case 'frp_lead_zip':
            echo esc_html( get_post_meta( $post_id, 'lead_zip', true ) ?: '—' );
            break;
        case 'frp_lead_urg':
            $map = [ 'now' => '🚨 Right Now', '24hrs' => 'Within 24hr', 'older' => 'Older' ];
            $urg = get_post_meta( $post_id, 'lead_urgency', true );
            echo esc_html( $map[ $urg ] ?? $urg ?: '—' );
            break;
        case 'frp_lead_phone':
            echo esc_html( get_post_meta( $post_id, 'lead_phone', true ) ?: '—' );
            break;
        case 'frp_lead_status':
            $status = get_post_meta( $post_id, 'lead_status', true ) ?: 'new';
            $colors = [
                'new'       => '#2563eb',
                'contacted' => '#d97706',
                'converted' => '#16a34a',
                'closed'    => '#6b7280',
            ];
            $c = $colors[ $status ] ?? '#6b7280';
            echo '<span style="color:' . $c . ';font-weight:600">' . esc_html( $status ) . '</span>';
            break;
        case 'frp_lead_page':
            $url = get_post_meta( $post_id, 'lead_page_url', true );
            if ( $url ) {
                $path = wp_parse_url( $url, PHP_URL_PATH ) . ( wp_parse_url( $url, PHP_URL_QUERY ) ? '?' . wp_parse_url( $url, PHP_URL_QUERY ) : '' );
                echo '<span title="' . esc_attr( $url ) . '">' . esc_html( $path ?: $url ) . '</span>';
            } else {
                echo '—';
            }
            break;
    }
}

// ─────────────────────────────────────────────────────────────
// 8. FLUSH REWRITE RULES ON ACTIVATION
//    Run once: visit WP Admin → Settings → Permalinks → Save
// ─────────────────────────────────────────────────────────────
function frp_flush_rewrites() {
    frp_register_cpt();
    flush_rewrite_rules();
}
register_activation_hook( __FILE__, 'frp_flush_rewrites' );
