<?php
/**
 * FRP one-time data migrations.
 * Each migration runs once on admin_init (guarded by a wp_options flag)
 * and is also callable on-demand via POST /frp/v1/admin/run-migration
 * so the integration test suite can trigger them without a browser.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

// ─────────────────────────────────────────────────────────────
// Auto-run migrations once on admin_init
// ─────────────────────────────────────────────────────────────
add_action( 'admin_init', function () {
    if ( get_option( 'frp_migration_claim_status_backfill_v1' ) !== 'done' ) {
        frp_migration_claim_status_backfill_v1();
        update_option( 'frp_migration_claim_status_backfill_v1', 'done' );
    }
} );

// ─────────────────────────────────────────────────────────────
// Migration: claim_status_backfill_v1
// Sets claim_status=unclaimed on all restoration_pro posts that
// have NO claim_status meta row (i.e. seeded before Task 1.3.5).
// Uses add_post_meta with $unique=true so it never overwrites
// a row that already exists (claimed, claim_pending, etc.).
// ─────────────────────────────────────────────────────────────
function frp_migration_claim_status_backfill_v1() : int {
    global $wpdb;
    $rows = $wpdb->get_col( "
        SELECT p.ID
        FROM {$wpdb->posts} p
        LEFT JOIN {$wpdb->postmeta} m
            ON m.post_id = p.ID AND m.meta_key = 'claim_status'
        WHERE p.post_type = 'restoration_pro'
          AND m.meta_id IS NULL
    " );
    foreach ( $rows as $id ) {
        add_post_meta( (int) $id, 'claim_status', 'unclaimed', true );
    }
    return count( $rows );
}

// ─────────────────────────────────────────────────────────────
// REST endpoint — test-suite on-demand migration runner
// Allows integration tests to invoke a migration without needing
// a browser session (admin_init only fires during admin page loads).
// ─────────────────────────────────────────────────────────────
add_action( 'rest_api_init', function () {
    register_rest_route( 'frp/v1', '/admin/run-migration', [
        'methods'             => 'POST',
        'permission_callback' => 'frp_is_administrator',
        'callback'            => function ( WP_REST_Request $r ) {
            $name = (string) $r->get_param( 'name' );
            // Allow only safe identifier characters — no shell-injection risk even
            // though this is PHP, but defence-in-depth: names like "../../evil" die here.
            $fn = 'frp_migration_' . preg_replace( '/[^a-z0-9_]/', '', $name );
            if ( ! function_exists( $fn ) ) {
                return new WP_Error( 'unknown_migration', 'Unknown migration: ' . esc_html( $name ), [ 'status' => 404 ] );
            }
            return rest_ensure_response( [ 'migration' => $name, 'affected' => $fn() ] );
        },
    ] );
} );
