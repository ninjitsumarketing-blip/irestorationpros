// ============================================================
// SCRIPT 3: City Page Generator
// Uses Claude to write unique location pages and publishes
// them automatically to irestorationpros.com
// ============================================================
// Usage: node scripts/city-page-generator.js
//        node scripts/city-page-generator.js --city "Pasadena" --state "CA" --service "water-damage"
// ============================================================

import Anthropic from "@anthropic-ai/sdk";
import { CONFIG } from "../config/config.js";
import { WordPressPublisher, buildElementorTemplate } from "./wp-publisher.js";
import { generateImage } from "./image-generator.js";
import fs from "fs";

const client = new Anthropic({ apiKey: CONFIG.anthropic.apiKey });

// ----------------------------------------------------------
// CITY DATA — local signals that make pages unique
// ----------------------------------------------------------
const CITY_DATA = {
  "Pasadena": {
    state: "CA", stateFullName: "California",
    county: "Los Angeles County",
    population: "138,699",
    landmarks: ["Rose Bowl", "Old Town Pasadena", "Caltech"],
    neighborhoods: ["Bungalow Heaven", "San Rafael Hills", "Hastings Ranch"],
    zipCodes: ["91101", "91103", "91104", "91105", "91106", "91107"],
    commonIssues: "aging pipes in historic homes, flash flooding in storm drains",
    avgRainfall: "20 inches annually",
  },
  "Glendale": {
    state: "CA", stateFullName: "California",
    county: "Los Angeles County",
    population: "196,543",
    landmarks: ["Brand Park", "Americana at Brand", "Forest Lawn"],
    neighborhoods: ["Adams Hill", "Montecito Park", "Chevy Chase Canyon"],
    zipCodes: ["91201", "91202", "91203", "91204", "91205", "91206"],
    commonIssues: "hillside drainage issues, older sewer infrastructure",
    avgRainfall: "18 inches annually",
  },
  // Add more cities here — or let the generator pull from an API
};

// Fallback city data builder for cities not in the list above
function buildGenericCityData(city, state) {
  return {
    state,
    stateFullName: state,
    county: `${city} County`,
    population: "varies",
    landmarks: [`Downtown ${city}`, `${city} City Hall`],
    neighborhoods: [`${city} Heights`, `North ${city}`, `South ${city}`],
    zipCodes: [],
    commonIssues: "pipe bursts, flooding, storm damage",
    avgRainfall: "varies by season",
  };
}

// ----------------------------------------------------------
// SERVICE CONFIG
// ----------------------------------------------------------
const SERVICE_CONFIG = {
  "water-damage": {
    label: "Water Damage Restoration",
    keywords: ["water damage restoration", "water damage repair", "flood cleanup", "water extraction"],
    urgencyLevel: "emergency",
    avgTicket: "$3,500–$8,000",
  },
  "fire-damage": {
    label: "Fire Damage Restoration",
    keywords: ["fire damage restoration", "fire damage repair", "smoke damage cleanup"],
    urgencyLevel: "emergency",
    avgTicket: "$10,000–$50,000",
  },
  "mold-remediation": {
    label: "Mold Remediation",
    keywords: ["mold remediation", "mold removal", "black mold removal"],
    urgencyLevel: "urgent",
    avgTicket: "$1,500–$6,000",
  },
  "storm-damage": {
    label: "Storm Damage Restoration",
    keywords: ["storm damage restoration", "storm cleanup", "wind damage repair"],
    urgencyLevel: "emergency",
    avgTicket: "$5,000–$25,000",
  },
  "sewage-cleanup": {
    label: "Sewage Cleanup",
    keywords: ["sewage cleanup", "sewage backup cleanup", "black water removal"],
    urgencyLevel: "emergency",
    avgTicket: "$2,000–$7,000",
  },
};

// ----------------------------------------------------------
// CLAUDE CONTENT GENERATOR
// ----------------------------------------------------------
async function generateCityPageContent(city, state, service) {
  const cityData = CITY_DATA[city] || buildGenericCityData(city, state);
  const serviceConfig = SERVICE_CONFIG[service];
  const focusKeyword = `${service.replace(/-/g, " ")} ${city} ${state}`;
  const localLandmark = cityData.landmarks[0];
  const neighborhood = cityData.neighborhoods[0];

  const prompt = `You are an expert SEO content writer for a local restoration lead generation website. Write a complete, unique city landing page for "${serviceConfig.label} in ${city}, ${state}".

PAGE REQUIREMENTS:
- Focus keyword: "${focusKeyword}"
- Secondary keywords: ${serviceConfig.keywords.map(k => `"${k} ${city}"`).join(", ")}
- Word count: 600–800 words
- Tone: urgent, trustworthy, local, professional
- Must feel genuinely LOCAL — not generic

LOCAL SIGNALS TO WEAVE IN NATURALLY:
- City: ${city}, ${state}
- County: ${cityData.county}
- Local landmarks: ${cityData.landmarks.join(", ")}
- Local neighborhoods: ${cityData.neighborhoods.join(", ")}
- Common local issues: ${cityData.commonIssues}

CONTENT STRUCTURE (use these exact HTML elements):
1. <h1> — Include city name and service, naturally
2. Opening paragraph — Local urgency hook, mention ${localLandmark} or ${city} context
3. <h2> — "Why ${city} Homeowners Trust Us"
4. 3 bullet points of trust signals (certified, fast response, insurance direct)
5. <h2> — "Common ${serviceConfig.label} Causes in ${city}"
6. Paragraph about local-specific causes (${cityData.commonIssues})
7. <h2> — "Our ${city} ${serviceConfig.label} Process"
8. 4-step numbered process list
9. <h2> — "Serving All ${city} Neighborhoods"
10. Mention specific neighborhoods: ${cityData.neighborhoods.join(", ")}
11. <h2> — "Get Immediate Help in ${city}"
12. Closing CTA paragraph — urgency, phone call to action

SEO REQUIREMENTS:
- Use focus keyword in H1, first paragraph, and one H2
- Use city name at least 8 times throughout
- Include naturally: "24/7", "free estimate", "insurance approved", "IICRC certified"
- Do NOT keyword stuff — it must read naturally

OUTPUT FORMAT: Return ONLY the HTML content (no markdown, no explanation). Start with <h1>.`;

  const response = await client.messages.create({
    model: CONFIG.anthropic.contentModel,
    max_tokens: 2000,
    messages: [{ role: "user", content: prompt }],
  });

  return response.content[0].text;
}

// ----------------------------------------------------------
// META GENERATOR
// ----------------------------------------------------------
async function generateMetaData(city, state, service, content) {
  const serviceConfig = SERVICE_CONFIG[service];

  const prompt = `Generate SEO meta data for a restoration city page. Return ONLY valid JSON, no explanation.

Page: ${serviceConfig.label} in ${city}, ${state}
Content preview: ${content.substring(0, 300)}

Return this exact JSON structure:
{
  "metaTitle": "60 chars max, include city + service + brand",
  "metaDescription": "155 chars max, include city + service + CTA",
  "focusKeyword": "primary keyword phrase",
  "slug": "url-slug-with-hyphens-no-city-abbreviations",
  "schemaType": "LocalBusiness"
}`;

  const response = await client.messages.create({
    model: CONFIG.anthropic.contentModel,
    max_tokens: 300,
    messages: [{ role: "user", content: prompt }],
  });

  try {
    const text = response.content[0].text.replace(/```json|```/g, "").trim();
    return JSON.parse(text);
  } catch {
    // Fallback meta
    return {
      metaTitle: `${serviceConfig.label} in ${city}, ${state} | iRestorationPros`,
      metaDescription: `Professional ${serviceConfig.label.toLowerCase()} in ${city}, ${state}. 24/7 emergency response. Free estimates. IICRC certified. Call now!`,
      focusKeyword: `${service.replace(/-/g, " ")} ${city} ${state}`,
      slug: `${service}-${city.toLowerCase().replace(/\s+/g, "-")}-${state.toLowerCase()}`,
      schemaType: "LocalBusiness",
    };
  }
}

// ----------------------------------------------------------
// SCHEMA MARKUP GENERATOR
// ----------------------------------------------------------
function generateLocalBusinessSchema(city, state, service, phone) {
  const serviceConfig = SERVICE_CONFIG[service];
  return {
    "@context": "https://schema.org",
    "@type": "LocalBusiness",
    "name": `iRestorationPros — ${serviceConfig.label} ${city}`,
    "description": `Professional ${serviceConfig.label.toLowerCase()} services in ${city}, ${state}. Available 24/7.`,
    "url": `https://irestorationpros.com/${service}-${city.toLowerCase().replace(/\s+/g, "-")}-${state.toLowerCase()}/`,
    "telephone": phone || "+1-800-000-0000",
    "areaServed": { "@type": "City", "name": city },
    "availableLanguage": "English",
    "openingHours": "Mo-Su 00:00-24:00",
    "priceRange": serviceConfig.avgTicket,
    "hasOfferCatalog": {
      "@type": "OfferCatalog",
      "name": serviceConfig.label,
      "itemListElement": serviceConfig.keywords.map(kw => ({
        "@type": "Offer",
        "itemOffered": { "@type": "Service", "name": kw },
      })),
    },
  };
}

// ----------------------------------------------------------
// MAIN PAGE GENERATOR
// ----------------------------------------------------------
async function generateAndPublishCityPage(city, state, service, options = {}) {
  const {
    phone = "(800) 000-0000",
    formShortcode = '[wpforms id="1"]',
    publishToSite = "leadCapture",
    generateHeroImage = true,
    dryRun = false,
  } = options;

  const serviceConfig = SERVICE_CONFIG[service];
  console.log(`\nGenerating: ${serviceConfig.label} in ${city}, ${state}`);

  // 1. Generate content
  console.log("  Writing content with Claude...");
  const htmlContent = await generateCityPageContent(city, state, service);

  // 2. Generate meta data
  console.log("  Generating meta data...");
  const meta = await generateMetaData(city, state, service, htmlContent);

  // 3. Generate schema markup
  const schema = generateLocalBusinessSchema(city, state, service, phone);
  const schemaScript = `\n<script type="application/ld+json">\n${JSON.stringify(schema, null, 2)}\n</script>`;

  // 4. Build full page content
  const fullContent = htmlContent + schemaScript;

  // 5. Generate hero image
  let featuredImageId = null;
  if (generateHeroImage && !dryRun) {
    try {
      console.log("  Generating hero image...");
      const imageId = await generateImage({
        prompt: `Professional restoration technicians working in ${city}, California. Water damage restoration scene. Clean, trustworthy, emergency response. Photorealistic, daytime, residential property.`,
        filename: `${meta.slug}-hero.jpg`,
        site: publishToSite,
      });
      featuredImageId = imageId;
    } catch (err) {
      console.warn(`  Image generation skipped: ${err.message}`);
    }
  }

  // 6. Build Elementor JSON template
  const elementorJson = buildElementorTemplate({
    heroTitle: `${serviceConfig.label} in ${city}, ${state}`,
    heroSubtitle: `24/7 Emergency Response · Free Estimates · IICRC Certified`,
    city,
    phone,
    formShortcode,
    services: CONFIG.settings.primaryServices,
  });

  const pageData = {
    type: "page",
    title: meta.metaTitle.replace(" | iRestorationPros", ""),
    slug: meta.slug,
    content: fullContent,
    metaTitle: meta.metaTitle,
    metaDescription: meta.metaDescription,
    focusKeyword: meta.focusKeyword,
    featuredImageId,
    elementorJson,
    status: dryRun ? "draft" : "publish",
  };

  if (dryRun) {
    console.log("\n  [DRY RUN] Page data preview:");
    console.log(`  Title: ${pageData.title}`);
    console.log(`  Slug: ${pageData.slug}`);
    console.log(`  Meta: ${pageData.metaTitle}`);
    console.log(`  Content length: ${htmlContent.length} chars`);
    return pageData;
  }

  // 7. Publish to WordPress
  console.log(`  Publishing to ${publishToSite}...`);
  const publisher = new WordPressPublisher(publishToSite);
  const result = await publisher.createOrUpdatePage(pageData);

  console.log(`  ✓ Live at: ${result.url}`);
  return { ...pageData, ...result };
}

// ----------------------------------------------------------
// BATCH GENERATOR
// Reads from a city list and generates pages in sequence
// ----------------------------------------------------------
export async function batchGenerateCityPages(cityList, options = {}) {
  const results = { success: [], failed: [] };
  const logFile = `./logs/batch-${new Date().toISOString().split("T")[0]}.json`;

  for (const item of cityList) {
    try {
      const result = await generateAndPublishCityPage(
        item.city,
        item.state,
        item.service,
        options
      );
      results.success.push(result);

      // Rate limit — wait 3 seconds between pages
      await new Promise(r => setTimeout(r, 3000));
    } catch (err) {
      console.error(`  Failed: ${item.city} — ${err.message}`);
      results.failed.push({ ...item, error: err.message });
    }

    // Save progress as we go
    fs.writeFileSync(logFile, JSON.stringify(results, null, 2));
  }

  console.log(`\n✓ Batch complete: ${results.success.length} success, ${results.failed.length} failed`);
  console.log(`  Log saved: ${logFile}`);
  return results;
}

// ----------------------------------------------------------
// SAMPLE CITY LIST — expand this as you grow
// ----------------------------------------------------------
export const PRIORITY_CITIES = [
  // LA area — highest search volume
  { city: "Pasadena", state: "CA", service: "water-damage" },
  { city: "Glendale", state: "CA", service: "water-damage" },
  { city: "Burbank", state: "CA", service: "water-damage" },
  { city: "Torrance", state: "CA", service: "water-damage" },
  { city: "Inglewood", state: "CA", service: "water-damage" },
  // Add mold for same cities
  { city: "Pasadena", state: "CA", service: "mold-remediation" },
  { city: "Glendale", state: "CA", service: "mold-remediation" },
  // Fire damage
  { city: "Pasadena", state: "CA", service: "fire-damage" },
  { city: "Burbank", state: "CA", service: "fire-damage" },
];

export { generateAndPublishCityPage };

// ----------------------------------------------------------
// CLI — run directly
// node scripts/city-page-generator.js
// node scripts/city-page-generator.js --dry-run
// ----------------------------------------------------------
if (process.argv[1].includes("city-page-generator")) {
  const args = process.argv.slice(2);
  const dryRun = args.includes("--dry-run");
  const cityArg = args.find((_, i) => args[i - 1] === "--city");
  const stateArg = args.find((_, i) => args[i - 1] === "--state");
  const serviceArg = args.find((_, i) => args[i - 1] === "--service");

  if (cityArg && stateArg && serviceArg) {
    // Single page mode
    await generateAndPublishCityPage(cityArg, stateArg, serviceArg, { dryRun });
  } else {
    // Batch mode — run first 3 as a test
    console.log(`Running batch generator (${dryRun ? "DRY RUN" : "LIVE"})...`);
    await batchGenerateCityPages(PRIORITY_CITIES.slice(0, 3), { dryRun });
  }
}
