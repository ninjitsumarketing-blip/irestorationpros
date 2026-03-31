<?php
/**
 * Plugin Name: FRP Directory
 * Description: Restoration Pro CPT, meta fields, and REST API endpoints for findrestorationpros.com
 * Version: 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;

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
        'rewrite'       => [ 'slug' => 'pros', 'with_front' => false ],
        'show_in_rest'  => true,
        'rest_base'     => 'restoration_pro',
        'supports'      => [ 'title', 'editor', 'thumbnail', 'custom-fields' ],
        'menu_icon'     => 'dashicons-businessman',
        'show_in_menu'  => true,
    ] );
}
add_action( 'init', 'frp_register_cpt' );

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
        'yelp_reviews',   // stored as JSON string
        'google_reviews', // stored as JSON string
        'services',       // stored as comma-separated string
    ];

    $number_fields = [
        'yelp_rating', 'yelp_review_count',
        'google_rating', 'google_review_count',
        'years_in_business', 'service_radius_miles',
    ];

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
        ],
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

function frp_search_handler( WP_REST_Request $request ) {
    $zip     = $request->get_param( 'zip' );
    $service = $request->get_param( 'service' );

    $meta_query = [
        'relation' => 'AND',
        [
            'key'     => 'zip_codes',
            'value'   => $zip,
            'compare' => 'LIKE',
        ],
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

    $query = new WP_Query( [
        'post_type'      => 'restoration_pro',
        'posts_per_page' => 20,
        'post_status'    => 'publish',
        'meta_query'     => $meta_query,
        // Paid listings first: sort by is_paid_listing DESC
        'orderby'        => [ 'meta_value' => 'DESC', 'title' => 'ASC' ],
        'meta_key'       => 'is_paid_listing',
    ] );

    $results = [];
    foreach ( $query->posts as $post ) {
        $id = $post->ID;

        // Parse reviews from JSON meta
        $yelp_reviews_raw   = get_post_meta( $id, 'yelp_reviews', true );
        $google_reviews_raw = get_post_meta( $id, 'google_reviews', true );
        $is_paid            = (bool) get_post_meta( $id, 'is_paid_listing', true );

        $yelp_reviews   = $yelp_reviews_raw   ? json_decode( $yelp_reviews_raw, true )   : [];
        $google_reviews = ( ! $is_paid && $google_reviews_raw )
                          ? json_decode( $google_reviews_raw, true )
                          : []; // hide worst reviews for paid listings

        $results[] = [
            'id'                  => $id,
            'name'                => $post->post_title,
            'slug'                => $post->post_name,
            'phone'               => get_post_meta( $id, 'phone', true ),
            'city'                => get_post_meta( $id, 'city', true ),
            'county'              => get_post_meta( $id, 'county', true ),
            'yelp_rating'         => (float) get_post_meta( $id, 'yelp_rating', true ),
            'yelp_review_count'   => (int)   get_post_meta( $id, 'yelp_review_count', true ),
            'google_rating'       => (float) get_post_meta( $id, 'google_rating', true ),
            'google_review_count' => (int)   get_post_meta( $id, 'google_review_count', true ),
            'yelp_reviews'        => $yelp_reviews,
            'google_reviews'      => $google_reviews,
            'is_paid_listing'     => $is_paid,
            'services'            => get_post_meta( $id, 'services', true ),
            'response_time'       => get_post_meta( $id, 'response_time', true ),
            'logo_url'            => get_post_meta( $id, 'logo_url', true ),
            'years_in_business'   => (int) get_post_meta( $id, 'years_in_business', true ),
            'certifications'      => get_post_meta( $id, 'certifications', true ),
            'verified'            => (bool) get_post_meta( $id, 'verified', true ),
            'description'         => get_post_meta( $id, 'description', true ),
        ];
    }

    return rest_ensure_response( $results );
}

function frp_call_handler( WP_REST_Request $request ) {
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

// ─────────────────────────────────────────────────────────────
// 4. FLUSH REWRITE RULES ON ACTIVATION
//    Run once: visit WP Admin → Settings → Permalinks → Save
// ─────────────────────────────────────────────────────────────
function frp_flush_rewrites() {
    frp_register_cpt();
    flush_rewrite_rules();
}
register_activation_hook( __FILE__, 'frp_flush_rewrites' );
