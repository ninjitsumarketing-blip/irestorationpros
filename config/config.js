// ============================================================
// SECURE CONFIG — reads credentials from .env file
// NEVER put real values directly in this file
// ============================================================

import dotenv from "dotenv";
import { existsSync } from "fs";

dotenv.config();

function checkKeyRotation() {
  const rotationDate = process.env.KEY_ROTATION_DATE;
  if (!rotationDate) return;
  const daysSince = Math.floor(
    (Date.now() - new Date(rotationDate).getTime()) / (1000 * 60 * 60 * 24)
  );
  if (daysSince > 90) {
    console.warn(`\n⚠️  SECURITY WARNING: API keys are ${daysSince} days old. Please rotate them.\n`);
  }
}

function requireEnv(key) {
  const value = process.env[key];
  if (!value || value.startsWith("YOUR_")) {
    throw new Error(`Missing required environment variable: ${key}\nCopy .env.example to .env and fill in your values.`);
  }
  return value;
}

function checkServiceAccount() {
  const filePath = process.env.GOOGLE_SERVICE_ACCOUNT_PATH || "./config/google-service-account.json";
  if (!existsSync(filePath)) {
    console.warn(`⚠️  Google service account file not found: ${filePath}`);
  }
  return filePath;
}

checkKeyRotation();

export const CONFIG = {

  // ----------------------------------------------------------
  // WORDPRESS SITE — findrestorationpros.com
  // SiteGround → WP Admin → Users → Add New → Role: Editor
  // Generate Application Password under user profile
  // irestorationpros.com retired 2026-04-17; see archive/
  // ----------------------------------------------------------
  sites: {
    authority: {
      name: "findrestorationpros",
      url: process.env.WP_AUTHORITY_URL || "https://findrestorationpros.com",
      username: process.env.WP_AUTHORITY_USERNAME || "",
      appPassword: process.env.WP_AUTHORITY_PASSWORD || "",
    },
  },

  // ----------------------------------------------------------
  // ANTHROPIC — console.anthropic.com → API Keys
  // ----------------------------------------------------------
  anthropic: {
    apiKey: process.env.ANTHROPIC_API_KEY || "",
    model: "claude-sonnet-4-6",          // best for content generation
    contentModel: "claude-sonnet-4-6", // faster/cheaper for bulk pages
  },

  // ----------------------------------------------------------
  // GOOGLE APIs
  // console.cloud.google.com → Create Project → Enable APIs:
  //   - Google Search Console API
  //   - Google Analytics Data API
  // Create Service Account → Download JSON key
  // Then share GSC property with the service account email
  // ----------------------------------------------------------
  google: {
    serviceAccountPath: "./config/google-service-account.json",
    gscSiteUrls: {
      authority: "sc-domain:findrestorationpros.com",
    },
    ga4PropertyIds: {
      authority: process.env.GA4_PROPERTY_ID_AUTHORITY || "",
    }
  },

  // ----------------------------------------------------------
  // OPENAI — platform.openai.com → API Keys
  // Used for DALL-E bulk image generation
  // ----------------------------------------------------------
  openai: {
    apiKey: process.env.OPENAI_API_KEY || "",
    imageModel: "dall-e-3",
    imageSize: "1792x1024", // landscape for hero images
  },

  // ----------------------------------------------------------
  // CLOUDINARY — cloudinary.com → Dashboard
  // Free tier: 25GB storage, 25GB bandwidth/mo
  // ----------------------------------------------------------
  cloudinary: {
    cloudName: process.env.CLOUDINARY_CLOUD_NAME || "",
    apiKey: process.env.CLOUDINARY_API_KEY || "",
    apiSecret: process.env.CLOUDINARY_API_SECRET || "",
    folder: "restoration-sites",
  },

  // ----------------------------------------------------------
  // AIRTABLE — airtable.com → Account → API
  // Create a base called "Restoration Lead Gen System"
  // Tables: ContentQueue, Leads, Rankings, CityPages
  // ----------------------------------------------------------
  airtable: {
    apiKey: "YOUR_AIRTABLE_API_KEY",
    baseId: "YOUR_AIRTABLE_BASE_ID",   // format: appXXXXXXXXXXXXXX
    tables: {
      contentQueue: "ContentQueue",
      leads: "Leads",
      rankings: "Rankings",
      cityPages: "CityPages",
    }
  },

  // ----------------------------------------------------------
  // MAKE.COM — make.com (used for lead routing webhooks)
  // Create a scenario: Webhooks → Email/SMS to buyer
  // ----------------------------------------------------------
  make: {
    leadWebhookUrl: process.env.MAKE_WEBHOOK_URL || "",
    webhookSecret: process.env.MAKE_WEBHOOK_SECRET || "",
  },

  // ----------------------------------------------------------
  // CALLRAIL — callrail.com → Integrations → API
  // ----------------------------------------------------------
  callRail: {
    apiKey: process.env.CALLRAIL_API_KEY || "",
    accountId: process.env.CALLRAIL_ACCOUNT_ID || "",
    trackingNumber: process.env.CALLRAIL_TRACKING_NUMBER || "(855) 999-0000",
  },

  // ----------------------------------------------------------
  // DATAFORSEO — dataforseo.com → API access
  // Used for keyword research and rank tracking
  // ----------------------------------------------------------
  dataForSeo: {
    username: "YOUR_DATAFORSEO_EMAIL",
    password: "YOUR_DATAFORSEO_PASSWORD",
  },

  // ----------------------------------------------------------
  // YELP FUSION API — yelp.com/developers/v3/manage_app
  // Free tier: 5,000 requests/day
  // ----------------------------------------------------------
  yelp: {
    apiKey: process.env.YELP_API_KEY || "",
    searchUrl: "https://api.yelp.com/v3/businesses/search",
    reviewsUrl: "https://api.yelp.com/v3/businesses/{id}/reviews",
    rateLimit: 5, // requests per second to stay under free tier
  },

  // ----------------------------------------------------------
  // GOOGLE PLACES API (New) — separate from service account
  // Used for place search and worst-3 review lookup
  // ----------------------------------------------------------
  googlePlaces: {
    apiKey: process.env.GOOGLE_PLACES_API_KEY || "",
    searchUrl: "https://places.googleapis.com/v1/places:searchText",
    detailsUrl: "https://places.googleapis.com/v1/places/{id}",
  },

  // ----------------------------------------------------------
  // FINDRESTORATIONPROS.COM — directory site config
  // ----------------------------------------------------------
  frp: {
    makeWebhookUrl: process.env.FRP_MAKE_WEBHOOK_URL || "",
    seedCities: [
      "Los Angeles, CA", "San Diego, CA", "San Jose, CA", "San Francisco, CA",
      "Fresno, CA", "Sacramento, CA", "Long Beach, CA", "Oakland, CA",
      "Bakersfield, CA", "Anaheim, CA", "Santa Ana, CA", "Riverside, CA",
      "Stockton, CA", "Irvine, CA", "Chula Vista, CA", "Fremont, CA",
      "San Bernardino, CA", "Modesto, CA", "Fontana, CA", "Moreno Valley, CA",
      "Glendale, CA", "Huntington Beach, CA", "Santa Clarita, CA", "Garden Grove, CA",
      "Oceanside, CA", "Rancho Cucamonga, CA", "Santa Rosa, CA", "Ontario, CA",
      "Elk Grove, CA", "Corona, CA", "Salinas, CA", "Torrance, CA",
      "Pomona, CA", "Escondido, CA", "Sunnyvale, CA", "Pasadena, CA",
      "Hayward, CA", "Palmdale, CA", "Visalia, CA", "Simi Valley, CA",
      "Concord, CA", "Roseville, CA", "Thousand Oaks, CA", "Victorville, CA",
      "El Monte, CA", "Inglewood, CA", "Downey, CA", "Costa Mesa, CA", "Murrieta, CA",
    ],
    searchTerms: [
      "water damage restoration",
      "mold remediation",
      "fire damage restoration",
      "flood restoration",
    ],
  },

  // ----------------------------------------------------------
  // SYSTEM SETTINGS
  // ----------------------------------------------------------
  settings: {
    targetStates: ["California", "Texas", "Florida", "Arizona", "Nevada"],
    primaryServices: ["water-damage", "fire-damage", "mold-remediation", "storm-damage", "sewage-cleanup"],
    contentSchedule: {
      pagesPerWeek: 10,       // new city pages to generate
      blogPostsPerWeek: 3,    // new blog posts
      refreshOldContent: true // rewrite pages older than 90 days
    },
    leadRouting: {
      notifyBuyerEmail: true,
      notifyBuyerSms: true,
      dedupeWindowHours: 24,  // don't send same phone number twice in 24hrs
    }
  }
};
