# iRP Inventory Snapshot — 2026-04-17

Captured before Task 0.2 removes `leadCapture` from config.
Source: `https://irestorationpros.com` WP REST API (`/wp/v2/pages`, `/wp/v2/posts`).

```json
{
  "pages": 6,
  "posts": 0,
  "pageSlugs": [
    "water-damage-restoration-los-angeles-ca",
    "how-it-works",
    "service-areas",
    "water-damage-restoration",
    "contact",
    "home"
  ],
  "postSlugs": []
}
```

**Notes:**
- No blog posts published on iRP — orchestrator had not yet run content generation.
- 6 pages are city/service landing pages + utility pages (contact, home, how-it-works, service-areas).
- `water-damage-restoration-los-angeles-ca` and `water-damage-restoration` are the only SEO-facing content pages.
- All 6 slugs will need 301 redirects to FRP equivalents per Task 0.3 (redirect map).
