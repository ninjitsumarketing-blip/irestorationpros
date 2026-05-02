<?php
/**
 * Plugin Name: FRP Contractor Dashboard
 * Description: Authenticated contractor self-service (leads, profile, billing)
 * Version: 0.2.0
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
    frp_dashboard_styles();
    ?>
    <div class="frp-dashboard">
      <nav class="frp-dash-tabs" role="tablist">
        <button class="frp-tab-btn active" data-tab="leads"     role="tab" aria-selected="true">Leads</button>
        <button class="frp-tab-btn"        data-tab="analytics" role="tab" aria-selected="false">Analytics</button>
        <button class="frp-tab-btn"        data-tab="profile"   role="tab" aria-selected="false">Profile</button>
        <button class="frp-tab-btn"        data-tab="billing"   role="tab" aria-selected="false">Billing</button>
      </nav>

      <section id="frp-tab-leads"     class="frp-tab-pane active"><?php echo frp_dashboard_leads_html( $pro_id ); ?></section>
      <section id="frp-tab-analytics" class="frp-tab-pane frp-analytics-tab"></section>
      <section id="frp-tab-profile"   class="frp-tab-pane"><?php echo frp_dashboard_profile_html( $pro_id ); ?></section>
      <section id="frp-tab-billing"   class="frp-tab-pane"><?php echo frp_dashboard_billing_html( $pro_id ); ?></section>
    </div>
    <?php
    // Inline the nonce directly — wp_localize_script requires an array and
    // wp-api-request is not guaranteed to be enqueued on all themes.
    echo '<script>var frpDashNonce = "' . esc_js( wp_create_nonce( 'wp_rest' ) ) . '";</script>';
    ?>
    <script>
    (function () {
      // ── Tab switching ──────────────────────────────────────────────────
      var analyticsLoaded = false;
      document.querySelectorAll('.frp-tab-btn').forEach(function (btn) {
        btn.addEventListener('click', function () {
          document.querySelectorAll('.frp-tab-btn').forEach(function(b){ b.classList.remove('active'); b.setAttribute('aria-selected','false'); });
          document.querySelectorAll('.frp-tab-pane').forEach(function(p){ p.classList.remove('active'); });
          btn.classList.add('active');
          btn.setAttribute('aria-selected','true');
          var tab = btn.dataset.tab;
          document.getElementById('frp-tab-' + tab).classList.add('active');
          if (tab === 'analytics' && !analyticsLoaded) {
            analyticsLoaded = true;
            frpLoadAnalytics();
          }
        });
      });

      // ── Lead card expand/collapse ─────────────────────────────────────
      document.addEventListener('click', function (e) {
        var header = e.target.closest('.frp-lead-header');
        if (!header) return;
        var card = header.closest('.frp-lead-card');
        if (!card) return;
        card.classList.toggle('frp-expanded');
      });

      // ── Status action buttons ─────────────────────────────────────────
      document.addEventListener('click', function (e) {
        var btn = e.target.closest('.frp-action-btn');
        if (!btn) return;
        var card   = btn.closest('.frp-lead-card');
        var leadId = card ? card.dataset.leadId : null;
        var status = btn.dataset.status;
        if (!leadId || !status) return;

        btn.disabled = true;
        btn.textContent = 'Saving…';

        fetch('/wp-json/frp/v1/leads/' + leadId + '/status', {
          method:  'POST',
          headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': frpDashNonce },
          body:    JSON.stringify({ status: status }),
        })
        .then(function (r) { return r.json().then(function(b){ return { ok: r.ok, body: b }; }); })
        .then(function (res) {
          if (res.ok) {
            frpUpdateCardStatus(card, status);
          } else {
            var msg = (res.body && res.body.message) ? res.body.message : 'Error updating lead.';
            alert(msg);
            btn.disabled = false;
            btn.textContent = btn.dataset.label;
          }
        })
        .catch(function () {
          alert('Network error. Please try again.');
          btn.disabled = false;
          btn.textContent = btn.dataset.label;
        });
      });

      function frpUpdateCardStatus(card, status) {
        var statusEl = card.querySelector('.frp-status-pill');
        if (statusEl) {
          statusEl.className = 'frp-status-pill frp-status-' + status;
          statusEl.textContent = frpStatusLabel(status);
        }
        // Hide action buttons once responded
        var actions = card.querySelector('.frp-lead-actions');
        if (actions) actions.style.display = 'none';
        // Hide countdown
        var countdown = card.querySelector('.frp-countdown');
        if (countdown) countdown.style.display = 'none';
      }

      function frpStatusLabel(s) {
        return { contacted:'Contacted', won:'Won', lost:'Lost', pending:'Pending', missed:'Missed' }[s] || s;
      }

      // ── Countdown timers ──────────────────────────────────────────────
      function frpTickCountdowns() {
        document.querySelectorAll('[data-deadline]').forEach(function (el) {
          var deadline = parseInt(el.dataset.deadline, 10);
          var now      = Math.floor(Date.now() / 1000);
          var diff     = deadline - now;
          if (diff <= 0) {
            el.textContent = 'Overdue';
            el.classList.add('frp-countdown-overdue');
          } else {
            var h = Math.floor(diff / 3600);
            var m = Math.floor((diff % 3600) / 60);
            el.textContent = 'Respond within ' + (h > 0 ? h + 'h ' : '') + m + 'm';
          }
        });
      }
      setInterval(frpTickCountdowns, 1000);
      frpTickCountdowns();

      // ── Analytics loader ──────────────────────────────────────────────
      function frpLoadAnalytics() {
        var container = document.getElementById('frp-tab-analytics');
        container.innerHTML = '<div class="frp-analytics-loading">Loading analytics…</div>';

        fetch('/wp-json/frp/v1/me/stats', {
          headers: { 'X-WP-Nonce': frpDashNonce }
        })
        .then(function(r){ return r.json().then(function(b){ return { ok: r.ok, body: b }; }); })
        .then(function(res){
          if (!res.ok) throw new Error((res.body && res.body.message) ? res.body.message : 'API error');
          frpRenderAnalytics(container, res.body);
        })
        .catch(function(){ container.innerHTML = '<p class="frp-error">Failed to load analytics.</p>'; });
      }

      function frpRenderAnalytics(container, d) {
        var rateColor = d.response_rate === null ? '' :
          d.response_rate >= 0.8 ? 'frp-indicator-green' :
          d.response_rate >= 0.5 ? 'frp-indicator-amber' : 'frp-indicator-red';

        var sparkBars = (d.leads_by_week || []).map(function(w){
          return '<span class="frp-spark-bar" style="height:' + Math.max(4, (w.count * 16)) + 'px" title="' + w.week + ': ' + w.count + '"></span>';
        }).join('');

        container.innerHTML =
          '<div class="frp-analytics-cards">' +
            '<div class="frp-stat-card">' +
              '<div class="frp-stat-value">' + (d.leads_received_total || 0) + '</div>' +
              '<div class="frp-stat-label">Leads Received</div>' +
              '<div class="frp-sparkline">' + sparkBars + '</div>' +
            '</div>' +
            '<div class="frp-stat-card">' +
              '<div class="frp-stat-value">' + (d.response_rate !== null && rateColor ? '<span class="frp-stat-dot ' + rateColor + '"></span>' : '') + (d.response_rate !== null ? Math.round(d.response_rate * 100) + '%' : '—') + '</div>' +
              '<div class="frp-stat-label">Response Rate</div>' +
            '</div>' +
            '<div class="frp-stat-card">' +
              '<div class="frp-stat-value">' + (d.win_rate !== null ? Math.round(d.win_rate * 100) + '%' : '—') + '</div>' +
              '<div class="frp-stat-label">Win Rate</div>' +
            '</div>' +
          '</div>' +
          '<div class="frp-recent-leads">' +
            '<h3 class="frp-section-title">Recent Leads</h3>' +
            (d.recent_leads && d.recent_leads.length ?
              frpBuildLeadsTable(d.recent_leads) :
              '<p class="frp-empty-state">No leads yet.</p>'
            ) +
          '</div>';

        // Wire up table sort after rendering
        frpWireSortableTable(container, d.recent_leads || []);
      }

      // Build sortable leads table HTML (default sort: date desc)
      function frpBuildLeadsTable(leads, sortKey, sortDir) {
        sortKey = sortKey || 'assigned_at';
        sortDir = sortDir || 'desc';
        var sorted = leads.slice().sort(function(a, b) {
          var av = a[sortKey] || '', bv = b[sortKey] || '';
          if (av < bv) return sortDir === 'asc' ? -1 :  1;
          if (av > bv) return sortDir === 'asc' ?  1 : -1;
          return 0;
        });
        var rows = sorted.map(function(l) {
          var date = l.assigned_at ? new Date(l.assigned_at * 1000).toLocaleDateString() : '—';
          return '<tr>' +
            '<td>' + frpEsc(l.service) + '</td>' +
            '<td>' + frpEsc(l.city) + '</td>' +
            '<td><span class="frp-urgency-pill frp-urgency-' + frpEsc(l.urgency) + '">' + frpEsc(l.urgency) + '</span></td>' +
            '<td><span class="frp-status-pill frp-status-' + frpEsc(l.status) + '">' + frpStatusLabel(l.status) + '</span></td>' +
            '<td data-ts="' + (l.assigned_at || 0) + '">' + date + '</td>' +
          '</tr>';
        }).join('');
        var arrowDate   = sortKey === 'assigned_at' ? (sortDir === 'asc' ? ' ↑' : ' ↓') : '';
        var arrowStatus = sortKey === 'status'      ? (sortDir === 'asc' ? ' ↑' : ' ↓') : '';
        return '<table class="frp-leads-table" data-sort-key="' + sortKey + '" data-sort-dir="' + sortDir + '">' +
          '<thead><tr>' +
            '<th>Service</th>' +
            '<th>City</th>' +
            '<th>Urgency</th>' +
            '<th class="frp-sortable" data-sort="status">Status' + arrowStatus + '</th>' +
            '<th class="frp-sortable" data-sort="assigned_at">Date' + arrowDate + '</th>' +
          '</tr></thead>' +
          '<tbody>' + rows + '</tbody>' +
        '</table>';
      }

      function frpWireSortableTable(container, leads) {
        container.querySelectorAll('.frp-sortable').forEach(function(th) {
          th.style.cursor = 'pointer';
          th.addEventListener('click', function() {
            var table   = th.closest('table');
            var sortKey = th.dataset.sort;
            var prevKey = table.dataset.sortKey;
            var prevDir = table.dataset.sortDir || 'desc';
            var newDir  = (sortKey === prevKey && prevDir === 'desc') ? 'asc' : 'desc';
            var parent  = table.parentNode;
            parent.innerHTML = frpBuildLeadsTable(leads, sortKey, newDir);
            frpWireSortableTable(container, leads);
          });
        });
      }

      function frpEsc(s) {
        if (!s) return '';
        return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
      }
    })();
    </script>
    <?php
    return ob_get_clean();
}

// ── Lead Cards ────────────────────────────────────────────────────────────────

function frp_dashboard_leads_html( $pro_id ) {
    $q = new WP_Query( [
        'post_type'      => 'frp_lead',
        'posts_per_page' => 50,
        'post_status'    => 'publish',
        'no_found_rows'  => true,
        'meta_query'     => [[
            'key'     => 'lead_routing_history',
            'value'   => '"pro_id":' . $pro_id,
            'compare' => 'LIKE',
        ]],
        'orderby' => 'date',
        'order'   => 'DESC',
    ] );

    if ( ! $q->have_posts() ) {
        return '<div class="frp-empty-state"><p>No leads yet. Hang tight.</p></div>';
    }

    $active_html = '';
    $missed_html = '';
    $now         = time();

    foreach ( $q->posts as $lead ) {
        $id      = $lead->ID;
        $history = json_decode( get_post_meta( $id, 'lead_routing_history', true ) ?: '[]', true );

        // Find this pro's entry
        $my_entry = null;
        foreach ( $history as $entry ) {
            if ( (int) $entry['pro_id'] === $pro_id ) { $my_entry = $entry; break; }
        }
        if ( ! $my_entry ) continue;

        $status    = $my_entry['status'] ?? 'pending';
        $city      = esc_html( get_post_meta( $id, 'lead_city',          true ) );
        $svc       = get_post_meta( $id, 'lead_service',       true );
        $urgency   = get_post_meta( $id, 'lead_urgency',     true );
        $score     = (int) get_post_meta( $id, 'lead_score',       true );
        $phone_raw = get_post_meta( $id, 'lead_phone',       true );
        $prop_type = esc_html( get_post_meta( $id, 'lead_property_type', true ) );
        $insurance = esc_html( get_post_meta( $id, 'lead_has_insurance', true ) );
        $scope     = esc_html( get_post_meta( $id, 'lead_scope',         true ) );
        $zip       = esc_html( get_post_meta( $id, 'lead_zip',           true ) );
        $notes     = esc_html( get_post_meta( $id, 'lead_notes',         true ) );
        $deadline  = (int) get_post_meta( $id, 'lead_response_deadline', true );

        $urgency_class = $urgency === 'emergency' ? 'frp-urgency-emergency' : ( $urgency === 'urgent' ? 'frp-urgency-urgent' : 'frp-urgency-standard' );
        $urgency_label = esc_html( ucfirst( $urgency ?: 'Standard' ) );
        $status_label  = ucfirst( $status );

        // Countdown (only for pending with a future deadline)
        $countdown_html = '';
        if ( $status === 'pending' && $deadline && $deadline > $now ) {
            $countdown_html = '<span class="frp-countdown" data-deadline="' . esc_attr( $deadline ) . '">…</span>';
        }

        // Action buttons (only for pending, current assignee)
        $current_assignee = (int) get_post_meta( $id, 'lead_current_assignee', true );
        $actions_html     = '';
        if ( $status === 'pending' && $current_assignee === $pro_id ) {
            $actions_html =
                '<div class="frp-lead-actions">' .
                '<button class="frp-action-btn frp-btn-primary"  data-status="contacted" data-label="Mark Contacted">Mark Contacted</button>' .
                '<button class="frp-action-btn frp-btn-outline"  data-status="won"       data-label="Mark Won">Mark Won</button>' .
                '<button class="frp-action-btn frp-btn-outline frp-btn-danger" data-status="lost" data-label="Mark Lost">Mark Lost</button>' .
                '</div>';
        }

        $phone_href    = esc_url( 'tel:' . $phone_raw );
        $phone_display = esc_html( $phone_raw );

        $card_html =
            '<div class="frp-lead-card' . ( $status === 'missed' ? ' frp-lead-missed' : '' ) . '" data-lead-id="' . esc_attr( $id ) . '">' .
              '<div class="frp-lead-header">' .
                '<span class="frp-service-badge">' . esc_html( $svc ) . '</span>' .
                '<span class="frp-city">' . $city . '</span>' .
                '<span class="frp-urgency-pill ' . esc_attr( $urgency_class ) . '">' . $urgency_label . '</span>' .
                '<span class="frp-score-badge">Score ' . $score . '</span>' .
                ( $status === 'pending' ? $countdown_html : '<span class="frp-status-pill frp-status-' . esc_attr( $status ) . '">' . esc_html( $status_label ) . '</span>' ) .
                '<span class="frp-expand-icon" aria-hidden="true">›</span>' .
              '</div>' .
              '<div class="frp-lead-details">' .
                '<dl class="frp-lead-meta">' .
                  ( $score     ? '<dt>Lead Score</dt><dd>' . $score . '</dd>'   : '' ) .
                  ( $prop_type ? '<dt>Property</dt><dd>' . $prop_type . '</dd>' : '' ) .
                  ( $insurance ? '<dt>Insurance</dt><dd>' . $insurance . '</dd>' : '' ) .
                  ( $scope     ? '<dt>Scope</dt><dd>' . $scope . '</dd>'         : '' ) .
                  ( $zip       ? '<dt>ZIP</dt><dd>' . $zip . '</dd>'             : '' ) .
                  ( $notes     ? '<dt>Notes</dt><dd>' . $notes . '</dd>'         : '' ) .
                '</dl>' .
                ( $status !== 'missed'
                    ? '<a class="frp-phone-link" href="' . $phone_href . '">' . $phone_display . '</a>' . $actions_html
                    : '<p class="frp-missed-notice">You missed this lead — it was reassigned.</p>'
                ) .
              '</div>' .
            '</div>';

        if ( $status === 'missed' ) {
            $missed_html .= $card_html;
        } else {
            $active_html .= $card_html;
        }
    }

    $out = '<div class="frp-leads-list">';
    $out .= $active_html ?: '<p class="frp-empty-state">No active leads.</p>';
    if ( $missed_html ) {
        $out .= '<h3 class="frp-section-title frp-missed-title">Missed Leads</h3>' . $missed_html;
    }
    $out .= '</div>';
    return $out;
}

// ── Profile + Billing (unchanged) ────────────────────────────────────────────

function frp_dashboard_profile_html( $pro_id ) {
    $pro = get_post( $pro_id );
    if ( ! $pro ) {
        return '<p class="frp-dashboard-error">Profile not found. Contact support.</p>';
    }
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

// ── REST endpoint: leads-html (keep for backward compat) ─────────────────────

add_action( 'rest_api_init', function () {
    register_rest_route( 'frp/v1', '/me/leads-html', [
        'methods'             => 'GET',
        'callback'            => function () {
            $pro_id = frp_current_pro_id();
            return rest_ensure_response( [ 'html' => frp_dashboard_leads_html( $pro_id ) ] );
        },
        'permission_callback' => function () {
            return is_user_logged_in() && frp_current_pro_id();
        },
    ] );

    // Returns the full dashboard shortcode HTML — used by integration tests
    // to verify dashboard structure without requiring cookie-based auth.
    register_rest_route( 'frp/v1', '/me/dashboard-html', [
        'methods'             => 'GET',
        'callback'            => function () {
            return rest_ensure_response( [ 'html' => frp_dashboard_render() ] );
        },
        'permission_callback' => function () {
            return is_user_logged_in() && frp_current_pro_id();
        },
    ] );
} );

// ── CSS ───────────────────────────────────────────────────────────────────────

function frp_dashboard_styles() {
    ?>
    <style>
    /* ── Reset & Base ─────────────────────────────────────────── */
    .frp-dashboard { font-family: 'Inter', system-ui, sans-serif; color: #191c1d; max-width: 900px; margin: 0 auto; padding: 0 0 48px; }

    /* ── Tabs ─────────────────────────────────────────────────── */
    .frp-dash-tabs { display: flex; gap: 2px; background: #edeeef; padding: 4px; border-radius: 10px; margin-bottom: 24px; }
    .frp-tab-btn { flex: 1; padding: 8px 16px; border: none; background: transparent; border-radius: 8px; font-size: 14px; font-weight: 600; color: #43474f; cursor: pointer; transition: background 0.15s, color 0.15s; }
    .frp-tab-btn.active { background: #fff; color: #001e40; box-shadow: 0 1px 4px rgba(0,0,0,.08); }
    .frp-tab-pane { display: none; }
    .frp-tab-pane.active { display: block; }

    /* ── Lead Cards ───────────────────────────────────────────── */
    .frp-lead-card { background: #fff; border: 1px solid #e1e3e4; border-radius: 12px; margin-bottom: 12px; box-shadow: 0 1px 4px rgba(0,0,0,.05); overflow: hidden; transition: box-shadow 0.15s; }
    .frp-lead-card:hover { box-shadow: 0 4px 12px rgba(0,0,0,.09); }
    .frp-lead-header { display: flex; align-items: center; gap: 10px; padding: 14px 16px; cursor: pointer; flex-wrap: wrap; }
    .frp-expand-icon { margin-left: auto; color: #737780; font-size: 18px; transition: transform 0.2s; line-height: 1; }
    .frp-lead-card.frp-expanded .frp-expand-icon { transform: rotate(90deg); }
    .frp-lead-details { max-height: 0; overflow: hidden; transition: max-height 0.25s ease; padding: 0 16px; border-top: 1px solid #f3f4f5; }
    .frp-lead-card.frp-expanded .frp-lead-details { max-height: 600px; padding-bottom: 16px; }
    .frp-lead-meta { display: grid; grid-template-columns: max-content 1fr; gap: 6px 12px; margin: 14px 0; font-size: 13px; }
    .frp-lead-meta dt { color: #737780; font-weight: 600; }
    .frp-lead-meta dd { margin: 0; }

    /* Missed cards */
    .frp-lead-missed { opacity: .65; }
    .frp-missed-title { font-size: 12px; text-transform: uppercase; letter-spacing: .06em; color: #737780; margin: 24px 0 10px; }
    .frp-missed-notice { font-size: 13px; color: #737780; font-style: italic; margin: 6px 0 0; }

    /* ── Pills & Badges ───────────────────────────────────────── */
    .frp-service-badge { background: #d5e3ff; color: #001b3c; font-size: 12px; font-weight: 700; padding: 3px 8px; border-radius: 6px; }
    .frp-city { font-size: 14px; font-weight: 500; }
    .frp-score-badge { font-size: 12px; color: #43474f; background: #f3f4f5; padding: 3px 8px; border-radius: 6px; font-weight: 600; }

    .frp-urgency-pill { font-size: 11px; font-weight: 700; text-transform: uppercase; padding: 3px 8px; border-radius: 20px; letter-spacing: .04em; }
    .frp-urgency-emergency { background: #ffdad6; color: #93000a; }
    .frp-urgency-urgent    { background: #ffe0b2; color: #7a3500; }
    .frp-urgency-standard  { background: #e1e3e4; color: #43474f; }

    .frp-status-pill { font-size: 11px; font-weight: 700; text-transform: uppercase; padding: 3px 8px; border-radius: 20px; }
    .frp-status-won       { background: #c8f0c8; color: #1a5c1a; }
    .frp-status-contacted { background: #dce8ff; color: #0c3a7a; }
    .frp-status-lost      { background: #e1e3e4; color: #43474f; }
    .frp-status-missed    { background: #ffdad6; color: #93000a; }
    .frp-status-pending   { background: #ffe0b2; color: #7a3500; }

    /* Countdown */
    .frp-countdown { font-family: 'Courier New', monospace; font-size: 12px; background: #001e40; color: #a7c8ff; padding: 3px 8px; border-radius: 6px; font-weight: 700; }
    .frp-countdown-overdue { background: #ba1a1a; color: #fff; }

    /* Phone link */
    .frp-phone-link { display: inline-block; margin-top: 8px; font-size: 16px; font-weight: 700; color: #001e40; text-decoration: none; border-bottom: 2px solid #a7c8ff; }
    .frp-phone-link:hover { color: #003366; }

    /* Action buttons */
    .frp-lead-actions { display: flex; gap: 8px; margin-top: 14px; flex-wrap: wrap; }
    .frp-action-btn { padding: 9px 18px; border-radius: 8px; font-size: 13px; font-weight: 700; cursor: pointer; transition: opacity 0.15s, transform 0.1s; border: 2px solid transparent; }
    .frp-action-btn:active { transform: scale(.97); }
    .frp-action-btn:disabled { opacity: .5; cursor: not-allowed; }
    .frp-btn-primary { background: #001e40; color: #fff; border-color: #001e40; }
    .frp-btn-primary:hover { background: #003366; }
    .frp-btn-outline { background: transparent; color: #001e40; border-color: #001e40; }
    .frp-btn-outline:hover { background: #f0f4ff; }
    .frp-btn-danger { color: #ba1a1a; border-color: #ba1a1a; }
    .frp-btn-danger:hover { background: #fff0f0; }

    /* ── Analytics ────────────────────────────────────────────── */
    .frp-analytics-cards { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 16px; margin-bottom: 32px; }
    .frp-stat-card { background: #fff; border: 1px solid #e1e3e4; border-radius: 12px; padding: 20px 24px; box-shadow: 0 1px 4px rgba(0,0,0,.05); }
    .frp-stat-value { font-size: 36px; font-weight: 800; color: #001e40; line-height: 1.1; }
    .frp-stat-label { font-size: 12px; font-weight: 600; text-transform: uppercase; letter-spacing: .06em; color: #737780; margin-top: 4px; }
    .frp-stat-dot { display: inline-block; width: 8px; height: 8px; border-radius: 50%; margin-right: 6px; vertical-align: middle; }
    .frp-stat-dot.frp-indicator-green { background: #1a5c1a; }
    .frp-stat-dot.frp-indicator-amber { background: #7a3500; }
    .frp-stat-dot.frp-indicator-red   { background: #ba1a1a; }
    .frp-indicator-green { color: #1a5c1a; }
    .frp-indicator-amber { color: #7a3500; }
    .frp-indicator-red   { color: #ba1a1a; }
    .frp-sparkline { display: flex; align-items: flex-end; gap: 3px; height: 32px; margin-top: 12px; }
    .frp-spark-bar { flex: 1; background: #a7c8ff; border-radius: 2px 2px 0 0; min-height: 4px; }

    /* Analytics table */
    .frp-leads-table { width: 100%; border-collapse: collapse; font-size: 13px; }
    .frp-leads-table th { text-align: left; padding: 8px 12px; font-weight: 600; color: #43474f; border-bottom: 2px solid #e1e3e4; font-size: 11px; text-transform: uppercase; letter-spacing: .06em; }
    .frp-leads-table th.frp-sortable { cursor: pointer; user-select: none; }
    .frp-leads-table th.frp-sortable:hover { color: #001e40; }
    .frp-leads-table td { padding: 10px 12px; border-bottom: 1px solid #f3f4f5; }
    .frp-leads-table tr:last-child td { border-bottom: none; }
    .frp-leads-table tr:hover td { background: #f8f9fa; }

    .frp-section-title { font-size: 13px; font-weight: 700; text-transform: uppercase; letter-spacing: .06em; color: #43474f; margin: 0 0 14px; }
    .frp-analytics-loading { padding: 32px; text-align: center; color: #737780; }
    .frp-empty-state { color: #737780; font-size: 14px; padding: 16px 0; }
    .frp-dashboard-error { color: #93000a; padding: 12px; background: #ffdad6; border-radius: 8px; }
    </style>
    <?php
}
