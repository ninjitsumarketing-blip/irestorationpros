# iRP → FRP Redirect Runbook

**Status:** Ready for ops execution  
**Snapshot reference:** `docs/superpowers/plans/artifacts/irp-snapshot-2026-04-17.md`

irestorationpros.com (iRP) has 6 published pages and 0 posts. All 6 need permanent
301 redirects to findrestorationpros.com (FRP) equivalents before the iRP WordPress
instance is decommissioned.

---

## Part A — 301 Redirect Rules

Set up these redirects at the **SiteGround level** (Site Tools → Speed →
Redirects, or `.htaccess` on the iRP origin) so they fire before WordPress
processes the request. Cloudflare-level redirects (Rules → Redirects) are
equivalent if iRP is proxied.

| iRP URL (source) | FRP destination | Notes |
|---|---|---|
| `https://irestorationpros.com/water-damage-restoration-los-angeles-ca/` | `https://findrestorationpros.com/service-areas/los-angeles-ca/` | Closest FRP equivalent — city service area page |
| `https://irestorationpros.com/water-damage-restoration/` | `https://findrestorationpros.com/services/water-damage-restoration/` | Top-level service page (create if missing) |
| `https://irestorationpros.com/service-areas/` | `https://findrestorationpros.com/service-areas/` | Direct equivalent |
| `https://irestorationpros.com/how-it-works/` | `https://findrestorationpros.com/` | No FRP equivalent; send to homepage |
| `https://irestorationpros.com/contact/` | `https://findrestorationpros.com/contact/` | Direct equivalent (create if missing) |
| `https://irestorationpros.com/` | `https://findrestorationpros.com/` | Homepage → homepage |

**Catch-all:** any iRP URL not in the table above → `https://findrestorationpros.com/` (301).

### SiteGround `.htaccess` snippet (add to iRP site root)

```apache
# iRP → FRP permanent redirects (2026-04-17)
RewriteEngine On
RewriteRule ^water-damage-restoration-los-angeles-ca/?$ https://findrestorationpros.com/service-areas/los-angeles-ca/ [R=301,L]
RewriteRule ^water-damage-restoration/?$               https://findrestorationpros.com/services/water-damage-restoration/ [R=301,L]
RewriteRule ^service-areas/?$                          https://findrestorationpros.com/service-areas/ [R=301,L]
RewriteRule ^how-it-works/?$                           https://findrestorationpros.com/ [R=301,L]
RewriteRule ^contact/?$                                https://findrestorationpros.com/contact/ [R=301,L]
RewriteRule ^$                                         https://findrestorationpros.com/ [R=301,L]
# Catch-all for any other iRP paths
RewriteRule ^(.*)$                                     https://findrestorationpros.com/ [R=301,L]
```

**Verify with:**
```bash
curl -sI https://irestorationpros.com/water-damage-restoration-los-angeles-ca/ | grep -i location
# Expected: location: https://findrestorationpros.com/service-areas/los-angeles-ca/
```

---

## Part B — Google Search Console Change of Address

1. Log in to [Google Search Console](https://search.google.com/search-console).
2. Select the **irestorationpros.com** property.
3. Settings → Change of address → select `findrestorationpros.com` as the destination.
4. GSC will verify the 301 redirect from iRP root → FRP is live before accepting.
5. After acceptance, GSC will notify Google that all iRP signals should transfer to FRP.

**Prerequisite:** FRP must be verified in GSC before you can submit the change of address.

---

## Part C — GA4 Cross-Site Event Preservation

iRP and FRP share a GA4 property (`530375112`). No cross-site linking is required
since sessions on iRP will redirect immediately to FRP — GA4 will track the session
as a FRP session from the point the redirect lands.

**Action:** Confirm the GA4 data stream for `irestorationpros.com` continues to receive
traffic during the 7-day redirect check window (verify in GA4 → Reports → Realtime).
After 30 days with zero iRP traffic, the iRP data stream can be archived.

---

## Part D — Timeline

| Day | Action |
|-----|--------|
| **D+0** (now) | Deploy `.htaccess` redirects on iRP; verify with `curl` |
| **D+0** | Submit Search Console change-of-address request |
| **D+1** | Confirm GSC accepted change of address |
| **D+7** | Check Search Console coverage report — iRP pages should show "Moved permanently" |
| **D+7** | Check GA4 — confirm zero sessions on iRP streams |
| **D+30** | Remove iRP WordPress instance from SiteGround |
| **D+30** | Archive iRP data stream in GA4 |
| **D+30** | Cancel iRP domain auto-renew (or keep for domain protection — your call) |

**Soft-launch check (D+7):** If any iRP URL is still receiving direct traffic (not redirect)
— i.e., the `.htaccess` rules aren't catching all paths — add them to the table in Part A
and redeploy.

---

## Checklist

- [ ] `.htaccess` redirects deployed and verified with `curl` for all 6 slugs
- [ ] Catch-all rule tested (any unknown iRP path → FRP homepage)
- [ ] GSC change-of-address submitted
- [ ] GSC confirmed acceptance (check 24-48h later)
- [ ] D+7 redirect audit complete (no iRP pages receiving direct hits)
- [ ] D+30 iRP WP instance removed from SiteGround
- [ ] D+30 iRP GA4 data stream archived
