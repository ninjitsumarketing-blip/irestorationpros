<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Score applicant against existing restoration_pro posts.
 * Return highest-confidence hit.
 *
 * @param array $a Applicant data. Canonical keys (used by match-test endpoint):
 *                 license_number, google_place_id, yelp_id, dispatch_phone, business_name, website, state.
 *                 Alias keys (used by frp_apply_validate return value):
 *                 license → license_number, phone → dispatch_phone, business → business_name.
 * @return array { tier: 'strong'|'medium'|'weak'|'none', pro_id: int, reason: string, signals: array }
 */
function frp_match_applicant( array $a ) : array {
    // ── Strong-tier lookups (unique identifiers) ──────────────────────
    // Key aliases: frp_apply_validate() returns 'license' (not 'license_number').
    // The canonical matcher keys are used by the match-test endpoint; both are accepted.
    $key_aliases = [ 'license_number' => 'license' ];
    foreach ( [ 'license_number', 'google_place_id', 'yelp_id' ] as $key ) {
        $val = trim( (string) ( $a[ $key ] ?? '' ) );
        if ( $val === '' && isset( $key_aliases[ $key ] ) ) {
            $val = trim( (string) ( $a[ $key_aliases[ $key ] ] ?? '' ) );
        }
        if ( $val === '' ) continue;
        $hit = frp_find_pro_by_meta( $key, $val );
        if ( $hit ) {
            return [ 'tier' => 'strong', 'pro_id' => $hit, 'reason' => "exact {$key} match", 'signals' => [ $key => $val ] ];
        }
    }

    // ── Medium-tier lookups ───────────────────────────────────────────
    // frp_apply_validate() returns 'phone' and 'business'; accept both forms.
    $phone_norm = frp_normalize_phone( $a['dispatch_phone'] ?? $a['phone'] ?? '' );
    $name_norm  = frp_normalize_name( $a['business_name'] ?? $a['business'] ?? '' );
    if ( $phone_norm ) {
        foreach ( frp_find_pros_by_meta( 'phone', $phone_norm, 'normalized' ) as $pid ) {
            $candidate_name = frp_normalize_name( get_the_title( $pid ) );
            if ( frp_name_similarity( $name_norm, $candidate_name ) >= 0.85 ) {
                return [ 'tier' => 'medium', 'pro_id' => $pid, 'reason' => 'phone + fuzzy name', 'signals' => [ 'phone' => $phone_norm ] ];
            }
        }
    }

    $domain = frp_extract_domain( $a['website'] ?? '' );
    $state  = strtoupper( trim( (string) ( $a['state'] ?? '' ) ) );
    if ( $domain && $state ) {
        foreach ( frp_find_pros_by_domain( $domain ) as $pid ) {
            if ( strtoupper( (string) get_post_meta( $pid, 'state', true ) ) === $state ) {
                return [ 'tier' => 'medium', 'pro_id' => $pid, 'reason' => 'website domain + state', 'signals' => [ 'domain' => $domain, 'state' => $state ] ];
            }
        }
    }

    // ── Weak-tier lookups (name-only fuzzy) ───────────────────────────
    if ( $name_norm ) {
        foreach ( frp_find_pros_by_name_prefix( $name_norm ) as $pid ) {
            $sim = frp_name_similarity( $name_norm, frp_normalize_name( get_the_title( $pid ) ) );
            if ( $sim >= 0.90 ) {
                return [ 'tier' => 'weak', 'pro_id' => $pid, 'reason' => 'fuzzy name only', 'signals' => [ 'name_sim' => $sim ] ];
            }
        }
    }

    return [ 'tier' => 'none', 'pro_id' => 0, 'reason' => 'no signals matched', 'signals' => [] ];
}

function frp_normalize_phone( string $raw ) : string {
    $digits = preg_replace( '/\D+/', '', $raw );
    // Strip US country code
    if ( strlen( $digits ) === 11 && $digits[0] === '1' ) $digits = substr( $digits, 1 );
    return strlen( $digits ) === 10 ? $digits : '';
}

function frp_normalize_name( string $raw ) : string {
    $n = strtolower( trim( $raw ) );
    // Strip legal suffixes + articles ONLY. Do NOT strip "restoration", "pros",
    // or "professionals" — in a restoration directory those are the
    // discriminating words ("Acme Restoration" vs "Acme Plumbing" must not
    // collapse to identical tokens).
    $n = preg_replace( '/\b(inc|llc|llp|corp|corporation|co|company|ltd|limited|the)\b\.?/i', '', $n );
    // Strip punctuation
    $n = preg_replace( '/[^a-z0-9 ]/', ' ', $n );
    return trim( preg_replace( '/\s+/', ' ', $n ) );
}

function frp_name_similarity( string $a, string $b ) : float {
    if ( $a === '' || $b === '' ) return 0.0;
    similar_text( $a, $b, $pct );
    return $pct / 100.0;
}

function frp_extract_domain( string $url ) : string {
    if ( ! $url ) return '';
    $host = parse_url( $url, PHP_URL_HOST );
    if ( ! $host ) $host = parse_url( 'http://' . ltrim( $url, '/' ), PHP_URL_HOST );
    return $host ? strtolower( preg_replace( '/^www\./', '', $host ) ) : '';
}

function frp_find_pro_by_meta( string $key, string $value ) : int {
    $q = new WP_Query( [
        'post_type'      => 'restoration_pro',
        'post_status'    => [ 'publish', 'draft' ], // exclude trash — consistent with name-prefix query
        'posts_per_page' => 1,
        'fields'         => 'ids',
        'meta_query'     => [ [ 'key' => $key, 'value' => $value, 'compare' => '=' ] ],
    ] );
    return isset( $q->posts[0] ) ? (int) $q->posts[0] : 0;
}

function frp_find_pros_by_meta( string $key, string $value, string $variant = 'raw' ) : array {
    global $wpdb;

    // Phone matching: compare digits-only on both sides.
    // $value has already been normalized to exactly 10 digits by frp_normalize_phone.
    if ( $key === 'phone' && $variant === 'normalized' ) {
        // REGEXP_REPLACE requires MySQL 8.0.4+ / MariaDB 10.0.5+.
        // SiteGround runs MySQL 8+; if stack changes, the fallback below activates.
        $rows = $wpdb->get_col( $wpdb->prepare(
            "SELECT post_id FROM {$wpdb->postmeta}
             WHERE meta_key = 'phone'
               AND REGEXP_REPLACE(meta_value, '[^0-9]', '') = %s",
            $value
        ) );
        // Fallback for pre-8.0 MySQL — detected by error containing REGEXP_REPLACE.
        if ( $wpdb->last_error && str_contains( $wpdb->last_error, 'REGEXP_REPLACE' ) ) {
            $wpdb->last_error = '';
            $all = $wpdb->get_results(
                "SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = 'phone'"
            );
            $rows = [];
            foreach ( $all as $row ) {
                if ( frp_normalize_phone( $row->meta_value ) === $value ) {
                    $rows[] = $row->post_id;
                }
            }
        }
        return array_map( 'intval', (array) $rows );
    }

    $q = new WP_Query( [
        'post_type'      => 'restoration_pro',
        'post_status'    => [ 'publish', 'draft' ], // exclude trash — consistent with name-prefix query
        'posts_per_page' => 20,
        'fields'         => 'ids',
        'meta_query'     => [ [ 'key' => $key, 'value' => $value ] ],
    ] );
    return (array) $q->posts;
}

function frp_find_pros_by_domain( string $domain ) : array {
    global $wpdb;
    $rows = $wpdb->get_col( $wpdb->prepare(
        "SELECT post_id FROM {$wpdb->postmeta}
         WHERE meta_key = 'website' AND meta_value LIKE %s",
        '%' . $wpdb->esc_like( $domain ) . '%'
    ) );
    return array_map( 'intval', (array) $rows );
}

function frp_find_pros_by_name_prefix( string $name_norm ) : array {
    if ( strlen( $name_norm ) < 4 ) return [];
    global $wpdb;
    $prefix = substr( $name_norm, 0, 4 );
    $rows = $wpdb->get_col( $wpdb->prepare(
        "SELECT ID FROM {$wpdb->posts}
         WHERE post_type = 'restoration_pro'
           AND post_status IN ('publish','draft')
           AND LOWER(post_title) LIKE %s
         LIMIT 50",
        $wpdb->esc_like( $prefix ) . '%'
    ) );
    return array_map( 'intval', (array) $rows );
}
