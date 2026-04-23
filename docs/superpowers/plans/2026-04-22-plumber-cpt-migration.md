# Plumber CPT Migration Implementation Plan

> **For agentic workers:** REQUIRED: Use superpowers:subagent-driven-development (if subagents available) or superpowers:executing-plans to implement this plan. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Create `frp-plumbers.php` — a WordPress mu-plugin that registers a container `plumber` CPT and provides a WP Admin migration tool to move sewage-cleanup-only `restoration_pro` posts out of the directory search pool.

**Architecture:** Single self-contained mu-plugin file. CPT is private (no frontend, no REST), visible only in WP Admin. Admin migration page lives under Tools → Migrate to Plumbers, shows a dry-run preview, and runs the migration on form submit with per-post success/failure tracking. Nothing in existing plugins is modified.

**Tech Stack:** PHP 7.4+, WordPress mu-plugin, WP Admin UI (no JS framework, plain HTML forms + WP nonce).

---

## Context for implementers

- All mu-plugin files live in `wordpress-plugins/` in the repo and are deployed to `wp-content/mu-plugins/` on SiteGround via File Manager (no WP-CLI).
- Services are stored as comma-separated string in post meta key `services` (e.g. `"water-damage,sewage-cleanup"`). The migration targets posts where this value is **exactly** `"sewage-cleanup"` — SQL `=` comparison, not `LIKE`.
- `wp_update_post(['ID' => $id, 'post_type' => 'plumber'])` is the migration operation — preserves all meta, title, content, status.
- The directory search queries `post_type = 'restoration_pro'` explicitly, so changing `post_type` is enough to remove a post from all zip/service searches.
- There are no automated Node.js integration tests for WP Admin pages — verification is manual on staging before running on live.

---

## File Map

| File | Action | Purpose |
|---|---|---|
| `wordpress-plugins/frp-plumbers.php` | **Create** | CPT registration + admin migration page |

---

## Task 1: Create `frp-plumbers.php`

**Files:**
- Create: `wordpress-plugins/frp-plumbers.php`

- [ ] **Step 1: Create the file with complete implementation**

```php
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
```

- [ ] **Step 2: Commit**

```bash
git add wordpress-plugins/frp-plumbers.php
git commit -m "feat(frp): Plumber CPT + WP Admin migration tool for sewage-cleanup-only profiles"
```

---

## Task 2: Deploy to staging and verify

**No code changes — deployment and manual verification only.**

- [ ] **Step 1: Upload to staging**

Upload `wordpress-plugins/frp-plumbers.php` to `wp-content/mu-plugins/frp-plumbers.php` on staging via SiteGround File Manager.

- [ ] **Step 2: Verify CPT is registered**

Log into WP Admin on staging. You should see **Plumbers** in the left sidebar. If it doesn't appear immediately, do a hard refresh (Cmd+Shift+R / Ctrl+Shift+R) — this is normal first-activation behaviour for mu-plugins with `show_ui = true`. (A visit to Settings → Permalinks → Save is sometimes mentioned online but is not required here since `rewrite = false`.)

Expected: "Plumbers" menu item appears in the WP Admin left sidebar.

- [ ] **Step 3: Verify migration page loads**

Navigate to **Tools → Migrate to Plumbers**.

Expected:
- Page loads without errors
- Shows a count of matching `restoration_pro` posts
- Table lists matching posts with ID, business name, post status
- "Run Migration" button is visible (or "Nothing to migrate" if count is 0)

- [ ] **Step 4: Run migration on staging**

Click **Run Migration** and confirm the prompt.

Expected:
- Green success notice: "**X** profile(s) migrated to Plumbers." (no failure line when 0 failed)
- Table below now shows 0 candidates remaining ("Nothing to migrate" info notice appears)
- If any failures: yellow notice shows "**X** migrated. **Y** failed (IDs: ... — see error log)." — note the failed post IDs and check PHP error log

- [ ] **Step 5: Verify results on staging**

1. Go to **Plumbers** in the WP Admin sidebar → confirm the migrated posts appear there with correct titles
2. Go to **Restoration Pros** → confirm the sewage-cleanup-only profiles are no longer listed
3. On the staging frontend, run a zip code search for a zip that previously returned sewage-cleanup-only pros → confirm they no longer appear in results
4. Find a multi-service pro that includes `sewage-cleanup` alongside other services → confirm it still appears in Restoration Pros (unaffected)

---

## Task 3: Deploy to live and run migration

**No code changes — mirrors Task 2 on production.**

- [ ] **Step 1: Upload to live**

Upload `wordpress-plugins/frp-plumbers.php` to `wp-content/mu-plugins/frp-plumbers.php` on the live site via SiteGround File Manager.

- [ ] **Step 2: Verify page loads on live**

Log into WP Admin on the live site. Navigate to **Tools → Migrate to Plumbers**.

Expected: Page loads, shows count of candidates (should match or be close to staging count unless live has additional/different profiles).

- [ ] **Step 3: Run migration on live**

Click **Run Migration** and confirm.

Expected: Green success notice with migrated count and 0 failures.

- [ ] **Step 4: Verify results on live**

1. **Plumbers** sidebar → confirm migrated posts are there
2. **Restoration Pros** → confirm sewage-cleanup-only profiles gone
3. Live zip code search → confirm no sewage-cleanup-only pros appear in results
