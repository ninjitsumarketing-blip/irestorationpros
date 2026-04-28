<?php
/**
 * Plugin Name: FRP Billing
 * Description: Stripe Checkout + subscription mapping for restoration_pro listings
 * Version: 0.1.0
 * Requires Plugins: frp-directory
 */
if ( ! defined( 'ABSPATH' ) ) exit;

// Stripe SDK — loaded only when vendor/autoload.php is present.
// Install via: composer require stripe/stripe-php (Task 1.6 setup step).
$frp_autoload = __DIR__ . '/vendor/autoload.php';
if ( file_exists( $frp_autoload ) ) {
    require_once $frp_autoload;
}

const FRP_TIERS = [
    'basic'    => [ 'label' => 'Basic Listing',    'option' => 'frp_stripe_price_basic',    'price_usd' => 4900  ],
    'paid'     => [ 'label' => 'Paid Listing',     'option' => 'frp_stripe_price_paid',     'price_usd' => 24900 ],
    'featured' => [ 'label' => 'Featured Listing', 'option' => 'frp_stripe_price_featured', 'price_usd' => 49900 ],
];

add_action( 'rest_api_init', function () {
    register_rest_route( 'frp/v1', '/billing/catalog', [
        'methods'             => 'GET',
        'callback'            => 'frp_billing_catalog',
        'permission_callback' => '__return_true',
    ] );
} );

function frp_billing_catalog() {
    $out = [];
    foreach ( FRP_TIERS as $id => $meta ) {
        $out[] = [
            'id'               => $id,
            'label'            => $meta['label'],
            'price_usd_cents'  => $meta['price_usd'],
            'price_configured' => (bool) get_option( $meta['option'] ),
        ];
    }
    return rest_ensure_response( [ 'tiers' => $out ] );
}

// Admin settings page — lets ops paste the three Stripe price_ids
add_action( 'admin_menu', function () {
    add_options_page( 'FRP Billing', 'FRP Billing', 'manage_options', 'frp-billing', 'frp_billing_settings_page' );
} );

add_action( 'admin_init', function () {
    foreach ( FRP_TIERS as $meta ) {
        register_setting( 'frp_billing', $meta['option'], [ 'sanitize_callback' => 'sanitize_text_field' ] );
    }
} );

function frp_billing_settings_page() {
    ?>
    <div class="wrap">
      <h1>FRP Billing — Stripe Price IDs</h1>
      <p>Paste the Stripe <code>price_...</code> ID for each tier (test-mode IDs for staging, live IDs for production).</p>
      <form method="post" action="options.php">
        <?php settings_fields( 'frp_billing' ); ?>
        <table class="form-table">
          <?php foreach ( FRP_TIERS as $id => $meta ): ?>
            <tr>
              <th scope="row"><?php echo esc_html( $meta['label'] ); ?></th>
              <td>
                <input type="text"
                       name="<?php echo esc_attr( $meta['option'] ); ?>"
                       value="<?php echo esc_attr( (string) get_option( $meta['option'] ) ); ?>"
                       class="regular-text"
                       placeholder="price_...">
              </td>
            </tr>
          <?php endforeach; ?>
        </table>
        <?php submit_button(); ?>
      </form>
    </div>
    <?php
}

add_action( 'rest_api_init', function() {
    register_rest_route( 'frp/v1', '/billing/checkout', [
        'methods'             => 'POST',
        'callback'            => 'frp_billing_checkout',
        'permission_callback' => function() {
            return is_user_logged_in() && in_array( 'restoration_pro', (array) wp_get_current_user()->roles, true );
        },
    ] );
} );

function frp_billing_checkout( WP_REST_Request $r ) {
    $tier = (string) $r->get_param( 'tier' );
    if ( ! isset( FRP_TIERS[ $tier ] ) ) {
        return new WP_Error( 'bad_request', 'Unknown tier.', [ 'status' => 400 ] );
    }
    $price_id = (string) get_option( FRP_TIERS[ $tier ]['option'] );
    if ( ! $price_id ) {
        return new WP_Error( 'not_configured', 'Tier price not configured.', [ 'status' => 500 ] );
    }
    if ( ! defined( 'FRP_STRIPE_SECRET_KEY' ) ) {
        return new WP_Error( 'not_configured', 'Stripe secret not configured.', [ 'status' => 500 ] );
    }
    if ( ! class_exists( '\Stripe\Stripe' ) ) {
        return new WP_Error( 'not_configured', 'Stripe SDK not loaded.', [ 'status' => 500 ] );
    }

    $pro_id = frp_current_pro_id();
    if ( ! $pro_id ) {
        return new WP_Error( 'no_pro', 'User is not bound to a pro.', [ 'status' => 403 ] );
    }

    try {
        \Stripe\Stripe::setApiKey( FRP_STRIPE_SECRET_KEY );

        $customer_id = (string) get_user_meta( get_current_user_id(), 'frp_stripe_customer_id', true );
        if ( ! $customer_id ) {
            $customer = \Stripe\Customer::create( [
                'email'    => wp_get_current_user()->user_email,
                'metadata' => [ 'frp_pro_id' => (string) $pro_id, 'wp_user_id' => (string) get_current_user_id() ],
            ] );
            $customer_id = $customer->id;
            update_user_meta( get_current_user_id(), 'frp_stripe_customer_id', $customer_id );
        }

        $base    = home_url();
        $session = \Stripe\Checkout\Session::create( [
            'mode'         => 'subscription',
            'customer'     => $customer_id,
            'line_items'   => [ [ 'price' => $price_id, 'quantity' => 1 ] ],
            'success_url'  => $base . '/contractor/dashboard/?billing=success&session_id={CHECKOUT_SESSION_ID}',
            'cancel_url'   => $base . '/contractor/dashboard/?billing=cancelled',
            'metadata'     => [ 'frp_pro_id' => (string) $pro_id, 'tier' => $tier ],
            'subscription_data' => [
                'metadata' => [ 'frp_pro_id' => (string) $pro_id, 'tier' => $tier ],
            ],
        ] );

        return [ 'checkout_url' => $session->url, 'session_id' => $session->id ];
    } catch ( \Stripe\Exception\ApiErrorException $e ) {
        return new WP_Error( 'stripe_error', $e->getMessage(), [ 'status' => 502 ] );
    } catch ( \Throwable $e ) {
        return new WP_Error( 'stripe_error', 'Unexpected billing error.', [ 'status' => 500 ] );
    }
}
