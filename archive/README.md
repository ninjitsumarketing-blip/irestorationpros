# Archived scripts — iRestorationPros era

These scripts drove irestorationpros.com (iRP) content generation before the 2026-04-17
pivot to FRP-only. They reference `CONFIG.sites.leadCapture` (removed) and
`CONFIG.zapier` (never existed). Do not resurrect without porting to the FRP config.

- `orchestrator.js` — weekly cron brain (read GSC → plan with Claude → publish)
- `city-page-generator.js` — programmatic [service] × [city] page generator
- `publish-core-pages.js` — one-shot core pages publisher
- `gsc-reader.js` — Google Search Console intelligence report (reusable for FRP if re-pointed)
