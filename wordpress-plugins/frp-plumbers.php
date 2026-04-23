<?php
/**
 * Plugin Name: FRP Plumbers
 * Description: Plumber CPT container and WP Admin migration tool for sewage-cleanup-only profiles.
 * Version: 0.1.0
 */
if ( ! defined( 'ABSPATH' ) ) exit;

// ─── CPT Registration ────────────────────────────────────────────────────────

add_action( 'init', function () {
    register_post_type( 'plumber', [
        'labels' => [
            'name'               => 'Plumbers',
            'singular_name'      => 'Plumber',
            'menu_name'          => 'Plumbers',
            'all_items'          => 'All Plumbers',
            'edit_item'          => 'Edit Plumber',
            'view_item'          => 'View Plumber',
            'search_items'       => 'Search Plumbers',
            'not_found'          => 'No plumbers found.',
            'not_found_in_trash' => 'No plumbers found in trash.',
        ],
        'public'            => false,   // No frontend URLs, no archive
        'show_ui'           => true,    // Visible in WP Admin sidebar
        'show_in_nav_menus' => false,
        'show_in_rest'      => false,   // No REST API exposure
        'has_archive'       => false,
        'rewrite'           => false,   // No permalink rules — no flush needed
        'supports'          => [ 'title' ],
        'capability_type'   => 'post',
        'map_meta_cap'      => true,
    ] );
} );

// ─── Admin Migration Page ─────────────────────────────────────────────────────

add_action( 'admin_menu', function () {
    add_management_page(
        'Migrate to Plumbers',    // Page title
        'Migrate to Plumbers',    // Menu label under Tools
        'manage_options',         // Capability required
        'frp-migrate-plumbers',   // Menu slug
        'frp_plumbers_migration_page'
    );
} );

/**
 * Query restoration_pro posts whose services is exactly "sewage-cleanup".
 * Uses SQL = (not LIKE) so multi-service strings like "sewage-cleanup,other" are excluded.
 * Excludes trash — trashed posts don't affect search and should be left alone.
 */
function frp_plumbers_get_candidates(): WP_Query {
    return new WP_Query( [
        'post_type'      => 'restoration_pro',
        'post_status'    => [ 'publish', 'draft', 'pending', 'private' ],
        'meta_query'     => [ [
            'key'     => 'services',
            'value'   => 'sewage-cleanup',
            'compare' => '=',
        ] ],
        'no_found_rows'  => true,
        'posts_per_page' => -1,
    ] );
}

function frp_plumbers_migration_page(): void {
    $migrated = null; // null = migration hasn't run yet this page load
    $failed   = null;

    // ── Handle form submission ────────────────────────────────────────────────
    if (
        isset( $_POST['frp_migrate_plumbers'] ) &&
        check_admin_referer( 'frp_migrate_to_plumbers', 'frp_plumbers_nonce' )
    ) {
        $migrated = [];
        $failed   = [];
        $run      = frp_plumbers_get_candidates();

        foreach ( $run->posts as $post ) {
            // Pass `true` so WP returns WP_Error (not 0) on failure — is_wp_error() catches it.
            $result = wp_update_post( [ 'ID' => $post->ID, 'post_type' => 'plumber' ], true );
            if ( is_wp_error( $result ) ) {
                $failed[] = $post->ID;
                error_log( 'frp-plumbers: failed to migrate post ' . $post->ID );
            } else {
                $migrated[] = $post->ID;
            }
        }
    }

    // ── Fresh dry-run preview (always shown) ──────────────────────────────────
    $query      = frp_plumbers_get_candidates();
    $candidates = $query->posts;

    ?>
    <div class="wrap">
        <h1>Migrate Sewage-Cleanup-Only Pros → Plumbers</h1>
        <p>
            Finds <code>restoration_pro</code> posts where <code>services</code> is
            <strong>exactly</strong> <code>sewage-cleanup</code> (sole service only —
            multi-service pros are excluded). Changes their <code>post_type</code>
            to <code>plumber</code>, removing them from all directory searches.
        </p>

        <?php if ( $migrated !== null ) : ?>
            <?php
            // Show "Nothing to migrate" if 0 candidates were found at run time (e.g. second run).
            if ( empty( $migrated ) && empty( $failed ) ) : ?>
                <div class="notice notice-info is-dismissible">
                    <p>Nothing to migrate — no sewage-cleanup-only restoration pros found.</p>
                </div>
            <?php else : ?>
                <div class="notice notice-<?php echo empty( $failed ) ? 'success' : 'warning'; ?> is-dismissible">
                    <p>
                        <strong><?php echo esc_html( count( $migrated ) ); ?></strong> profile(s) migrated to Plumbers.
                        <?php if ( ! empty( $failed ) ) : ?>
                            <strong><?php echo esc_html( count( $failed ) ); ?></strong> failed
                            (IDs: <?php echo esc_html( implode( ', ', $failed ) ); ?> — see error log).
                        <?php endif; ?>
                    </p>
                </div>
            <?php endif; ?>
        <?php endif; ?>

        <h2>Candidates remaining: <?php echo esc_html( count( $candidates ) ); ?></h2>

        <?php if ( ! empty( $candidates ) ) : ?>
            <table class="widefat striped" style="max-width:700px">
                <thead>
                    <tr>
                        <th style="width:80px">Post ID</th>
                        <th>Business Name</th>
                        <th style="width:120px">Post Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ( $candidates as $post ) : ?>
                        <tr>
                            <td><?php echo esc_html( $post->ID ); ?></td>
                            <td>
                                <a href="<?php echo esc_url( get_edit_post_link( $post->ID ) ); ?>">
                                    <?php echo esc_html( $post->post_title ?: '(no title)' ); ?>
                                </a>
                            </td>
                            <td><?php echo esc_html( $post->post_status ); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <form method="post" style="margin-top:1.5em">
                <?php wp_nonce_field( 'frp_migrate_to_plumbers', 'frp_plumbers_nonce' ); ?>
                <input type="submit"
                       name="frp_migrate_plumbers"
                       class="button button-primary"
                       value="Run Migration — move <?php echo esc_attr( count( $candidates ) ); ?> profile(s) to Plumbers"
                       onclick="return confirm('Move <?php echo esc_attr( count( $candidates ) ); ?> profile(s) to Plumbers? This cannot be undone from this tool.');">
            </form>
        <?php else : ?>
            <p><em>Nothing to migrate — no sewage-cleanup-only restoration pros found.</em></p>
        <?php endif; ?>
    </div>
    <?php
}
