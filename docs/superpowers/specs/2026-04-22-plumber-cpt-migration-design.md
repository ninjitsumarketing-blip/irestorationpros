# Plumber CPT Migration Design

**Date:** 2026-04-22
**Status:** Approved

---

## Goal

Remove sewage-cleanup-only profiles from the `restoration_pro` directory pool by migrating them into a new `plumber` Custom Post Type. This prevents plumber-only listings from diluting restoration company search results.

---

## Background

Services are stored on `restoration_pro` posts as a comma-separated string in the `services` post meta key (e.g., `"water-damage,sewage-cleanup"`). The lead-matching and directory search queries filter by `post_type = 'restoration_pro'`, so changing a post's type is sufficient to remove it from all search results — no query changes needed.

**In-scope:** `restoration_pro` posts where `services` is exactly `"sewage-cleanup"` (sole service, no others).

**Out-of-scope:** Multi-service pros that include `sewage-cleanup` alongside other services — these remain in `restoration_pro`.

---

## Approach

A new mu-plugin `frp-plumbers.php` deployed to `wp-content/mu-plugins/` on both staging and live. It registers the `plumber` CPT and provides a WP Admin migration tool. Nothing in `frp-directory.php` or `frp-billing.php` is modified.

---

## Architecture

### File

`wordpress-plugins/frp-plumbers.php` — new mu-plugin, self-contained.

### Components

**1. `plumber` CPT Registration**

| Setting | Value | Reason |
|---|---|---|
| `public` | `false` | No frontend URLs, no archive, invisible to site visitors |
| `show_ui` | `true` | Visible in WP Admin sidebar for inspection |
| `show_in_nav_menus` | `false` | Not a navigable resource yet |
| `show_in_rest` | `false` | No API exposure needed at this stage |
| `has_archive` | `false` | No archive page |
| `rewrite` | `false` | No permalink rules |
| `supports` | `['title']` | Minimal — all data lives in post meta |
| Capability (admin page) | `manage_options` | Both page registration and form submission |

All existing post meta (`services`, `phone`, `listing_status`, `business_name`, etc.) is preserved untouched — only `post_type` changes.

**Note on WP Admin sidebar:** Because `rewrite = false`, no permalink flush is needed. However, after uploading the plugin for the first time, the "Plumbers" sidebar item may require a page reload or a visit to Settings → Permalinks before it appears. This is a normal WordPress CPT registration behaviour.

**2. WP Admin Migration Page**

- Location: **Tools → Migrate to Plumbers** (registered via `add_management_page`, capability `manage_options`)
- Nonce-protected form (action: `frp_migrate_to_plumbers`)

**Dry-run preview (always shown, before and after migration):**
- Count of `restoration_pro` posts matching `services = 'sewage-cleanup'` (exact, not LIKE)
- Table columns: **Post ID** | **Business Name** (post title) | **Post Status** (`post_status` field: publish / draft / pending / private / trash (excluded by query))
- "Run Migration" submit button

**Post-run notice:**
- `"X profiles migrated to Plumbers. Y failed (see error log)."` — always shows both counts
- If 0 matches and 0 failures: `"Nothing to migrate — no sewage-cleanup-only restoration pros found."`
- Idempotent: safe to run multiple times (already-migrated posts have `post_type = plumber` and are not found by the query)

**3. Migration Logic**

```
WP_Query:
  post_type     = restoration_pro
  post_status   = ['publish', 'draft', 'pending', 'private']   ← excludes trash (see note)
  meta_query:
    key     = services
    value   = sewage-cleanup
    compare = =          ← exact string match on the raw meta value
  no_found_rows = true
  posts_per_page = -1

For each result:
  $result = wp_update_post(['ID' => $id, 'post_type' => 'plumber'])
  if $result === 0 or is_wp_error($result):
    $failed[]  = $id
    error_log("frp-plumbers: failed to migrate post $id")
  else:
    $migrated[] = $id
```

**Why `compare = =` is correct:** WordPress translates this to a SQL `=` comparison against the full raw meta value string. `services = 'sewage-cleanup'` will NOT match `'sewage-cleanup,water-damage'` or `'water-damage,sewage-cleanup'` because those are different strings. `LIKE` must not be used — it would match partial substrings and pull in multi-service pros.

**Why trash is excluded:** Trashed posts are already hidden from all public-facing queries. Migrating them provides no benefit and could confuse admin users who see migrated content in the Plumbers trash. If needed, a separate manual step can handle trashed posts.

`wp_update_post` with only `post_type` changed preserves all meta, title, content, status, and dates.

---

## Data Flow

```
WP Admin clicks "Run Migration"
  → nonce verified
  → WP_Query finds restoration_pro posts where services = 'sewage-cleanup' exactly
     (excludes trash)
  → foreach post:
      wp_update_post(['post_type' => 'plumber'])
      → success: add to migrated count
      → failure: add to failed count, log post ID to error_log
  → admin notice: "X migrated. Y failed."
  → page re-renders dry-run preview (should now show 0 remaining)
  → next directory search automatically excludes moved posts (queries restoration_pro only)
```

---

## Effect on Search

No code changes needed to the directory search or lead-matching logic. The existing query:

```php
$query->set('post_type', 'restoration_pro');
```

...automatically excludes `plumber` posts from all zip/service/radius searches.

---

## Deployment Order

1. Upload `frp-plumbers.php` to staging `wp-content/mu-plugins/`
2. Visit WP Admin (a page reload may be needed for the "Plumbers" sidebar item to appear)
3. Open Tools → Migrate to Plumbers — verify dry-run count looks correct
4. Run migration on staging — confirm success notice shows expected count and 0 failures
5. Verify: WP Admin → Plumbers shows migrated posts; WP Admin → Restoration Pros no longer shows them
6. Upload `frp-plumbers.php` to live `wp-content/mu-plugins/`
7. Run migration on live

---

## Verification (Manual)

- WP Admin → Plumbers: shows migrated posts with all meta intact (same post IDs)
- WP Admin → Restoration Pros: sewage-cleanup-only profiles no longer listed
- Frontend zip/service search: no sewage-cleanup-only profiles in results
- Multi-service pros (e.g., `"water-damage,sewage-cleanup"`): unaffected, still in Restoration Pros
- Trashed sewage-cleanup-only restoration_pro posts: remain in trash, not migrated

---

## Future Considerations

- The `plumber` CPT is a container only. A future "Plumber Directory" feature would add public visibility, its own search page, billing tiers, and lead routing.
- The migration tool can be removed from the plugin once both environments are migrated, or left in place (it's idempotent and harmless).
