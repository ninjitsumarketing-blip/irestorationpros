// ============================================================
// SCRIPT 3: City Page Generator (v2)
// Generates unique city pages using Stitch HTML templates +
// Claude-written city content. Publishes via WP REST API.
//
// Usage:
//   node scripts/city-page-generator.js
//   node scripts/city-page-generator.js --dry-run
//   node scripts/city-page-generator.js --city "Pasadena" --state "CA"
// ============================================================

import Anthropic from "@anthropic-ai/sdk";
import { CONFIG } from "../config/config.js";
import { WordPressPublisher } from "./wp-publisher.js";
import { generateImage } from "./image-generator.js";
import fs from "fs";
import path from "path";
import { fileURLToPath } from "url";

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const client = new Anthropic({ apiKey: CONFIG.anthropic.apiKey });
const HTML_DIR = path.join(__dirname, "../stitch-html");

// ----------------------------------------------------------
// Load Stitch HTML templates once at startup
// ----------------------------------------------------------
const TEMPLATES = {
  la: fs.readFileSync(path.join(HTML_DIR, "city-la.html"), "utf-8"),
  oc: fs.readFileSync(path.join(HTML_DIR, "city-oc.html"), "utf-8"),
  riverside: fs.readFileSync(path.join(HTML_DIR, "city-riverside.html"), "utf-8"),
};

// County → template mapping
const COUNTY_TEMPLATE = {
  "Los Angeles County": "la",
  "Ventura County": "la",   // geographically adjacent, same audience
  "Orange County": "oc",
  "Riverside County": "riverside",
};

// ----------------------------------------------------------
// Tailwind + Fonts preamble for Elementor HTML widget
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
        "secondary-container": "#fd6c22", "primary-container": "#0a2342",
        "surface-container-low": "#f6f2f8", "surface-container-lowest": "#ffffff",
        "surface-container-highest": "#e4e1e7", "surface-container-high": "#eae7ed",
        "surface-container": "#f0edf2", "surface-bright": "#fbf8fe",
        "surface-dim": "#dcd9de", "surface": "#fbf8fe",
        "on-surface": "#1b1b1f", "on-surface-variant": "#44474e",
        "on-primary-container": "#768baf", "on-secondary-container": "#591d00",
        "outline-variant": "#c4c6cf", "outline": "#74777e",
        "secondary": "#a43d00", "primary": "#000d22",
        "on-secondary": "#ffffff", "on-primary": "#ffffff",
        "surface-variant": "#e4e1e7", "background": "#fbf8fe",
        "on-background": "#1b1b1f", "inverse-surface": "#303034",
        "inverse-on-surface": "#f3f0f5", "inverse-primary": "#b2c7ef",
        "secondary-fixed": "#ffdbcd", "secondary-fixed-dim": "#ffb597",
        "primary-fixed": "#d5e3ff", "primary-fixed-dim": "#b2c7ef",
        "tertiary": "#000e1a", "tertiary-container": "#00253c",
        "on-tertiary": "#ffffff", "on-tertiary-container": "#628eb4",
        "error": "#ba1a1a", "error-container": "#ffdad6",
        "on-error": "#ffffff", "on-error-container": "#93000a",
        "surface-tint": "#4a5f81"
      },
      fontFamily: { "headline": ["Manrope"], "body": ["Work Sans"], "label": ["Work Sans"] },
      borderRadius: { "DEFAULT": "0.125rem", "lg": "0.25rem", "xl": "0.5rem", "full": "0.75rem" }
    }
  }
}
</script>
<style>
* { box-sizing: border-box; }
body, .irp-city { font-family: 'Work Sans', sans-serif; color: #1b1b1f; }
h1, h2, h3, h4 { font-family: 'Manrope', sans-serif; letter-spacing: -0.02em; }
.material-symbols-outlined { font-variation-settings: 'FILL' 0,'wght' 400,'GRAD' 0,'opsz' 24; vertical-align: middle; line-height: 1; }
.glass-header { background: rgba(255,255,255,0.8); backdrop-filter: blur(24px); }
.tight-tracking { letter-spacing: -0.02em; }
.irp-city a { text-decoration: none; }
.irp-city img { max-width: 100%; height: auto; }
</style>
`;

// ----------------------------------------------------------
// CITY DATA — 29 target cities
// ----------------------------------------------------------
const CITY_DATA = {
  // LA County (11)
  "Pasadena": {
    county: "Los Angeles County", state: "CA",
    zipCodes: ["91101", "91103", "91104", "91105", "91106", "91107"],
    landmarks: ["Rose Bowl", "Old Town Pasadena", "Caltech"],
    neighborhoods: ["Bungalow Heaven", "San Rafael Hills", "Hastings Ranch", "Old Pasadena", "Caltech Area"],
    commonIssues: "aging pipes in historic craftsman homes, flash flooding in storm drains near the Arroyo Seco",
  },
  "Burbank": {
    county: "Los Angeles County", state: "CA",
    zipCodes: ["91501", "91502", "91503", "91504", "91505", "91506"],
    landmarks: ["Warner Bros. Studio", "Burbank Airport", "Magnolia Park"],
    neighborhoods: ["Magnolia Park", "Media District", "Rancho Equestrian", "Downtown Burbank", "Burbank Hills"],
    commonIssues: "slab leaks in post-WWII homes, hillside drainage issues, studio lot water line failures",
  },
  "Glendale": {
    county: "Los Angeles County", state: "CA",
    zipCodes: ["91201", "91202", "91203", "91204", "91205", "91206"],
    landmarks: ["Brand Park", "Americana at Brand", "Forest Lawn Memorial Park"],
    neighborhoods: ["Adams Hill", "Montecito Park", "Chevy Chase Canyon", "Verdugo Hills", "Downtown Glendale"],
    commonIssues: "hillside drainage issues, older sewer infrastructure in historic areas, canyon water intrusion",
  },
  "Torrance": {
    county: "Los Angeles County", state: "CA",
    zipCodes: ["90501", "90502", "90503", "90504", "90505", "90506"],
    landmarks: ["Del Amo Fashion Center", "Torrance Beach", "Toyota USA Headquarters"],
    neighborhoods: ["Old Torrance", "Hollywood Riviera", "Walteria", "South Torrance", "Seaside Ranchos"],
    commonIssues: "coastal humidity accelerating mold growth, basement flooding, aging water mains near industrial areas",
  },
  "Downey": {
    county: "Los Angeles County", state: "CA",
    zipCodes: ["90240", "90241", "90242"],
    landmarks: ["Downey Civic Light Opera", "Columbia Memorial Space Center", "Stonewood Center"],
    neighborhoods: ["West Downey", "Central Downey", "North Downey", "South Downey", "Downey Landing"],
    commonIssues: "storm sewer overflow during rain events, aging cast iron pipes in postwar homes",
  },
  "Compton": {
    county: "Los Angeles County", state: "CA",
    zipCodes: ["90220", "90221", "90222", "90223"],
    landmarks: ["Compton Community College", "Compton Courthouse", "Enterprise Park"],
    neighborhoods: ["Sunny Cove", "Richland Farms", "East Compton", "North Compton", "West Compton"],
    commonIssues: "aging water infrastructure, storm runoff flooding in low-lying residential areas",
  },
  "Encino": {
    county: "Los Angeles County", state: "CA",
    zipCodes: ["91316", "91436"],
    landmarks: ["Balboa Park", "Ventura Boulevard shops", "Sepulveda Basin Recreation Area"],
    neighborhoods: ["Royal Hills", "Encino Hills", "Amestoy Estates", "Encino Village", "Lake Encino"],
    commonIssues: "hillside homes prone to water intrusion, aging plumbing in San Fernando Valley ranch homes",
  },
  "West Covina": {
    county: "Los Angeles County", state: "CA",
    zipCodes: ["91790", "91791", "91792", "91793"],
    landmarks: ["The Eastland Center", "West Covina City Hall", "Galster Wilderness Park"],
    neighborhoods: ["California Heights", "Shadow Hills", "Sunset Heights", "Eastside West Covina", "Woodside Village"],
    commonIssues: "clay soil expansion causing foundation leaks and slab cracks, aging municipal water lines",
  },
  "Beverly Hills": {
    county: "Los Angeles County", state: "CA",
    zipCodes: ["90210", "90211", "90212"],
    landmarks: ["Rodeo Drive", "Beverly Gardens Park", "Greystone Mansion"],
    neighborhoods: ["The Flats", "Trousdale Estates", "Cañon Drive", "North Beverly Hills", "Beverly Hills Flats"],
    commonIssues: "aging estate plumbing in classic properties, pool overflow and spa leaks, luxury restoration requiring discretion",
  },
  "West Hollywood": {
    county: "Los Angeles County", state: "CA",
    zipCodes: ["90046", "90048", "90069"],
    landmarks: ["Sunset Strip", "Pacific Design Center", "Plummer Park"],
    neighborhoods: ["Norma Triangle", "West Sunset Strip", "Santa Monica West", "Design District", "Laurel Canyon"],
    commonIssues: "shared-wall plumbing failures in dense apartment buildings, aging mid-century commercial plumbing",
  },
  "Long Beach": {
    county: "Los Angeles County", state: "CA",
    zipCodes: ["90801", "90802", "90803", "90804", "90805", "90806", "90807", "90808"],
    landmarks: ["Queen Mary", "Aquarium of the Pacific", "Shoreline Village"],
    neighborhoods: ["Belmont Shore", "Naples Island", "Bixby Knolls", "Signal Hill", "Alamitos Beach"],
    commonIssues: "coastal flooding near the port, saltwater corrosion in older pipe systems, storm surge damage",
  },
  // Ventura County — uses LA template (3 cities)
  "Topanga": {
    county: "Ventura County", state: "CA",
    zipCodes: ["90290"],
    landmarks: ["Topanga State Park", "Topanga Canyon Boulevard", "Old Topanga Canyon Road"],
    neighborhoods: ["Topanga Village", "Fernwood", "Pine Tree Circle", "Rodeo Grounds", "Greenleaf Canyon"],
    commonIssues: "hillside erosion causing water intrusion, aging rural septic and plumbing systems in canyon homes",
  },
  "Agoura Hills": {
    county: "Ventura County", state: "CA",
    zipCodes: ["91301", "91376"],
    landmarks: ["Agoura Hills Recreation Center", "Chumash Park", "Reyes Adobe Historical Site"],
    neighborhoods: ["Morrison Ranch Estates", "Medea Valley Estates", "Liberty Canyon", "Lake Lindero", "Chesebro"],
    commonIssues: "hillside drainage overflow, wildfire ash clogging gutters and storm drains causing flooding",
  },
  "Calabasas": {
    county: "Ventura County", state: "CA",
    zipCodes: ["91302", "91372"],
    landmarks: ["Leonis Adobe Museum", "Malibu Creek State Park", "Calabasas Commons"],
    neighborhoods: ["The Oaks", "Vista Hills", "Calabasas Park", "Hidden Hills border", "Old Town Calabasas"],
    commonIssues: "hillside water intrusion in gated communities, aging canyon infrastructure, luxury estate plumbing failures",
  },
  // Riverside County (7)
  "Riverside": {
    county: "Riverside County", state: "CA",
    zipCodes: ["92501", "92503", "92504", "92505", "92506", "92507", "92508"],
    landmarks: ["Mission Inn Hotel & Spa", "UC Riverside", "Riverside National Cemetery"],
    neighborhoods: ["Downtown Riverside", "Canyon Crest", "La Sierra", "Woodcrest", "Arlington"],
    commonIssues: "burst pipes from extreme heat cycles, slab leaks in Inland Empire homes, flash flooding in drainage channels",
  },
  "Corona": {
    county: "Riverside County", state: "CA",
    zipCodes: ["92879", "92880", "92881", "92882", "92883"],
    landmarks: ["Circle City Center", "Dos Lagos", "Tom's Farms"],
    neighborhoods: ["Chase Ranch", "Sierra del Oro", "South Corona", "Temescal Valley", "Green River"],
    commonIssues: "new development plumbing defects, hillside drainage issues, thermal expansion causing pipe cracks",
  },
  "Moreno Valley": {
    county: "Riverside County", state: "CA",
    zipCodes: ["92551", "92552", "92553", "92554", "92555", "92557"],
    landmarks: ["Moreno Valley Mall", "March Air Reserve Base", "Lake Perris"],
    neighborhoods: ["Towngate", "Canyon Hills", "Hidden Springs", "Sunnymead Ranch", "World Logistics Center area"],
    commonIssues: "flash flooding in desert terrain during monsoon season, storm runoff overwhelming drainage systems",
  },
  "Temecula": {
    county: "Riverside County", state: "CA",
    zipCodes: ["92589", "92590", "92591", "92592"],
    landmarks: ["Old Town Temecula", "Temecula Valley Wine Country", "Promenade Temecula"],
    neighborhoods: ["Wine Country estates", "Redhawk", "Paseo Del Sol", "Wolf Creek", "Paloma del Sol"],
    commonIssues: "irrigation system failures on wine country estates, hillside water intrusion in newer developments",
  },
  "Redlands": {
    county: "Riverside County", state: "CA",
    zipCodes: ["92373", "92374"],
    landmarks: ["Lincoln Memorial Shrine", "University of Redlands", "Smiley Park"],
    neighborhoods: ["Historic Downtown Redlands", "Mentone", "Crafton Hills", "University neighborhood", "North Redlands"],
    commonIssues: "aging Victorian-era plumbing in historic homes, citrus grove irrigation overflow, slab leaks",
  },
  "Chino": {
    county: "Riverside County", state: "CA",
    zipCodes: ["91708", "91710"],
    landmarks: ["Planes of Fame Air Museum", "Cal Aero Preserve", "Prado Regional Park"],
    neighborhoods: ["Airport Acres", "Pine Country", "Bridle Path", "College Park", "Preserve"],
    commonIssues: "older residential plumbing failures in dairy farm area homes, groundwater intrusion in basements",
  },
  "Chino Hills": {
    county: "Riverside County", state: "CA",
    zipCodes: ["91709"],
    landmarks: ["Chino Hills State Park", "The Shoppes at Chino Hills", "Boys Republic"],
    neighborhoods: ["Carbon Canyon", "English Springs", "Summit Ranch", "Butterfield Ranch", "Woodfield"],
    commonIssues: "hillside water intrusion from canyon runoff, expansive clay soil causing foundation and slab damage",
  },
  // Orange County (8)
  "Anaheim": {
    county: "Orange County", state: "CA",
    zipCodes: ["92801", "92802", "92804", "92805", "92806", "92807", "92808"],
    landmarks: ["Disneyland Resort", "Angel Stadium", "Honda Center"],
    neighborhoods: ["Anaheim Hills", "Downtown Anaheim", "Platinum Triangle", "West Anaheim", "Northeast Anaheim"],
    commonIssues: "aging 1950s-60s residential plumbing, commercial water damage near tourist district, slab leaks",
  },
  "Santa Ana": {
    county: "Orange County", state: "CA",
    zipCodes: ["92701", "92702", "92703", "92704", "92705", "92706", "92707"],
    landmarks: ["Bowers Museum", "Discovery Science Center", "MainPlace Mall"],
    neighborhoods: ["Floral Park", "Eastside", "South Coast Metro", "Downtown Santa Ana", "Northwest Santa Ana"],
    commonIssues: "older residential plumbing in historic neighborhoods, storm drain overflow, ground-level flooding",
  },
  "Huntington Beach": {
    county: "Orange County", state: "CA",
    zipCodes: ["92646", "92647", "92648", "92649"],
    landmarks: ["Huntington Beach Pier", "Bolsa Chica Ecological Reserve", "Pacific City"],
    neighborhoods: ["Downtown HB", "Seacliff", "Huntington Harbour", "Sunset Beach", "Newland Triangle"],
    commonIssues: "coastal storm flooding, saltwater corrosion accelerating pipe degradation, beach house moisture and mold",
  },
  "Irvine": {
    county: "Orange County", state: "CA",
    zipCodes: ["92602", "92603", "92604", "92606", "92612", "92614", "92617", "92618", "92620"],
    landmarks: ["UC Irvine", "Irvine Spectrum Center", "Orange County Great Park"],
    neighborhoods: ["Woodbridge", "Northwood", "Turtle Ridge", "Orchard Hills", "Great Park Neighborhoods"],
    commonIssues: "new construction plumbing defects, HOA community flooding from shared irrigation, slab leaks in newer homes",
  },
  "Newport Beach": {
    county: "Orange County", state: "CA",
    zipCodes: ["92657", "92660", "92661", "92662", "92663"],
    landmarks: ["Newport Harbor", "Fashion Island", "Balboa Island"],
    neighborhoods: ["Corona del Mar", "Balboa Island", "Lido Isle", "Newport Heights", "West Newport"],
    commonIssues: "seawater intrusion in waterfront properties, boat slip flooding, luxury estate plumbing systems",
  },
  "Laguna Beach": {
    county: "Orange County", state: "CA",
    zipCodes: ["92651", "92652", "92653"],
    landmarks: ["Laguna Beach Art Museum", "Heisler Park", "Main Beach"],
    neighborhoods: ["South Laguna", "Woods Cove", "Top of the World", "Arch Beach Heights", "Three Arch Bay"],
    commonIssues: "cliffside home water intrusion from ocean spray, coastal storm damage, luxury hillside property drainage",
  },
  "Laguna Niguel": {
    county: "Orange County", state: "CA",
    zipCodes: ["92677"],
    landmarks: ["Aliso and Wood Canyons Wilderness Park", "Laguna Niguel Regional Park", "Salt Creek Beach"],
    neighborhoods: ["Bear Brand Ranch", "Monarch Beach", "Kite Hill", "Pacific Island", "Crown Valley"],
    commonIssues: "HOA-managed community flooding, hillside drainage overflows, planned community irrigation failures",
  },
  "Newport Coast": {
    county: "Orange County", state: "CA",
    zipCodes: ["92657"],
    landmarks: ["Crystal Cove State Park", "Pelican Hill Golf Club", "Newport Ridge Community Park"],
    neighborhoods: ["Pelican Crest", "Pelican Hill", "Newport Ridge North", "Crystal Cove", "Newport Coast Village"],
    commonIssues: "ultra-luxury property water damage, coastal bluff drainage failures, high-end estate plumbing emergencies",
  },
};

// ----------------------------------------------------------
// PRIORITY CITIES — all 29 targets
// ----------------------------------------------------------
export const PRIORITY_CITIES = [
  // LA County
  { city: "Pasadena", state: "CA", service: "water-damage" },
  { city: "Burbank", state: "CA", service: "water-damage" },
  { city: "Glendale", state: "CA", service: "water-damage" },
  { city: "Torrance", state: "CA", service: "water-damage" },
  { city: "Downey", state: "CA", service: "water-damage" },
  { city: "Compton", state: "CA", service: "water-damage" },
  { city: "Encino", state: "CA", service: "water-damage" },
  { city: "West Covina", state: "CA", service: "water-damage" },
  { city: "Beverly Hills", state: "CA", service: "water-damage" },
  { city: "West Hollywood", state: "CA", service: "water-damage" },
  { city: "Long Beach", state: "CA", service: "water-damage" },
  // Ventura County (uses LA template)
  { city: "Topanga", state: "CA", service: "water-damage" },
  { city: "Agoura Hills", state: "CA", service: "water-damage" },
  { city: "Calabasas", state: "CA", service: "water-damage" },
  // Riverside County
  { city: "Riverside", state: "CA", service: "water-damage" },
  { city: "Corona", state: "CA", service: "water-damage" },
  { city: "Moreno Valley", state: "CA", service: "water-damage" },
  { city: "Temecula", state: "CA", service: "water-damage" },
  { city: "Redlands", state: "CA", service: "water-damage" },
  { city: "Chino", state: "CA", service: "water-damage" },
  { city: "Chino Hills", state: "CA", service: "water-damage" },
  // Orange County
  { city: "Anaheim", state: "CA", service: "water-damage" },
  { city: "Santa Ana", state: "CA", service: "water-damage" },
  { city: "Huntington Beach", state: "CA", service: "water-damage" },
  { city: "Irvine", state: "CA", service: "water-damage" },
  { city: "Newport Beach", state: "CA", service: "water-damage" },
  { city: "Laguna Beach", state: "CA", service: "water-damage" },
  { city: "Laguna Niguel", state: "CA", service: "water-damage" },
  { city: "Newport Coast", state: "CA", service: "water-damage" },
];

// ----------------------------------------------------------
// Claude content generator — returns structured JSON
// ----------------------------------------------------------
async function generateCityContent(city, state, cityData) {
  const prompt = `Generate city-specific content for a water damage restoration page in ${city}, ${state}.

LOCAL CONTEXT:
- County: ${cityData.county}
- Landmarks: ${cityData.landmarks.join(", ")}
- Neighborhoods: ${cityData.neighborhoods.join(", ")}
- Zip codes: ${cityData.zipCodes.join(", ")}
- Common local issues: ${cityData.commonIssues}

Return ONLY valid JSON with this exact structure (no markdown, no explanation):
{
  "h1": "Water Damage Restoration in ${city}, CA",
  "heroDescription": "2-3 sentence hero subheading mentioning specific ${city} neighborhoods/landmarks. Max 180 chars.",
  "editorialH2": "Expert Restoration for Every ${city} Neighborhood",
  "editorialP1": "Paragraph about local routing/dispatch knowledge specific to ${city}. Mention neighborhoods. 80-100 words.",
  "editorialP2": "Paragraph about insurance direct billing. Mention major carriers (State Farm, Allstate, Farmers). 70-90 words.",
  "editorialP3": "Paragraph about ${city}-specific climate/mold risk using local context from commonIssues. 70-90 words.",
  "ctaP": "Seconds count when water hits your floors. Call the local ${city} experts now for immediate emergency response.",
  "zipList": "${cityData.zipCodes.join(", ")}",
  "neighborhoodList": "${cityData.neighborhoods.join(", ")}",
  "faqs": [
    {"q": "How fast can you respond to water damage in ${city}?", "a": "60-word specific answer mentioning ${city} response time."},
    {"q": "Does insurance cover water damage restoration in ${city}?", "a": "60-word answer about insurance coverage."},
    {"q": "What causes most water damage in ${city} homes?", "a": "60-word answer using the local commonIssues context."},
    {"q": "Do you serve all neighborhoods in ${city}?", "a": "60-word answer listing the specific neighborhoods."}
  ]
}`;

  const response = await client.messages.create({
    model: CONFIG.anthropic.contentModel,
    max_tokens: 1500,
    messages: [{ role: "user", content: prompt }],
  });

  const text = response.content[0].text.replace(/```json|```/g, "").trim();
  try {
    return JSON.parse(text);
  } catch {
    // Fallback if JSON parsing fails
    return {
      h1: `Water Damage Restoration in ${city}, CA`,
      heroDescription: `Rapid emergency water extraction and structural drying for homes across ${city}. IICRC-certified experts arrive in 60 minutes.`,
      editorialH2: `Expert Restoration for Every ${city} Neighborhood`,
      editorialP1: `Our dispatchers know every street in ${city}. Whether you're near ${cityData.landmarks[0]} or in ${cityData.neighborhoods[0]}, we route the closest available technician directly to your door.`,
      editorialP2: `We work directly with all major insurance carriers—State Farm, Farmers, Allstate, and more. We document everything to ensure your claim is processed with $0 out-of-pocket for covered losses.`,
      editorialP3: `${city}'s ${cityData.commonIssues} create unique restoration challenges. Our technicians are trained specifically for the local conditions you face.`,
      ctaP: `Seconds count when water hits your floors. Call the local ${city} experts now for immediate emergency response.`,
      zipList: cityData.zipCodes.join(", "),
      neighborhoodList: cityData.neighborhoods.join(", "),
      faqs: [
        { q: `How fast can you respond to water damage in ${city}?`, a: `We guarantee on-site response within 60 minutes anywhere in ${city}. Our local dispatch team routes technicians from the nearest staging location to your address.` },
        { q: `Does insurance cover water damage restoration in ${city}?`, a: `Most homeowner insurance policies cover sudden water damage. We work directly with all major carriers serving ${city} and handle the claim documentation for you.` },
        { q: `What causes most water damage in ${city} homes?`, a: `In ${city}, ${cityData.commonIssues} are the most common causes. Our technicians are trained to handle all these scenarios efficiently.` },
        { q: `Do you serve all neighborhoods in ${city}?`, a: `Yes, we cover all of ${city} including ${cityData.neighborhoods.slice(0, 3).join(", ")}, and surrounding areas. Call us 24/7 for immediate dispatch.` },
      ],
    };
  }
}

// ----------------------------------------------------------
// Schema generators
// ----------------------------------------------------------
function buildLocalBusinessSchema(city, state, cityData, phone, slug) {
  return {
    "@context": "https://schema.org",
    "@type": "LocalBusiness",
    "name": `iRestorationpros — Water Damage Restoration ${city}`,
    "description": `Professional water damage restoration in ${city}, ${state}. 24/7 emergency response, IICRC certified, insurance approved.`,
    "url": `https://irestorationpros.com/${slug}/`,
    "telephone": phone.replace(/[^\d+]/g, ""),
    "areaServed": [
      { "@type": "City", "name": city, "containedInPlace": { "@type": "State", "name": state } },
      ...cityData.zipCodes.map(zip => ({ "@type": "PostalAddress", "postalCode": zip })),
    ],
    "openingHours": "Mo-Su 00:00-24:00",
    "priceRange": "$$$",
    "hasOfferCatalog": {
      "@type": "OfferCatalog",
      "name": "Water Damage Restoration Services",
      "itemListElement": [
        { "@type": "Offer", "itemOffered": { "@type": "Service", "name": "Emergency Water Extraction" } },
        { "@type": "Offer", "itemOffered": { "@type": "Service", "name": "Structural Drying" } },
        { "@type": "Offer", "itemOffered": { "@type": "Service", "name": "Mold Prevention" } },
        { "@type": "Offer", "itemOffered": { "@type": "Service", "name": "Insurance Claims Assistance" } },
      ],
    },
  };
}

function buildFaqSchema(faqs) {
  return {
    "@context": "https://schema.org",
    "@type": "FAQPage",
    "mainEntity": faqs.map(faq => ({
      "@type": "Question",
      "name": faq.q,
      "acceptedAnswer": { "@type": "Answer", "text": faq.a },
    })),
  };
}

// ----------------------------------------------------------
// Build city-specific SEO content section (injected after hero)
// ----------------------------------------------------------
function buildSeoContentSection(city, cityData, content) {
  const faqItems = content.faqs.map(faq => `
    <div class="border border-outline-variant/20 rounded-xl overflow-hidden">
      <button class="w-full text-left p-6 flex justify-between items-start gap-4 bg-surface-container-lowest hover:bg-surface-container-low transition-colors" onclick="this.nextElementSibling.classList.toggle('hidden'); this.querySelector('span.expand-icon').textContent = this.nextElementSibling.classList.contains('hidden') ? 'expand_more' : 'expand_less'">
        <span class="font-bold text-primary-container">${faq.q}</span>
        <span class="material-symbols-outlined flex-shrink-0 text-secondary-container expand-icon">expand_more</span>
      </button>
      <div class="hidden px-6 pb-6 text-on-surface-variant leading-relaxed">${faq.a}</div>
    </div>`).join("");

  return `
<!-- City SEO Content Section — auto-generated -->
<section class="py-20 px-6 bg-surface" id="city-content">
  <div class="max-w-7xl mx-auto">
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-12">

      <!-- Main SEO Content -->
      <div class="lg:col-span-2 space-y-8">
        <h2 class="font-headline text-3xl font-extrabold text-primary-container">${content.editorialH2}</h2>

        <div class="flex gap-4">
          <div class="flex-shrink-0 w-12 h-12 rounded-full bg-secondary-container/10 flex items-center justify-center">
            <span class="material-symbols-outlined text-secondary-container">location_on</span>
          </div>
          <div>
            <h3 class="font-bold text-xl mb-2 text-on-surface">Hyper-Local Knowledge</h3>
            <p class="text-on-surface-variant leading-relaxed">${content.editorialP1}</p>
          </div>
        </div>

        <div class="flex gap-4">
          <div class="flex-shrink-0 w-12 h-12 rounded-full bg-secondary-container/10 flex items-center justify-center">
            <span class="material-symbols-outlined text-secondary-container">account_balance</span>
          </div>
          <div>
            <h3 class="font-bold text-xl mb-2 text-on-surface">Direct Insurance Billing</h3>
            <p class="text-on-surface-variant leading-relaxed">${content.editorialP2}</p>
          </div>
        </div>

        <div class="flex gap-4">
          <div class="flex-shrink-0 w-12 h-12 rounded-full bg-secondary-container/10 flex items-center justify-center">
            <span class="material-symbols-outlined text-secondary-container">health_and_safety</span>
          </div>
          <div>
            <h3 class="font-bold text-xl mb-2 text-on-surface">Local Expertise</h3>
            <p class="text-on-surface-variant leading-relaxed">${content.editorialP3}</p>
          </div>
        </div>

        <!-- FAQ Section -->
        <div class="mt-12">
          <h2 class="font-headline text-2xl font-extrabold text-primary-container mb-6">Frequently Asked Questions</h2>
          <div class="space-y-3">${faqItems}</div>
        </div>
      </div>

      <!-- Sidebar: Service areas + zip codes -->
      <div class="space-y-6">
        <div class="bg-surface-container-low p-6 rounded-xl">
          <h3 class="font-headline font-bold text-primary-container mb-4">Neighborhoods We Serve</h3>
          <ul class="space-y-2">
            ${cityData.neighborhoods.map(n => `<li class="flex items-center gap-2 text-on-surface-variant text-sm">
              <span class="material-symbols-outlined text-secondary-container text-sm">check_circle</span>${n}
            </li>`).join("")}
          </ul>
        </div>
        <div class="bg-surface-container-low p-6 rounded-xl">
          <h3 class="font-headline font-bold text-primary-container mb-4">Zip Codes Served</h3>
          <p class="text-on-surface-variant text-sm leading-relaxed">${content.zipList}</p>
        </div>
        <div class="bg-primary-container p-6 rounded-xl text-white">
          <h3 class="font-headline font-bold mb-3">24/7 Emergency Line</h3>
          <p class="text-on-primary-container text-sm mb-4">Water damage doesn't wait. Neither do we.</p>
          <a href="tel:PHONE_PLACEHOLDER" class="block w-full bg-secondary-container text-white text-center py-3 rounded-full font-bold hover:bg-secondary transition-colors">PHONE_PLACEHOLDER</a>
        </div>
      </div>
    </div>
  </div>
</section>`;
}

// ----------------------------------------------------------
// Build JavaScript form submission to Make.com
// ----------------------------------------------------------
function buildFormScript(city, makeWebhookUrl) {
  if (!makeWebhookUrl) return "";
  return `
<script>
(function() {
  var forms = document.querySelectorAll('form');
  forms.forEach(function(form) {
    form.addEventListener('submit', function(e) {
      e.preventDefault();
      var inputs = form.querySelectorAll('input, select, textarea');
      var name = '', phone = '', damageType = 'Water Damage';
      inputs.forEach(function(el) {
        var type = el.type || el.tagName.toLowerCase();
        var val = el.value || '';
        if (type === 'text' || el.placeholder && el.placeholder.toLowerCase().includes('name')) name = val;
        if (type === 'tel') phone = val;
        if (type === 'select-one') damageType = val;
      });
      var payload = {
        first_name: name,
        phone: phone,
        city: '${city}',
        damage_type: damageType,
        source_url: window.location.href,
        timestamp: new Date().toISOString()
      };
      var btn = form.querySelector('button[type="submit"], button:not([type])');
      var origText = btn ? btn.textContent : '';
      if (btn) btn.textContent = 'Sending...';
      fetch('${makeWebhookUrl}', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload)
      }).then(function() {
        form.innerHTML = '<div style="text-align:center;padding:20px;"><p style="color:#fd6c22;font-weight:bold;font-size:1.1rem;margin-bottom:8px;">✓ Request Received!</p><p style="color:#44474e;">We\'ll call you within 15 minutes.</p></div>';
      }).catch(function() {
        if (btn) btn.textContent = origText;
        alert('Submission error. Please call us directly.');
      });
    });
  });
})();
</script>`;
}

// ----------------------------------------------------------
// Apply content to Stitch HTML template
// ----------------------------------------------------------
function applyContentToTemplate(templateHtml, city, state, cityData, content, phone, makeWebhookUrl) {
  const slug = `water-damage-restoration-${city.toLowerCase().replace(/\s+/g, "-")}-ca`;
  const lbSchema = buildLocalBusinessSchema(city, state, cityData, phone, slug);
  const faqSchema = buildFaqSchema(content.faqs);
  const seoSection = buildSeoContentSection(city, cityData, content)
    .replace(/PHONE_PLACEHOLDER/g, phone);
  const formScript = buildFormScript(city, makeWebhookUrl);

  let html = templateHtml;

  // Replace all phone number occurrences
  html = html.split("(800) 555-0199").join(phone);
  html = html.split("tel:8005550199").join(`tel:${phone.replace(/\D/g, "")}`);

  // Replace H1 content (handles all 3 template variations)
  html = html.replace(
    /(<h1[^>]*>[\s\S]*?)(Los Angeles Water Damage Restoration Experts|Premium Restoration Services in <span[^>]*>Orange County\.<\/span>|24\/7 Water Damage Cleanup in Riverside County)([\s\S]*?<\/h1>)/,
    `$1${content.h1}$3`
  );

  // Replace hero description paragraph
  html = html.replace(
    /Rapid emergency water extraction and structural drying for homes and businesses across Los Angeles\. We handle everything from Santa Monica flood surges to Silver Lake pipe bursts\./,
    content.heroDescription
  );
  html = html.replace(
    /Preserving the integrity of high-end properties in Irvine, Newport Beach, and Anaheim with immediate 24\/7 expert intervention\./,
    content.heroDescription
  );
  html = html.replace(
    /Expert flood extraction and restoration for plumbing failures and storm damage\. Serving Riverside, Moreno Valley, and Corona with immediate authority\./,
    content.heroDescription
  );

  // Replace badge text
  html = html.replace("60-Minute Response in LA", `60-Minute Response in ${city}`);
  html = html.replace("Elite OC Response Team", `Elite ${city} Response Team`);

  // Replace footer copyright
  html = html.replace(
    /© 2024 iRestorationpros\.[^<]*/,
    `© 2024 iRestorationpros. 24/7 Water Damage Restoration in ${city}, ${state}.`
  );

  // Inject SEO content section before the first CTA or footer
  // (before </main> to ensure it appears in the page)
  html = html.replace("</main>", `${seoSection}\n</main>`);

  // Inject schemas before </body>
  const schemas = `
<script type="application/ld+json">${JSON.stringify(lbSchema)}</script>
<script type="application/ld+json">${JSON.stringify(faqSchema)}</script>
${formScript}`;
  html = html.replace("</body>", `${schemas}\n</body>`);

  return html;
}

// ----------------------------------------------------------
// Build Elementor HTML widget page JSON
// ----------------------------------------------------------
function buildCityElementorPage(html, citySlug) {
  const bodyMatch = html.match(/<body[^>]*>([\s\S]*?)<\/body>/i);
  const bodyContent = bodyMatch ? `<div class="irp-city">${bodyMatch[1].trim()}</div>` : html;
  const widgetHtml = TAILWIND_PREAMBLE + "\n" + bodyContent;

  return [
    {
      id: `c-city-${citySlug}`,
      elType: "container",
      settings: {
        flex_direction: "column",
        content_width: "full",
        padding: { unit: "px", top: "0", right: "0", bottom: "0", left: "0", isLinked: true },
        margin: { unit: "em", top: "0", right: "0", bottom: "0", left: "0", isLinked: true },
      },
      elements: [
        {
          id: `w-city-${citySlug}`,
          elType: "widget",
          widgetType: "html",
          settings: { html: widgetHtml },
        },
      ],
    },
  ];
}

// ----------------------------------------------------------
// MAIN PAGE GENERATOR
// ----------------------------------------------------------
export async function generateAndPublishCityPage(city, state, service, options = {}) {
  const {
    phone = CONFIG.settings?.phone || "(800) 555-0199",
    publishToSite = "leadCapture",
    generateHeroImage = false, // off by default — use Stitch template images
    dryRun = false,
  } = options;

  const cityData = CITY_DATA[city];
  if (!cityData) {
    throw new Error(`No CITY_DATA found for "${city}". Add it to CITY_DATA first.`);
  }

  console.log(`\n📍 Generating: Water Damage Restoration in ${city}, ${state}`);

  // 1. Generate city-specific content via Claude
  console.log("  ✍️  Writing content with Claude...");
  const content = await generateCityContent(city, state, cityData);

  // 2. Select and apply template
  const templateKey = COUNTY_TEMPLATE[cityData.county] || "la";
  const makeWebhookUrl = CONFIG.make?.leadWebhookUrl || "";
  const html = applyContentToTemplate(
    TEMPLATES[templateKey], city, state, cityData, content, phone, makeWebhookUrl
  );

  // 3. Build Elementor JSON
  const citySlug = city.toLowerCase().replace(/\s+/g, "-");
  const pageSlug = `water-damage-restoration-${citySlug}-ca`;
  const elementorJson = buildCityElementorPage(html, citySlug);

  // 4. SEO meta
  const metaTitle = `Water Damage Restoration ${city}, CA | 60-Min Response | iRestorationpros`;
  const metaDescription = `Water damage in ${city}? IICRC-certified experts respond in 60 minutes. Free estimates, insurance approved, 24/7 emergency service. Call ${phone}.`;

  const pageData = {
    type: "page",
    title: content.h1,
    slug: pageSlug,
    content: "",
    metaTitle,
    metaDescription,
    focusKeyword: `water damage restoration ${city} CA`,
    elementorJson,
    status: dryRun ? "draft" : "publish",
  };

  if (dryRun) {
    console.log("  [DRY RUN] Preview:");
    console.log(`  Title: ${pageData.title}`);
    console.log(`  Slug: ${pageData.slug}`);
    console.log(`  Template: ${templateKey} | Content length: ${html.length} chars`);
    return pageData;
  }

  // 5. Publish to WordPress
  console.log(`  🌐 Publishing to ${publishToSite}...`);
  const publisher = new WordPressPublisher(publishToSite);
  const result = await publisher.createOrUpdatePage(pageData);

  console.log(`  ✅ Live: ${result.url}`);
  return { ...pageData, ...result };
}

// ----------------------------------------------------------
// BATCH GENERATOR
// ----------------------------------------------------------
export async function batchGenerateCityPages(cityList, options = {}) {
  const results = { success: [], failed: [] };
  const logFile = `./logs/batch-${new Date().toISOString().split("T")[0]}.json`;

  // Ensure logs directory exists
  if (!fs.existsSync("./logs")) fs.mkdirSync("./logs");

  for (const item of cityList) {
    try {
      const result = await generateAndPublishCityPage(item.city, item.state, item.service, options);
      results.success.push(result);
      await new Promise(r => setTimeout(r, 3000)); // rate limit
    } catch (err) {
      console.error(`  ❌ Failed: ${item.city} — ${err.message}`);
      results.failed.push({ ...item, error: err.message });
    }
    fs.writeFileSync(logFile, JSON.stringify(results, null, 2));
  }

  console.log(`\n✅ Batch complete: ${results.success.length} success, ${results.failed.length} failed`);
  console.log(`  Log: ${logFile}`);
  return results;
}

// ----------------------------------------------------------
// CLI
// ----------------------------------------------------------
if (process.argv[1].includes("city-page-generator")) {
  const args = process.argv.slice(2);
  const dryRun = args.includes("--dry-run");
  const cityArg = args.find((_, i) => args[i - 1] === "--city");
  const stateArg = args.find((_, i) => args[i - 1] === "--state") || "CA";
  const serviceArg = args.find((_, i) => args[i - 1] === "--service") || "water-damage";

  if (cityArg) {
    await generateAndPublishCityPage(cityArg, stateArg, serviceArg, { dryRun });
  } else {
    console.log(`Running batch (${dryRun ? "DRY RUN" : "LIVE"}) — first 3 cities...`);
    await batchGenerateCityPages(PRIORITY_CITIES.slice(0, 3), { dryRun });
  }
}
