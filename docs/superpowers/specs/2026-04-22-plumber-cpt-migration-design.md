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

All existing post meta (`services`, `phone`, `listing_status`, `business_name`, etc.) is preserved untouched — only `post_type` changes.

**2. WP Admin Migration Page**

- Location: **Tools → Migrate to Plumbers**
- Capability required: `manage_options`
- Nonce-protected form

**Dry-run preview (always shown):**
- Count of `restoration_pro` posts matching `services = 'sewage-cleanup'` (exact, not LIKE)
- Table: Post ID | Business Name | Status
- "Run Migration" submit button

**Post-run state:**
- Success notice: "X profiles migrated to Plumbers."
- If 0 matches: "Nothing to migrate — no sewage-cleanup-only restoration pros found."
- Idempotent: safe to run multiple times

**3. Migration Logic**

```
WP_Query:
  post_type    = restoration_pro
  post_status  = any
  meta_query:
    key     = services
    value   = sewage-cleanup
    compare = =          ← exact match only
  no_found_rows = true
  posts_per_page = -1

For each result:
  wp_update_post(['ID' => $id, 'post_type' => 'plumber'])
```

`wp_update_post` with only `post_type` changed preserves all meta, title, content, status, and dates.

---

## Data Flow

```
WP Admin clicks "Run Migration"
  → nonce verified
  → WP_Query finds restoration_pro posts where services = 'sewage-cleanup' exactly
  → foreach: wp_update_post sets post_type = 'plumber'
  → success notice with count
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
2. Verify dry-run count on staging looks correct
3. Run migration on staging — confirm plumber posts appear in WP Admin → Plumbers and disappear from Restoration Pros directory search
4. Upload to live `wp-content/mu-plugins/`
5. Run migration on live

---

## Verification (Manual)

- WP Admin → Plumbers: shows migrated posts with all meta intact
- WP Admin → Restoration Pros: sewage-cleanup-only profiles no longer listed
- Frontend zip/service search: no sewage-cleanup-only profiles in results
- Multi-service pros (e.g., `"water-damage,sewage-cleanup"`): unaffected, still in Restoration Pros

---

## Future Considerations

- The `plumber` CPT is a container only. A future "Plumber Directory" feature would add public visibility, its own search page, billing tiers, and lead routing.
- The migration tool can be removed from the plugin once both environments are migrated, or left in place (it's idempotent and harmless).
