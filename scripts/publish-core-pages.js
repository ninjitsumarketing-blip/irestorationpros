// ============================================================
// SCRIPT: Publish Core Pages
// Reads Stitch HTML files, wraps in Elementor HTML widget JSON,
// publishes 5 core pages to irestorationpros.com via WP REST API
//
// Usage: node scripts/publish-core-pages.js
// ============================================================

import { WordPressPublisher } from "./wp-publisher.js";
import fs from "fs";
import path from "path";
import { fileURLToPath } from "url";

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const HTML_DIR = path.join(__dirname, "../stitch-html");

// ----------------------------------------------------------
// Tailwind + Fonts preamble — prepended to every HTML widget
// so the design renders correctly inside Elementor
// ----------------------------------------------------------
const TAILWIND_PREAMBLE = `
<link href="https://fonts.googleapis.com/css2?family=Manrope:wght@700;800&family=Work+Sans:wght@400;500;600&display=swap" rel="stylesheet"/>
<link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200" rel="stylesheet"/>
<script src="https://cdn.tailwindcss.com?plugins=forms,container-queries"></script>
<script>
tailwind.config = {
  darkMode: "class",
  theme: {
    extend: {
      colors: {
        "secondary-container": "#fd6c22",
        "primary-container": "#0a2342",
        "surface-container-low": "#f6f2f8",
        "surface-container-lowest": "#ffffff",
        "surface-container-highest": "#e4e1e7",
        "surface-container-high": "#eae7ed",
        "surface-container": "#f0edf2",
        "surface-bright": "#fbf8fe",
        "surface-dim": "#dcd9de",
        "surface": "#fbf8fe",
        "on-surface": "#1b1b1f",
        "on-surface-variant": "#44474e",
        "on-primary-container": "#768baf",
        "on-secondary-container": "#591d00",
        "outline-variant": "#c4c6cf",
        "outline": "#74777e",
        "secondary": "#a43d00",
        "primary": "#000d22",
        "inverse-surface": "#303034",
        "inverse-on-surface": "#f3f0f5",
        "secondary-fixed": "#ffdbcd",
        "secondary-fixed-dim": "#ffb597",
        "on-secondary-fixed": "#360f00",
        "on-secondary": "#ffffff",
        "on-primary": "#ffffff",
        "on-primary-fixed": "#021c3a",
        "on-primary-fixed-variant": "#324768",
        "primary-fixed": "#d5e3ff",
        "primary-fixed-dim": "#b2c7ef",
        "inverse-primary": "#b2c7ef",
        "error": "#ba1a1a",
        "error-container": "#ffdad6",
        "on-error": "#ffffff",
        "on-error-container": "#93000a",
        "tertiary": "#000e1a",
        "tertiary-container": "#00253c",
        "tertiary-fixed": "#cce5ff",
        "tertiary-fixed-dim": "#9ecbf4",
        "on-tertiary": "#ffffff",
        "on-tertiary-container": "#628eb4",
        "on-tertiary-fixed": "#001e31",
        "on-tertiary-fixed-variant": "#174a6d",
        "surface-tint": "#4a5f81",
        "surface-variant": "#e4e1e7",
        "background": "#fbf8fe",
        "on-background": "#1b1b1f"
      },
      fontFamily: {
        "headline": ["Manrope"],
        "body": ["Work Sans"],
        "label": ["Work Sans"]
      },
      borderRadius: {
        "DEFAULT": "0.125rem",
        "lg": "0.25rem",
        "xl": "0.5rem",
        "full": "0.75rem"
      }
    }
  }
}
</script>
<style>
* { box-sizing: border-box; margin: 0; padding: 0; }
body, .irp-page { font-family: 'Work Sans', sans-serif; color: #1b1b1f; background: #fbf8fe; }
h1, h2, h3, h4, h5, h6 { font-family: 'Manrope', sans-serif; letter-spacing: -0.02em; }
.material-symbols-outlined {
  font-variation-settings: 'FILL' 0, 'wght' 400, 'GRAD' 0, 'opsz' 24;
  vertical-align: middle;
  line-height: 1;
}
/* Isolate from Elementor/WP styles */
.irp-page a { text-decoration: none; }
.irp-page img { max-width: 100%; height: auto; }
</style>
`;

// ----------------------------------------------------------
// Extract <body> content and wrap in isolation div
// ----------------------------------------------------------
function extractBodyContent(html) {
  const bodyMatch = html.match(/<body[^>]*>([\s\S]*?)<\/body>/i);
  const content = bodyMatch ? bodyMatch[1].trim() : html;
  // Wrap in isolation class to scope reset styles
  return `<div class="irp-page">${content}</div>`;
}

// ----------------------------------------------------------
// Build Elementor Flexbox Container JSON with HTML widget
// ----------------------------------------------------------
function buildElementorHtmlPage(html, pageId) {
  const bodyContent = extractBodyContent(html);
  const widgetHtml = TAILWIND_PREAMBLE + "\n" + bodyContent;

  return [
    {
      id: `c-${pageId}-outer`,
      elType: "container",
      settings: {
        flex_direction: "column",
        content_width: "full",
        padding: { unit: "px", top: "0", right: "0", bottom: "0", left: "0", isLinked: true },
        margin: { unit: "em", top: "0", right: "0", bottom: "0", left: "0", isLinked: true },
        overflow: "hidden",
      },
      elements: [
        {
          id: `w-${pageId}-html`,
          elType: "widget",
          widgetType: "html",
          settings: {
            html: widgetHtml,
          },
        },
      ],
    },
  ];
}

// ----------------------------------------------------------
// Core page definitions
// ----------------------------------------------------------
const CORE_PAGES = [
  {
    pageId: "homepage",
    htmlFile: "homepage.html",
    title: "iRestorationpros | Water Damage Restoration Los Angeles",
    slug: "home",
    metaTitle: "Water Damage Restoration Los Angeles | 60-Min Response | iRestorationpros",
    metaDescription:
      "Water damage in LA? IICRC-certified experts arrive in 60 minutes. Free estimates, insurance approved, 24/7 emergency service. Call (800) 555-0199.",
    focusKeyword: "water damage restoration Los Angeles",
    setAsFrontPage: true,
  },
  {
    pageId: "contact",
    htmlFile: "contact.html",
    title: "Get Help Now | Free Estimate | iRestorationpros",
    slug: "contact",
    metaTitle: "Contact iRestorationpros | 24/7 Water Damage Emergency Service",
    metaDescription:
      "Need emergency water damage help? Submit your request online or call (800) 555-0199. IICRC-certified technicians available 24/7 across Southern California.",
    focusKeyword: "water damage restoration contact",
  },
  {
    pageId: "services",
    htmlFile: "services-overview.html",
    title: "Water Damage Restoration Services | iRestorationpros",
    slug: "water-damage-restoration",
    metaTitle: "Water Damage Restoration Services | IICRC Certified | iRestorationpros",
    metaDescription:
      "Expert water damage restoration services in Southern California. Flood extraction, structural drying, mold prevention. Free estimates. 60-minute response.",
    focusKeyword: "water damage restoration services",
  },
  {
    pageId: "service-areas",
    htmlFile: "service-areas.html",
    title: "Service Areas | Southern California | iRestorationpros",
    slug: "service-areas",
    metaTitle: "Water Damage Restoration Service Areas | LA, OC, Riverside | iRestorationpros",
    metaDescription:
      "Serving Los Angeles, Orange County, and Riverside County. Water damage restoration experts available 24/7 across Southern California.",
    focusKeyword: "water damage restoration service areas Southern California",
  },
  {
    pageId: "how-it-works",
    htmlFile: "how-it-works.html",
    title: "How It Works | iRestorationpros",
    slug: "how-it-works",
    metaTitle: "How Water Damage Restoration Works | Fast 4-Step Process | iRestorationpros",
    metaDescription:
      "Learn how iRestorationpros connects you with certified restoration experts in 60 minutes. Simple process, free estimates, insurance approved.",
    focusKeyword: "how water damage restoration works",
  },
];

// ----------------------------------------------------------
// Set WordPress reading settings to use static front page
// ----------------------------------------------------------
async function setFrontPage(publisher, pageId) {
  try {
    // WP REST API /settings endpoint requires manage_options capability
    await publisher._request("/settings", "POST", {
      show_on_front: "page",
      page_on_front: pageId,
    });
    console.log(`   ✓ Set as static front page`);
  } catch (err) {
    // Not all WP installs expose /settings via REST — manual fallback
    console.warn(`   ⚠️  Auto front-page set failed: ${err.message}`);
    console.warn(`   → Manual step: WP Admin → Settings → Reading → set "Home" page as Your homepage`);
  }
}

// ----------------------------------------------------------
// Main
// ----------------------------------------------------------
async function publishCorePages() {
  const publisher = new WordPressPublisher("leadCapture");

  console.log("🌐 Publishing core pages to irestorationpros.com\n");
  console.log("=".repeat(55));

  let successCount = 0;
  let failCount = 0;

  for (const page of CORE_PAGES) {
    const htmlPath = path.join(HTML_DIR, page.htmlFile);

    if (!fs.existsSync(htmlPath)) {
      console.error(`❌ HTML file not found: ${page.htmlFile}`);
      failCount++;
      continue;
    }

    try {
      console.log(`\n📄 ${page.title}`);
      const html = fs.readFileSync(htmlPath, "utf-8");
      const elementorJson = buildElementorHtmlPage(html, page.pageId);

      const result = await publisher.createOrUpdatePage({
        title: page.title,
        slug: page.slug,
        content: "",
        metaTitle: page.metaTitle,
        metaDescription: page.metaDescription,
        focusKeyword: page.focusKeyword,
        elementorJson,
        status: "publish",
      });

      console.log(`   ✅ ${result.isNew ? "Created" : "Updated"} → ${result.url}`);
      successCount++;

      if (page.setAsFrontPage) {
        await setFrontPage(publisher, result.id);
      }

      // Avoid overwhelming the server
      await new Promise((r) => setTimeout(r, 1500));
    } catch (err) {
      console.error(`   ❌ Failed: ${err.message}`);
      failCount++;
    }
  }

  console.log("\n" + "=".repeat(55));
  console.log(`\n✅ Complete: ${successCount} published, ${failCount} failed`);

  if (successCount > 0) {
    console.log("\n📋 Next steps:");
    console.log("  1. Open each page in Elementor editor to verify design");
    console.log("  2. Replace CallRail placeholder (800) 555-0199 with your real tracking number");
    console.log("  3. Add Elementor native form webhook → Make.com (Task 11)");
    console.log("  4. Set Homepage as front page in WP Admin → Settings → Reading");
  }
}

publishCorePages().catch(console.error);
