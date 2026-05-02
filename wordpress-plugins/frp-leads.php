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
    $user = wp_get_current_user();
    if ( ! in_array( 'restoration_pro', (array) $user->roles, true ) ) {
        return new WP_Error( 'rest_forbidden', 'Contractor role required.', [ 'status' => 403 ] );
    }
    // Note: we don't verify the pro post is still published here.
    // The per-lead assignee check below provides the real access gate.
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

    // Already responded check (status not pending)
    if ( $history[ $entry_idx ]['status'] !== 'pending' ) {
        return new WP_Error( 'already_actioned', 'Lead already actioned.', [ 'status' => 409 ] );
    }

    // Apply update
    $history[ $entry_idx ]['status']       = $new_status;
    $history[ $entry_idx ]['responded_at'] = time();

    update_post_meta( $lead_id, 'lead_routing_history',   wp_json_encode( $history ) );
    update_post_meta( $lead_id, 'lead_response_deadline', '' );  // clear deadline

    // Both 'won' and 'lost' are terminal — clear assignee so the lead is not re-routable.
    if ( in_array( $new_status, [ 'won', 'lost' ], true ) ) {
        update_post_meta( $lead_id, 'lead_current_assignee', '' );  // lead closed
    }

    return rest_ensure_response( [ 'ok' => true, 'status' => $new_status ] );
}

function frp_leads_trigger_deadline_cron() {
    $processed = frp_process_lead_deadlines();
    return rest_ensure_response( [ 'processed' => $processed ] );
}

// ── WP-Cron: Lead Deadline Processor ─────────────────────────────────────────

// Register custom 15-minute interval
add_filter( 'cron_schedules', 'frp_leads_add_cron_interval' );
function frp_leads_add_cron_interval( $schedules ) {
    if ( ! isset( $schedules['frp_quarter_hour'] ) ) {
        $schedules['frp_quarter_hour'] = [
            'interval' => 15 * MINUTE_IN_SECONDS,
            'display'  => __( 'Every 15 Minutes' ),
        ];
    }
    return $schedules;
}

// Schedule on plugin load (guard against duplicate registration)
add_action( 'init', 'frp_leads_schedule_cron' );
function frp_leads_schedule_cron() {
    if ( ! wp_next_scheduled( 'frp_process_lead_deadlines' ) ) {
        wp_schedule_event( time(), 'frp_quarter_hour', 'frp_process_lead_deadlines' );
    }
}

add_action( 'frp_process_lead_deadlines', 'frp_process_lead_deadlines' );

/**
 * Process overdue leads: mark missed, attempt fallback routing.
 * Called by WP-Cron and by the admin trigger endpoint.
 *
 * @return int Number of leads processed.
 */
function frp_process_lead_deadlines() {
    $now   = time();
    $count = 0;

    $overdue = new WP_Query( [
        'post_type'      => 'frp_lead',
        'post_status'    => 'publish',
        'posts_per_page' => 100,
        'meta_query'     => [
            'relation' => 'AND',
            [
                'key'     => 'lead_response_deadline',
                'value'   => $now,
                'compare' => '<',
                'type'    => 'NUMERIC',
            ],
            [
                'key'     => 'lead_current_assignee',
                'value'   => '',
                'compare' => '!=',
            ],
        ],
    ] );

    foreach ( $overdue->posts as $lead ) {
        $lead_id = $lead->ID;

        try {
            $history_raw      = get_post_meta( $lead_id, 'lead_routing_history', true );
            $history          = $history_raw ? json_decode( $history_raw, true ) : [];
            $current_assignee = (int) get_post_meta( $lead_id, 'lead_current_assignee', true );

            // Find the current assignee's entry and mark missed
            foreach ( $history as &$entry ) {
                if ( (int) $entry['pro_id'] === $current_assignee && $entry['status'] === 'pending' ) {
                    $entry['status']       = 'missed';
                    $entry['responded_at'] = $now;
                    break;
                }
            }
            unset( $entry );

            // Collect all tried pro IDs for exclusion
            $tried_ids = array_map( fn($e) => (int) $e['pro_id'], $history );

            // Attempt fallback routing
            $zip     = get_post_meta( $lead_id, 'lead_zip',     true );
            $service = get_post_meta( $lead_id, 'lead_service', true );
            $urgency = get_post_meta( $lead_id, 'lead_urgency', true );

            $next_pro_id = 0;
            if ( $zip && $service && function_exists( 'frp_find_dispatch_pros' ) ) {
                $result = frp_find_dispatch_pros( $zip, $service );
                foreach ( $result['pros'] ?? [] as $candidate ) {
                    $cid = (int) $candidate['post_id'];
                    if ( ! in_array( $cid, $tried_ids, true ) ) {
                        $next_pro_id = $cid;
                        break;
                    }
                }
            }

            if ( $next_pro_id ) {
                // Route to next pro
                $deadline_delta = ( $urgency === 'emergency' ) ? HOUR_IN_SECONDS : DAY_IN_SECONDS;
                $history[] = [
                    'pro_id'       => $next_pro_id,
                    'assigned_at'  => $now,
                    'responded_at' => null,
                    'status'       => 'pending',
                ];
                update_post_meta( $lead_id, 'lead_routing_history',   wp_json_encode( $history ) );
                update_post_meta( $lead_id, 'lead_current_assignee',  $next_pro_id );
                update_post_meta( $lead_id, 'lead_response_deadline', $now + $deadline_delta );

                // Trigger notification email to next pro
                do_action( 'frp_lead_created', $lead_id );
            } else {
                // No more pros — lead exhausted
                update_post_meta( $lead_id, 'lead_routing_history',   wp_json_encode( $history ) );
                update_post_meta( $lead_id, 'lead_current_assignee',  '' );
                update_post_meta( $lead_id, 'lead_response_deadline', '' );
            }

            $count++;
        } catch ( Throwable $e ) {
            error_log( "[frp_process_lead_deadlines] Error on lead {$lead_id}: " . $e->getMessage() );
            // Continue to next lead — don't halt the batch
        }
    }

    return $count;
}
