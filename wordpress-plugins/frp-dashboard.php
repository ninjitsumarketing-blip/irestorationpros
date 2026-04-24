<?php
/**
 * Plugin Name: FRP Contractor Dashboard
 * Description: Authenticated contractor self-service (leads, profile, billing)
 * Version: 0.1.0
 */
if ( ! defined( 'ABSPATH' ) ) exit;

add_shortcode( 'frp_contractor_dashboard', 'frp_dashboard_render' );

function frp_dashboard_render() {
    if ( ! is_user_logged_in() ) {
        return frp_dashboard_login_form();
    }
    $user = wp_get_current_user();
    if ( ! in_array( 'restoration_pro', (array) $user->roles, true ) ) {
        return '<div class="frp-dashboard-error">Your account does not have contractor access. <a href="/join/">Apply to join</a>.</div>';
    }
    $pro_id = frp_current_pro_id();
    if ( ! $pro_id ) {
        return '<div class="frp-dashboard-error">Your account is not linked to a profile yet. Contact support.</div>';
    }
    ob_start();
    ?>
    <div class="frp-dashboard">
      <nav class="frp-dash-tabs">
        <a href="#leads" class="active">Leads</a>
        <a href="#profile">Profile</a>
        <a href="#billing">Billing</a>
      </nav>
      <section id="leads"><?php echo frp_dashboard_leads_html( $pro_id ); ?></section>
      <section id="profile"><?php echo frp_dashboard_profile_html( $pro_id ); ?></section>
      <section id="billing"><?php echo frp_dashboard_billing_html( $pro_id ); ?></section>
    </div>
    <?php
    return ob_get_clean();
}

function frp_dashboard_leads_html( $pro_id ) {
    $q = new WP_Query( [
        'post_type'      => 'frp_lead',
        'posts_per_page' => 50,
        'post_status'    => 'publish',
        'meta_query'     => [[
            'key'     => 'lead_assigned_pros',
            'value'   => (string) $pro_id,
            'compare' => 'LIKE',
        ]],
        'orderby'        => 'date',
        'order'          => 'DESC',
    ] );
    if ( ! $q->have_posts() ) return '<p>No leads yet. Hang tight.</p>';
    $out = '<ul class="frp-lead-list">';
    foreach ( $q->posts as $lead ) {
        $id    = $lead->ID;
        $city  = esc_html( get_post_meta( $id, 'lead_city', true ) );
        $svc   = esc_html( get_post_meta( $id, 'lead_service', true ) );
        $urg   = esc_html( get_post_meta( $id, 'lead_urgency', true ) );
        $score = (int) get_post_meta( $id, 'lead_score', true );
        $phone = esc_html( get_post_meta( $id, 'lead_phone', true ) );
        $out .= "<li data-lead-id='{$id}'><strong>{$svc}</strong> · {$city} · urgency:{$urg} · score:{$score} · <a href='tel:{$phone}'>{$phone}</a></li>";
    }
    $out .= '</ul>';
    return $out;
}

function frp_dashboard_profile_html( $pro_id ) {
    $pro = get_post( $pro_id );
    return '<h3>' . esc_html( $pro->post_title ) . '</h3>' .
           '<p>Listing tier: ' . esc_html( get_post_meta( $pro_id, 'listing_tier', true ) ?: 'free' ) . '</p>';
}

function frp_dashboard_billing_html( $pro_id ) {
    $cust = get_post_meta( $pro_id, 'frp_stripe_customer_id', true );
    if ( ! $cust ) {
        return '<p>No subscription. <a href="#" onclick="frpStartCheckout(\'paid\')">Start Paid Listing</a></p>';
    }
    return '<p><a href="' . esc_url( rest_url( 'frp/v1/billing/portal' ) ) . '">Manage subscription →</a></p>';
}

function frp_dashboard_login_form() {
    ob_start();
    ?>
    <div class="frp-dashboard-login">
      <h2>Contractor sign in</h2>
      <?php echo wp_login_form( [ 'echo' => false, 'redirect' => home_url( '/contractor/dashboard/' ) ] ); ?>
      <p><a href="<?php echo esc_url( wp_lostpassword_url() ); ?>">Forgot password?</a></p>
    </div>
    <?php
    return ob_get_clean();
}

add_action( 'rest_api_init', function () {
    register_rest_route( 'frp/v1', '/me/leads-html', [
        'methods'             => 'GET',
        'callback'            => function () {
            $pro_id = frp_current_pro_id();
            if ( ! $pro_id ) {
                return new WP_Error( 'no_pro', 'Not linked to a pro.', [ 'status' => 403 ] );
            }
            return rest_ensure_response( [ 'html' => frp_dashboard_leads_html( $pro_id ) ] );
        },
        'permission_callback' => function () {
            return is_user_logged_in() && frp_current_pro_id();
        },
    ] );
} );
