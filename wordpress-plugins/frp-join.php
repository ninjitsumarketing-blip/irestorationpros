<?php
/**
 * Plugin Name: FRP Join Form
 * Description: [frp_join_form] shortcode — join page application form for restoration contractors.
 * Version: 1.0.0
 */
if ( ! defined( 'ABSPATH' ) ) exit;

add_shortcode( 'frp_join_form', 'frp_join_form_render' );

function frp_join_form_render() : string {
    $apply_url   = wp_json_encode(
        rest_url( 'frp/v1/apply' ),
        JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT
    );
    $pricing_url = esc_url( home_url( '/pricing/' ) );

    $us_states = [
        'AL' => 'Alabama',        'AK' => 'Alaska',         'AZ' => 'Arizona',
        'AR' => 'Arkansas',       'CA' => 'California',     'CO' => 'Colorado',
        'CT' => 'Connecticut',    'DE' => 'Delaware',       'DC' => 'District of Columbia',
        'FL' => 'Florida',        'GA' => 'Georgia',        'HI' => 'Hawaii',
        'ID' => 'Idaho',          'IL' => 'Illinois',       'IN' => 'Indiana',
        'IA' => 'Iowa',           'KS' => 'Kansas',         'KY' => 'Kentucky',
        'LA' => 'Louisiana',      'ME' => 'Maine',          'MD' => 'Maryland',
        'MA' => 'Massachusetts',  'MI' => 'Michigan',       'MN' => 'Minnesota',
        'MS' => 'Mississippi',    'MO' => 'Missouri',       'MT' => 'Montana',
        'NE' => 'Nebraska',       'NV' => 'Nevada',         'NH' => 'New Hampshire',
        'NJ' => 'New Jersey',     'NM' => 'New Mexico',     'NY' => 'New York',
        'NC' => 'North Carolina', 'ND' => 'North Dakota',   'OH' => 'Ohio',
        'OK' => 'Oklahoma',       'OR' => 'Oregon',         'PA' => 'Pennsylvania',
        'RI' => 'Rhode Island',   'SC' => 'South Carolina', 'SD' => 'South Dakota',
        'TN' => 'Tennessee',      'TX' => 'Texas',          'UT' => 'Utah',
        'VT' => 'Vermont',        'VA' => 'Virginia',       'WA' => 'Washington',
        'WV' => 'West Virginia',  'WI' => 'Wisconsin',      'WY' => 'Wyoming',
    ];

    $service_options = [
        'water-damage'      => 'Water Damage',
        'fire-damage'       => 'Fire & Smoke Damage',
        'mold-remediation'  => 'Mold Remediation',
        'storm-damage'      => 'Storm Damage',
        'sewage-cleanup'    => 'Sewage Cleanup',
        'structural'        => 'Structural Repairs',
        'biohazard-cleanup' => 'Biohazard Cleanup',
    ];

    ob_start();
    ?>
<style>
/* ── FRP Join Form ── */
.frp-join-wrap *,
.frp-join-wrap *::before,
.frp-join-wrap *::after { box-sizing: border-box; }
.frp-join-hero { text-align: center; padding: 2.5rem 1rem 2rem; max-width: 680px; margin: 0 auto; }
.frp-join-hero h1 { font-size: clamp(1.6rem, 3.5vw, 2.2rem); font-weight: 700; line-height: 1.25; margin: 0 0 .75rem; color: #0f172a; }
.frp-join-hero p  { font-size: 1.05rem; color: #475569; margin: 0; line-height: 1.6; }
.frp-join-form-section { max-width: 680px; margin: 0 auto 3rem; padding: 0 1rem; }
.frp-join-form .frp-field { margin-bottom: 1.25rem; }
.frp-join-form .frp-label { display: block; font-size: .875rem; font-weight: 600; color: #1e293b; margin-bottom: .35rem; }
.frp-join-form input[type="text"],
.frp-join-form input[type="email"],
.frp-join-form input[type="tel"],
.frp-join-form input[type="number"],
.frp-join-form select { width: 100%; border: 1px solid #cbd5e1; border-radius: .375rem; padding: .6rem .75rem; font-size: 1rem; background: #fff; color: #0f172a; }
.frp-join-form input:focus,
.frp-join-form select:focus { outline: 2px solid #2563eb; outline-offset: 1px; border-color: transparent; }
.frp-services-grid { display: flex; flex-wrap: wrap; gap: .5rem; }
.frp-service-chip { display: flex; align-items: center; gap: .4rem; padding: .35rem .7rem; border: 1px solid #cbd5e1; border-radius: 999px; font-size: .875rem; cursor: pointer; background: #f8fafc; color: #334155; transition: background .15s, border-color .15s; }
.frp-service-chip input { margin: 0; }
.frp-service-chip:has(input:checked) { background: #dbeafe; border-color: #2563eb; color: #1d4ed8; font-weight: 600; }
.frp-iicrc-options { display: flex; gap: 1rem; flex-wrap: wrap; margin-top: .25rem; }
.frp-iicrc-option { display: flex; align-items: center; gap: .4rem; font-size: .9rem; cursor: pointer; }
.frp-iicrc-helper { font-size: .8rem; color: #64748b; margin-top: .4rem; }
.frp-join-btn { display: block; width: 100%; padding: .85rem 1.5rem; background: #1d4ed8; color: #fff; font-size: 1rem; font-weight: 700; border: none; border-radius: .375rem; cursor: pointer; margin-top: 1.75rem; transition: background .15s; }
.frp-join-btn:hover { background: #1e40af; }
.frp-join-btn:disabled { background: #94a3b8; cursor: not-allowed; }
.frp-join-notice { font-size: .85rem; color: #64748b; margin-top: .75rem; line-height: 1.5; }
.frp-join-notice a { color: #2563eb; }
.frp-join-error { background: #fef2f2; border: 1px solid #fca5a5; color: #991b1b; padding: .75rem 1rem; border-radius: .375rem; font-size: .9rem; margin-top: 1rem; display: none; }
.frp-join-success { background: #f0fdf4; border: 1px solid #86efac; color: #166534; padding: 1.5rem; border-radius: .5rem; font-size: 1rem; line-height: 1.6; text-align: center; display: none; }
/* Benefit cards */
.frp-join-benefits { background: #f8fafc; padding: 3rem 1rem; }
.frp-benefits-inner { max-width: 960px; margin: 0 auto; display: grid; grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); gap: 1.5rem; }
.frp-benefit-card { background: #fff; border-radius: .5rem; padding: 1.5rem; border: 1px solid #e2e8f0; }
.frp-benefit-card h3 { font-size: 1rem; font-weight: 700; color: #0f172a; margin: 0 0 .5rem; }
.frp-benefit-card p  { font-size: .875rem; color: #475569; margin: 0; line-height: 1.6; }
</style>

<div class="frp-join-wrap">

<section class="frp-join-hero">
    <h1>Claim your free listing and start receiving leads from homeowners in your area.</h1>
    <p>Get your restoration business in front of people searching for help right now. Free to claim, no commitment required.</p>
</section>

<section class="frp-join-form-section">
    <div id="frp-join-success" class="frp-join-success">
        Application received. Check your email &mdash; we&rsquo;ll be in touch within 1&ndash;2 business days.
    </div>

    <form id="frp-join-form" class="frp-join-form" novalidate>

        <div class="frp-field">
            <label class="frp-label" for="frp-business-name">Business Name <span aria-hidden="true">*</span></label>
            <input type="text" id="frp-business-name" name="business_name" required autocomplete="organization" placeholder="Your business name">
        </div>

        <div class="frp-field">
            <label class="frp-label" for="frp-phone">Phone <span aria-hidden="true">*</span></label>
            <input type="tel" id="frp-phone" name="dispatch_phone" required autocomplete="tel" placeholder="(555) 555-5555">
        </div>

        <div class="frp-field">
            <label class="frp-label" for="frp-email">Email <span aria-hidden="true">*</span></label>
            <input type="email" id="frp-email" name="contact_email" required autocomplete="email" placeholder="you@example.com">
        </div>

        <div class="frp-field">
            <label class="frp-label" for="frp-state">State <span aria-hidden="true">*</span></label>
            <select id="frp-state" name="state" required>
                <option value="">Select your state&hellip;</option>
                <?php foreach ( $us_states as $code => $name ) : ?>
                <option value="<?php echo esc_attr( $code ); ?>"><?php echo esc_html( $name ); ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="frp-field">
            <label class="frp-label">Services Offered</label>
            <div class="frp-services-grid">
                <?php foreach ( $service_options as $slug => $label ) : ?>
                <label class="frp-service-chip">
                    <input type="checkbox" name="services" value="<?php echo esc_attr( $slug ); ?>">
                    <?php echo esc_html( $label ); ?>
                </label>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="frp-field">
            <label class="frp-label">IICRC Certified?</label>
            <div class="frp-iicrc-options">
                <label class="frp-iicrc-option"><input type="radio" name="iicrc_certified" value="yes"> Yes</label>
                <label class="frp-iicrc-option"><input type="radio" name="iicrc_certified" value="no"> No</label>
                <label class="frp-iicrc-option"><input type="radio" name="iicrc_certified" value="in_progress"> In Progress</label>
            </div>
            <p class="frp-iicrc-helper">IICRC certification is displayed on your profile as a badge and improves credibility with homeowners.</p>
        </div>

        <div class="frp-field">
            <label class="frp-label" for="frp-license">License Number</label>
            <input type="text" id="frp-license" name="license_number" autocomplete="off" placeholder="Optional">
        </div>

        <div class="frp-field">
            <label class="frp-label" for="frp-years">Years in Business</label>
            <input type="number" id="frp-years" name="years_in_business" min="0" max="100" placeholder="Optional">
        </div>

        <div class="frp-field">
            <label class="frp-label" for="frp-zips">Service Area ZIP Codes</label>
            <input type="text" id="frp-zips" name="service_area_zips" placeholder="90210, 90211, 90212 &mdash; optional, comma-separated">
        </div>

        <div id="frp-join-error" class="frp-join-error" role="alert" aria-live="polite"></div>

        <button type="submit" id="frp-join-submit" class="frp-join-btn">Claim My Free Listing &rarr;</button>

        <p class="frp-join-notice">Already in our directory? We&rsquo;ll find your listing and send a verification email to confirm ownership.</p>
        <p class="frp-join-notice"><em>Paid upgrades available after you claim your listing. <a href="<?php echo $pricing_url; ?>">See what&rsquo;s included &rarr;</a></em></p>

    </form>
</section>

<section class="frp-join-benefits">
    <div class="frp-benefits-inner">
        <div class="frp-benefit-card">
            <h3>Your listing, your credentials</h3>
            <p>Once claimed, your business name, services, and credentials appear on your profile. IICRC certification and other self-reported credentials are displayed as badges to homeowners searching in your area.</p>
        </div>
        <div class="frp-benefit-card">
            <h3>Get matched with homeowners</h3>
            <p>When a homeowner submits a restoration request matching your service type and location, you get notified. Free listings receive leads routed through our matching system.</p>
        </div>
        <div class="frp-benefit-card">
            <h3>Paid listing: more visibility, direct contact</h3>
            <p>Paid listings unlock your phone number on your profile so homeowners can call you directly. When a homeowner contacts you from your profile page, that lead comes to you alone. Paid listings are also highlighted in directory search results and featured on the homepage.</p>
        </div>
    </div>
</section>

</div><!-- .frp-join-wrap -->

<script>
(function () {
    'use strict';
    var APPLY_URL = <?php echo $apply_url; ?>;
    var form      = document.getElementById('frp-join-form');
    var submitBtn = document.getElementById('frp-join-submit');
    var errorEl   = document.getElementById('frp-join-error');
    var successEl = document.getElementById('frp-join-success');
    var cooldown  = null;

    form.addEventListener('submit', function (e) {
        e.preventDefault();
        clearInterval(cooldown);
        submitBtn.disabled = true;
        errorEl.style.display = 'none';
        errorEl.textContent = '';

        // Collect services as array — FormData.forEach only returns the last
        // checked value for duplicate keys, so we query the DOM directly.
        var services = Array.from(
            form.querySelectorAll('input[name="services"]:checked')
        ).map(function (cb) { return cb.value; });

        // Collect remaining fields as a plain object
        var fd = new FormData(form);
        var body = {};
        fd.forEach(function (val, key) {
            if (key !== 'services') { body[key] = val; }
        });
        // Omit services key entirely when none checked — the /apply endpoint
        // treats a missing services param the same as an empty list (optional field).
        // Do NOT send services: [] to avoid any edge-case handling of empty JSON array.
        if (services.length) { body.services = services; }

        // years_in_business: endpoint uses absint — send as integer
        if (body.years_in_business !== undefined && body.years_in_business !== '') {
            body.years_in_business = parseInt(body.years_in_business, 10) || 0;
        }

        fetch(APPLY_URL, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(body),
        })
        .then(function (res) {
            return res.json().then(function (data) {
                return { status: res.status, data: data };
            });
        })
        .then(function (result) {
            if (result.status === 200) {
                form.style.display = 'none';
                successEl.style.display = 'block';
                successEl.scrollIntoView({ behavior: 'smooth', block: 'center' });
            } else if (result.status === 400) {
                var msg = (result.data && result.data.message)
                    ? result.data.message
                    : 'Please check your information and try again.';
                errorEl.textContent = msg;
                errorEl.style.display = 'block';
                submitBtn.disabled = false;
            } else if (result.status === 429) {
                errorEl.textContent = 'Too many applications from this connection. Please try again later.';
                errorEl.style.display = 'block';
                var remaining = 60;
                submitBtn.textContent = 'Try again in ' + remaining + 's';
                cooldown = setInterval(function () {
                    remaining -= 1;
                    if (remaining <= 0) {
                        clearInterval(cooldown);
                        submitBtn.disabled = false;
                        submitBtn.textContent = 'Claim My Free Listing \u2192';
                    } else {
                        submitBtn.textContent = 'Try again in ' + remaining + 's';
                    }
                }, 1000);
            } else {
                errorEl.textContent = 'Something went wrong. Please try again.';
                errorEl.style.display = 'block';
                submitBtn.disabled = false;
            }
        })
        .catch(function () {
            errorEl.textContent = 'Something went wrong. Please try again.';
            errorEl.style.display = 'block';
            submitBtn.disabled = false;
        });
    });
})();
</script>
    <?php
    return ob_get_clean();
}
