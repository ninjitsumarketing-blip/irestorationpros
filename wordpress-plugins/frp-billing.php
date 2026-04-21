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
    'paid'     => [ 'label' => 'Paid Listing',     'option' => 'frp_stripe_price_paid',     'price_usd' => 19900 ],
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
