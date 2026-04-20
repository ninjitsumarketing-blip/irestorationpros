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
