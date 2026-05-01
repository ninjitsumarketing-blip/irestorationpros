<?php
/**
 * Plugin Name: FRP Lead Lifecycle
 * Description: Lead status REST endpoint, WP-Cron deadline processor, stats endpoint
 * Version: 0.1.0
 */
if ( ! defined( 'ABSPATH' ) ) exit;

// ── Constants ────────────────────────────────────────────────────────────────
define( 'FRP_LEADS_STATUSES', [ 'contacted', 'won', 'lost' ] );

// ── Helpers ──────────────────────────────────────────────────────────────────

/**
 * Returns the restoration_pro post ID bound to the current WP user.
 * Returns 0 if not found. Safe to call before frp-directory.php loads
 * because mu-plugins load in alphabetical order (frp-leads.php < frp-directory.php).
 * Do NOT redeclare — frp-directory.php may already define this.
 */
if ( ! function_exists( 'frp_current_pro_id' ) ) {
    function frp_current_pro_id() {
        $user = wp_get_current_user();
        if ( ! $user->ID ) return 0;
        $pro_id = (int) get_user_meta( $user->ID, 'frp_pro_id', true );
        return $pro_id ?: 0;
    }
}

// ── REST API ─────────────────────────────────────────────────────────────────

add_action( 'rest_api_init', 'frp_leads_register_routes' );

function frp_leads_register_routes() {

    // POST /frp/v1/leads/{id}/status
    register_rest_route( 'frp/v1', '/leads/(?P<id>\d+)/status', [
        'methods'             => 'POST',
        'callback'            => 'frp_leads_update_status',
        'permission_callback' => 'frp_leads_permission_check',
        'args'                => [
            'id'     => [ 'validate_callback' => fn($v) => is_numeric($v) && $v > 0 ],
            'status' => [
                'required'          => true,
                'validate_callback' => fn($v) => in_array( $v, FRP_LEADS_STATUSES, true ),
            ],
        ],
    ] );

    // POST /frp/v1/admin/trigger-deadline-cron  (test/ops helper — will be fleshed out in Task 3)
    register_rest_route( 'frp/v1', '/admin/trigger-deadline-cron', [
        'methods'             => 'POST',
        'callback'            => 'frp_leads_trigger_deadline_cron',
        'permission_callback' => fn() => current_user_can( 'manage_options' ),
    ] );
}

function frp_leads_permission_check() {
    if ( ! is_user_logged_in() ) return new WP_Error( 'rest_forbidden', 'Authentication required.', [ 'status' => 401 ] );
    $pro_id = frp_current_pro_id();
    if ( ! $pro_id ) return new WP_Error( 'rest_forbidden', 'No contractor profile found.', [ 'status' => 403 ] );
    return true;
}

function frp_leads_update_status( WP_REST_Request $req ) {
    $lead_id    = (int) $req->get_param( 'id' );
    $new_status = $req->get_param( 'status' );
    $pro_id     = frp_current_pro_id();

    // Lead must exist
    $lead = get_post( $lead_id );
    if ( ! $lead || $lead->post_type !== 'frp_lead' ) {
        return new WP_Error( 'not_found', 'Lead not found.', [ 'status' => 404 ] );
    }

    // Pro must be the current assignee
    $current_assignee = (int) get_post_meta( $lead_id, 'lead_current_assignee', true );
    if ( $current_assignee !== $pro_id ) {
        return new WP_Error( 'rest_forbidden', 'You are not the current assignee for this lead.', [ 'status' => 403 ] );
    }

    // Update routing history
    $history_raw = get_post_meta( $lead_id, 'lead_routing_history', true );
    $history     = $history_raw ? json_decode( $history_raw, true ) : [];

    // Find this pro's entry
    $entry_idx = null;
    foreach ( $history as $i => $entry ) {
        if ( (int) $entry['pro_id'] === $pro_id ) {
            $entry_idx = $i;
            break;
        }
    }

    if ( $entry_idx === null ) {
        return new WP_Error( 'invalid_state', 'Routing history entry not found.', [ 'status' => 422 ] );
    }

    // Already responded check (responded_at already set and status not pending)
    if ( $history[ $entry_idx ]['responded_at'] !== null && $history[ $entry_idx ]['status'] !== 'pending' ) {
        return new WP_Error( 'already_actioned', 'Lead already actioned.', [ 'status' => 409 ] );
    }

    // Apply update
    $history[ $entry_idx ]['status']       = $new_status;
    $history[ $entry_idx ]['responded_at'] = time();

    update_post_meta( $lead_id, 'lead_routing_history',   wp_json_encode( $history ) );
    update_post_meta( $lead_id, 'lead_response_deadline', '' );  // clear deadline

    if ( $new_status === 'won' ) {
        update_post_meta( $lead_id, 'lead_current_assignee', '' );  // lead closed
    }

    return rest_ensure_response( [ 'ok' => true, 'status' => $new_status ] );
}

// Stub — will be replaced with full implementation in Task 3
function frp_leads_trigger_deadline_cron() {
    if ( function_exists( 'frp_process_lead_deadlines' ) ) {
        $processed = frp_process_lead_deadlines();
        return rest_ensure_response( [ 'processed' => $processed ] );
    }
    return rest_ensure_response( [ 'processed' => 0 ] );
}
