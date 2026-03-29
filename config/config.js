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
  // YOUR TWO WORDPRESS SITES
  // SiteGround → WP Admin → Users → Add New → Role: Editor
  // Generate Application Password under user profile
  // ----------------------------------------------------------
  sites: {
    authority: {
      name: "findrestorationpros",
      url: "https://findrestorationpros.com",
      username: "YOUR_WP_USERNAME",
      appPassword: "YOUR_WP_APPLICATION_PASSWORD", // format: xxxx xxxx xxxx xxxx
    },
    leadCapture: {
      name: "irestorationpros",
      url: "https://irestorationpros.com",
      username: "YOUR_WP_USERNAME",
      appPassword: "YOUR_WP_APPLICATION_PASSWORD",
    }
  },

  // ----------------------------------------------------------
  // ANTHROPIC — console.anthropic.com → API Keys
  // ----------------------------------------------------------
  anthropic: {
    apiKey: "YOUR_ANTHROPIC_API_KEY",
    model: "claude-opus-4-5",          // best for content generation
    contentModel: "claude-sonnet-4-5", // faster/cheaper for bulk pages
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
      authority: "https://findrestorationpros.com/",
      leadCapture: "https://irestorationpros.com/",
    },
    ga4PropertyIds: {
      authority: "YOUR_GA4_PROPERTY_ID",     // format: 123456789
      leadCapture: "YOUR_GA4_PROPERTY_ID",
    }
  },

  // ----------------------------------------------------------
  // OPENAI — platform.openai.com → API Keys
  // Used for DALL-E bulk image generation
  // ----------------------------------------------------------
  openai: {
    apiKey: "YOUR_OPENAI_API_KEY",
    imageModel: "dall-e-3",
    imageSize: "1792x1024", // landscape for hero images
  },

  // ----------------------------------------------------------
  // CLOUDINARY — cloudinary.com → Dashboard
  // Free tier: 25GB storage, 25GB bandwidth/mo
  // ----------------------------------------------------------
  cloudinary: {
    cloudName: "YOUR_CLOUD_NAME",
    apiKey: "YOUR_CLOUDINARY_API_KEY",
    apiSecret: "YOUR_CLOUDINARY_API_SECRET",
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
  // ZAPIER — zapier.com (used for lead routing webhooks)
  // Create a Zap: Webhooks → Email/SMS to buyer
  // ----------------------------------------------------------
  zapier: {
    leadWebhookUrl: "YOUR_ZAPIER_WEBHOOK_URL",
  },

  // ----------------------------------------------------------
  // CALLRAIL — callrail.com → Integrations → API
  // ----------------------------------------------------------
  callRail: {
    apiKey: "YOUR_CALLRAIL_API_KEY",
    accountId: "YOUR_CALLRAIL_ACCOUNT_ID",
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
