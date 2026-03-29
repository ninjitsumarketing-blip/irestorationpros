// ============================================================
// SCRIPT 4: Image Generator
// Generates images via DALL-E and uploads to Cloudinary
// then pushes to WordPress media library
// ============================================================

import { CONFIG } from "../config/config.js";
import { WordPressPublisher } from "./wp-publisher.js";
import { v2 as cloudinary } from "cloudinary";
import OpenAI from "openai";
import fs from "fs";
import path from "path";

// Configure Cloudinary
cloudinary.config({
  cloud_name: CONFIG.cloudinary.cloudName,
  api_key: CONFIG.cloudinary.apiKey,
  api_secret: CONFIG.cloudinary.apiSecret,
});

const openai = new OpenAI({ apiKey: CONFIG.openai.apiKey });

// ----------------------------------------------------------
// GENERATE IMAGE VIA DALL-E
// ----------------------------------------------------------
async function generateDALLEImage(prompt, filename) {
  const response = await openai.images.generate({
    model: CONFIG.openai.imageModel,
    prompt: prompt,
    size: CONFIG.openai.imageSize,
    quality: "standard",
    n: 1,
  });

  const imageUrl = response.data[0].url;
  console.log(`  DALL-E generated: ${filename}`);
  return imageUrl;
}

// ----------------------------------------------------------
// UPLOAD TO CLOUDINARY (CDN + auto-optimize)
// ----------------------------------------------------------
async function uploadToCloudinary(imageUrl, filename) {
  const publicId = `${CONFIG.cloudinary.folder}/${path.parse(filename).name}`;

  const result = await cloudinary.uploader.upload(imageUrl, {
    public_id: publicId,
    overwrite: true,
    resource_type: "image",
    transformation: [
      { quality: "auto:good" },
      { fetch_format: "auto" },   // auto-converts to WebP for supported browsers
      { width: 1200, crop: "limit" }, // cap max width
    ],
    tags: ["restoration", "lead-gen"],
  });

  console.log(`  Cloudinary upload: ${result.secure_url}`);
  return result.secure_url;
}

// ----------------------------------------------------------
// PUSH TO WORDPRESS MEDIA LIBRARY
// ----------------------------------------------------------
async function pushToWordPress(cloudinaryUrl, altText, filename, site) {
  const publisher = new WordPressPublisher(site);
  const mediaId = await publisher.uploadImage(cloudinaryUrl, altText, filename);
  return mediaId;
}

// ----------------------------------------------------------
// MAIN IMAGE GENERATOR
// Call this from city-page-generator.js
// ----------------------------------------------------------
export async function generateImage({ prompt, filename, altText, site = "leadCapture" }) {
  // 1. Generate with DALL-E
  const rawImageUrl = await generateDALLEImage(prompt, filename);

  // 2. Upload to Cloudinary for optimization + CDN
  const optimizedUrl = await uploadToCloudinary(rawImageUrl, filename);

  // 3. Push to WordPress media library
  const wpMediaId = await pushToWordPress(
    optimizedUrl,
    altText || filename.replace(/-/g, " ").replace(".jpg", ""),
    filename,
    site
  );

  return wpMediaId;
}

// ----------------------------------------------------------
// BULK IMAGE GENERATOR
// Generate a full set of images for a new site launch
// ----------------------------------------------------------
export async function generateSiteImages(site = "leadCapture") {
  const imageSets = [
    {
      filename: "hero-water-damage.jpg",
      prompt: "Professional water damage restoration team working in a flooded living room. Workers in protective gear using industrial extraction equipment. Bright, clean, trustworthy. Photorealistic.",
      altText: "Professional water damage restoration technicians",
    },
    {
      filename: "hero-fire-damage.jpg",
      prompt: "Fire damage restoration professionals assessing smoke damaged home interior. Safety equipment, professional tools, reassuring presence. Photorealistic.",
      altText: "Fire damage restoration experts",
    },
    {
      filename: "hero-mold-remediation.jpg",
      prompt: "Mold remediation specialist in full protective suit removing mold from basement wall. Professional, safe, certified. Photorealistic.",
      altText: "Certified mold remediation specialist",
    },
    {
      filename: "trust-badge-24-7.jpg",
      prompt: "Simple professional icon showing 24/7 emergency availability. Clock icon with phone. Clean white background. Minimal illustration style.",
      altText: "24/7 emergency restoration services",
    },
    {
      filename: "trust-badge-certified.jpg",
      prompt: "Professional certification badge icon. IICRC certified restoration. Clean, minimal, professional. Blue color scheme.",
      altText: "IICRC certified restoration company",
    },
    {
      filename: "before-after-water.jpg",
      prompt: "Split image showing before and after water damage restoration. Left side: flooded damaged room. Right side: clean restored room. Dramatic transformation.",
      altText: "Before and after water damage restoration",
    },
  ];

  const results = [];
  for (const imageSet of imageSets) {
    try {
      console.log(`\nGenerating: ${imageSet.filename}`);
      const wpMediaId = await generateImage({ ...imageSet, site });
      results.push({ filename: imageSet.filename, wpMediaId, status: "success" });
      await new Promise(r => setTimeout(r, 2000));
    } catch (err) {
      console.error(`  Failed: ${imageSet.filename} — ${err.message}`);
      results.push({ filename: imageSet.filename, status: "failed", error: err.message });
    }
  }

  return results;
}

// ----------------------------------------------------------
// MIDJOURNEY HELPER (manual workflow)
// Since MJ has no public API, this generates the prompts
// for you to run in Discord, then uploads the result
// ----------------------------------------------------------
export function generateMidjourneyPrompts(city, services) {
  return services.map(service => ({
    service,
    prompt: `professional ${service.replace(/-/g, " ")} restoration technicians working in ${city} California residential property, photorealistic, trustworthy, emergency response team, bright natural lighting, safety equipment --ar 16:9 --style raw --q 2`,
    instructions: [
      `1. Copy the prompt above into Midjourney in Discord`,
      `2. Select your favorite upscale (U1-U4)`,
      `3. Right-click → Save image`,
      `4. Upload here: ${CONFIG.cloudinary.cloudName}.cloudinary.com`,
      `5. Note the Cloudinary URL and add to city page`,
    ],
  }));
}

// ----------------------------------------------------------
// CLI TEST
// node scripts/image-generator.js
// ----------------------------------------------------------
if (process.argv[1].includes("image-generator")) {
  console.log("Testing image generation pipeline...\n");
  const result = await generateImage({
    prompt: "Professional water damage restoration team in a modern home. Photorealistic, trustworthy, emergency response.",
    filename: "test-restoration-hero.jpg",
    altText: "Water damage restoration professionals",
    site: "leadCapture",
  });
  console.log(`\n✓ Test image uploaded to WordPress. Media ID: ${result}`);
}
