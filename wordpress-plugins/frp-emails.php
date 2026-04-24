<?php
/**
 * FRP transactional email handlers.
 * Hooked to plugin actions fired by frp-directory.php.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

// ─────────────────────────────────────────────────────────────
// CLAIM REQUEST EMAIL
// Fires when a strong/medium match is found on /apply.
// Sent to the on-file email (NOT the applicant's email) to prevent
// identity-theft via impersonation.
// ─────────────────────────────────────────────────────────────
add_action( 'frp_claim_requested', 'frp_email_claim_requested', 10, 2 );
function frp_email_claim_requested( $pro_id, $applicant ) {
    $onfile   = (string) get_post_meta( $pro_id, 'contact_email', true );
    $token    = (string) get_post_meta( $pro_id, 'claim_token',   true );
    $url      = home_url( "/claim/?pro={$pro_id}&token={$token}" );
    // Use home_url() — this action may fire from WP-Cron or CLI where
    // $_SERVER['HTTP_HOST'] is absent or spoofed.
    $host     = (string) parse_url( home_url(), PHP_URL_HOST );
    $business = get_the_title( $pro_id );

    $applicant_email = $applicant['contact_email'] ?? $applicant['email'] ?? '';
    $applicant_phone = $applicant['dispatch_phone'] ?? $applicant['phone'] ?? '';

    $subject = "Someone requested to claim your {$host} listing";
    $message = frp_claim_email_body( $business, $applicant_email, $applicant_phone, $url );

    wp_mail( $onfile, $subject, $message, [ 'Content-Type: text/html; charset=UTF-8' ] );
}

function frp_claim_email_body( string $business, string $applicant_email, string $applicant_phone, string $claim_url ) : string {
    $b   = esc_html( $business );
    $ae  = esc_html( $applicant_email );
    $ap  = esc_html( $applicant_phone );
    $url = esc_url( $claim_url );
    return "
<p>Hi,</p>
<p>Someone submitted a request to claim the listing for <strong>{$b}</strong> on Find Restoration Pros.</p>
<p><strong>Applicant email:</strong> {$ae}<br>
<strong>Applicant phone:</strong> {$ap}</p>
<p>If this is you, click the link below to verify and claim your listing:</p>
<p><a href=\"{$url}\">Claim your listing</a></p>
<p>If you did not request this, you can safely ignore this email. The link expires in 72 hours.</p>
<p>— Find Restoration Pros</p>
";
}

// ─────────────────────────────────────────────────────────────
// TEMPLATE ENGINE
// ─────────────────────────────────────────────────────────────

/**
 * Load an HTML email template and replace {{key}} placeholders.
 * Templates live at wordpress-plugins/templates/emails/{name}.html.
 * Values are HTML-escaped before substitution.
 *
 * @param string $name  Template name without .html extension.
 * @param array  $vars  Key-value pairs for placeholder replacement.
 * @return string Rendered HTML.
 */
function frp_email_tpl( string $name, array $vars = [], array $url_vars = [] ) : string {
    // Guard against path traversal — name must be lowercase alphanumeric + hyphens only.
    if ( ! preg_match( '/^[a-z0-9\-]+$/', $name ) ) {
        return '';
    }
    $path = __DIR__ . '/templates/emails/' . $name . '.html';
    $html = file_exists( $path ) ? file_get_contents( $path ) : '<p>{{body}}</p>';
    foreach ( $vars as $k => $v ) {
        $html = str_replace( '{{' . $k . '}}', esc_html( (string) $v ), $html );
    }
    foreach ( $url_vars as $k => $v ) {
        $html = str_replace( '{{' . $k . '}}', esc_url( (string) $v ), $html );
    }
    return $html;
}

// ─────────────────────────────────────────────────────────────
// APPLICATION SUBMITTED
// Fires when a pro submits an application via /apply.
// Sends confirmation to applicant + alert to admin.
// ─────────────────────────────────────────────────────────────

add_action( 'frp_application_submitted', 'frp_email_application', 10, 1 );
function frp_email_application( int $pro_id ) : void {
    $pro   = get_post( $pro_id );
    if ( ! $pro ) return;
    $email = (string) get_post_meta( $pro_id, 'contact_email', true );
    $admin = (string) get_option( 'admin_email' );

    if ( $email ) {
        wp_mail(
            $email,
            'We received your application — Find Restoration Pros',
            frp_email_tpl( 'application-applicant', [ 'business' => $pro->post_title ] ),
            [ 'Content-Type: text/html; charset=UTF-8' ]
        );
    }

    wp_mail(
        $admin,
        '[FRP] New application: ' . $pro->post_title,
        frp_email_tpl( 'application-admin', [
            'pro_id'   => $pro_id,
            'business' => $pro->post_title,
        ], [
            'edit_url' => admin_url( 'post.php?post=' . $pro_id . '&action=edit' ),
        ] ),
        [ 'Content-Type: text/html; charset=UTF-8' ]
    );
}

// ─────────────────────────────────────────────────────────────
// LEAD CREATED
// Fires when a homeowner submits a lead form.
// Sends confirmation to homeowner (if email provided).
// ─────────────────────────────────────────────────────────────

add_action( 'frp_lead_created', 'frp_email_lead_created', 10, 1 );
function frp_email_lead_created( int $lead_id ) : void {
    // lead_email is the homeowner's email (may also be written as lead_contact_email for profile_form)
    $email = (string) get_post_meta( $lead_id, 'lead_email', true );
    if ( ! $email || ! is_email( $email ) ) return;

    $token = (string) get_post_meta( $lead_id, 'lead_update_token', true );

    wp_mail(
        $email,
        'Your restoration request — Find Restoration Pros',
        frp_email_tpl( 'lead-homeowner', [
            'lead_id' => $lead_id,
        ], [
            'cancel_url' => home_url( '/lead-status/?id=' . $lead_id . '&token=' . rawurlencode( $token ) ),
        ] ),
        [ 'Content-Type: text/html; charset=UTF-8' ]
    );
}

// ─────────────────────────────────────────────────────────────
// SUBSCRIPTION ACTIVATED
// Fires when a pro's paid subscription goes live (Stripe webhook, Task 1.7).
// ─────────────────────────────────────────────────────────────

add_action( 'frp_subscription_activated', 'frp_email_sub_activated', 10, 2 );
function frp_email_sub_activated( int $pro_id, string $tier ) : void {
    $email = (string) get_post_meta( $pro_id, 'contact_email', true );
    if ( ! $email || ! is_email( $email ) ) return;

    wp_mail(
        $email,
        'Your Find Restoration Pros listing is live',
        frp_email_tpl( 'subscription-active', [
            'pro_id' => $pro_id,
            'tier'   => $tier,
        ], [
            'dashboard_url' => home_url( '/contractor/dashboard/' ),
        ] ),
        [ 'Content-Type: text/html; charset=UTF-8' ]
    );
}
