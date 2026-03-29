// ============================================================
// SCRIPT 5: Weekly Orchestrator — the system "brain"
// Reads all data, asks Claude to plan, then executes
// Run via cron: 0 6 * * 1 (every Monday at 6am)
// ============================================================
// crontab setup:
//   crontab -e
//   0 6 * * 1 cd /path/to/restoration-system && node scripts/orchestrator.js >> logs/cron.log 2>&1
// ============================================================

import Anthropic from "@anthropic-ai/sdk";
import { CONFIG } from "../config/config.js";
import { generateIntelligenceReport } from "./gsc-reader.js";
import { batchGenerateCityPages, PRIORITY_CITIES } from "./city-page-generator.js";
import { WordPressPublisher } from "./wp-publisher.js";
import fs from "fs";

const client = new Anthropic({ apiKey: CONFIG.anthropic.apiKey });

// ----------------------------------------------------------
// STEP 1 — READ ALL DATA
// ----------------------------------------------------------
async function gatherAllData() {
  console.log("=== GATHERING INTELLIGENCE ===\n");
  const data = {};

  for (const site of ["authority", "leadCapture"]) {
    try {
      data[site] = await generateIntelligenceReport(site);
      console.log(`✓ ${site}: ${data[site].gsc.summary.totalClicks} clicks, ${data[site].gsc.keywordGaps.length} gaps`);
    } catch (err) {
      console.warn(`  Skipping ${site} GSC — ${err.message}`);
      data[site] = null;
    }
  }

  // Get existing page inventory
  const publisher = new WordPressPublisher("leadCapture");
  data.existingPages = await publisher.getAllPages();
  data.existingPosts = await publisher.getAllPosts();

  console.log(`✓ Existing pages: ${data.existingPages.length}`);
  console.log(`✓ Existing posts: ${data.existingPosts.length}`);

  return data;
}

// ----------------------------------------------------------
// STEP 2 — ASK CLAUDE TO PLAN THIS WEEK
// ----------------------------------------------------------
async function generateWeeklyPlan(data) {
  console.log("\n=== GENERATING WEEKLY PLAN ===\n");

  const existingPageSlugs = data.existingPages.map(p => p.slug).join(", ");

  const prompt = `You are the content strategy AI for a restoration lead generation network with two WordPress sites:
- findrestorationpros.com (authority/blog site)  
- irestorationpros.com (city landing pages, lead capture)

CURRENT PERFORMANCE DATA:
${JSON.stringify({
  leadCapture: data.leadCapture?.gsc?.summary || "no data",
  authority: data.authority?.gsc?.summary || "no data",
}, null, 2)}

QUICK WIN OPPORTUNITIES (pages ranking 5-15, need a push):
${JSON.stringify(data.leadCapture?.gsc?.quickWins?.slice(0, 10) || [], null, 2)}

KEYWORD GAPS (searches with no dedicated page):
${JSON.stringify(data.leadCapture?.gsc?.keywordGaps?.slice(0, 15) || [], null, 2)}

PAGES NEEDING CTA FIX (traffic but no conversions):
${JSON.stringify(data.leadCapture?.ga4?.nonConvertingPages?.slice(0, 5) || [], null, 2)}

EXISTING PAGES (don't recreate these):
${existingPageSlugs}

YOUR TASK: Create a specific content plan for this week. Return ONLY valid JSON, no explanation.

{
  "weekOf": "${new Date().toISOString().split("T")[0]}",
  "summary": "one sentence overview of this week's strategy",
  "newCityPages": [
    {
      "city": "City Name",
      "state": "CA",
      "service": "water-damage|fire-damage|mold-remediation|storm-damage|sewage-cleanup",
      "priority": "high|medium",
      "reason": "why this page first"
    }
  ],
  "blogPosts": [
    {
      "title": "Post title",
      "focusKeyword": "target keyword",
      "type": "how-to|cost-guide|comparison|local-guide",
      "site": "authority|leadCapture",
      "reason": "why this post"
    }
  ],
  "pageImprovements": [
    {
      "slug": "existing-page-slug",
      "issue": "low-ctr|no-conversions|thin-content",
      "action": "what specifically to fix"
    }
  ],
  "metaRewrites": [
    {
      "slug": "existing-page-slug",
      "currentCTR": 1.2,
      "newTitle": "improved title under 60 chars",
      "newDescription": "improved description under 155 chars"
    }
  ]
}

CONSTRAINTS:
- Max 8 new city pages this week
- Max 3 blog posts this week  
- Max 5 page improvements
- Prioritize pages most likely to generate leads quickly
- Focus on California cities first`;

  const response = await client.messages.create({
    model: CONFIG.anthropic.model,
    max_tokens: 3000,
    messages: [{ role: "user", content: prompt }],
  });

  try {
    const text = response.content[0].text.replace(/```json|```/g, "").trim();
    return JSON.parse(text);
  } catch (err) {
    console.error("Failed to parse weekly plan JSON:", err.message);
    return null;
  }
}

// ----------------------------------------------------------
// STEP 3 — GENERATE BLOG POSTS
// ----------------------------------------------------------
async function generateBlogPost(postSpec) {
  const { title, focusKeyword, type, site } = postSpec;

  const typeInstructions = {
    "how-to": "Step-by-step guide. Use numbered steps. Include safety warnings where relevant.",
    "cost-guide": "Cost breakdown with ranges. Include factors that affect price. Use tables or lists.",
    "comparison": "Compare options fairly. Include pros/cons. Help reader make a decision.",
    "local-guide": "Hyper-local content. Mention specific neighborhoods, local codes, seasonal factors.",
  };

  const prompt = `Write a complete SEO blog post for a restoration lead generation website.

Title: "${title}"
Focus keyword: "${focusKeyword}"
Post type: ${type}
Instructions: ${typeInstructions[type] || "Informative, helpful content"}
Word count: 900-1200 words
Site: ${site === "authority" ? "findrestorationpros.com (authoritative, expert tone)" : "irestorationpros.com (local, action-oriented)"}

Requirements:
- Use focus keyword in first 100 words, in one H2, and in conclusion
- Include 3-5 H2 subheadings
- Write at 8th grade reading level
- End with a clear CTA to call or get a free estimate
- Include relevant internal linking anchors like [LINK: water damage restoration] where relevant
- Do NOT include fake statistics or made-up studies

Return ONLY the HTML content starting with the first <h2> (the H1 title is set separately).`;

  const response = await client.messages.create({
    model: CONFIG.anthropic.contentModel,
    max_tokens: 2500,
    messages: [{ role: "user", content: prompt }],
  });

  const content = response.content[0].text;

  const publisher = new WordPressPublisher(site === "authority" ? "authority" : "leadCapture");
  const slug = title.toLowerCase().replace(/[^a-z0-9]+/g, "-").replace(/^-|-$/g, "");

  return publisher.createOrUpdatePost({
    title,
    slug,
    content,
    focusKeyword,
    metaTitle: `${title} | ${site === "authority" ? "FindRestorationPros" : "iRestorationPros"}`,
    metaDescription: `${title}. Expert advice from certified restoration professionals. Get a free estimate today.`,
    categories: [type === "cost-guide" ? "Cost Guides" : type === "how-to" ? "How To" : "Guides"],
    tags: [focusKeyword, "restoration", "water damage"],
    status: "publish",
  });
}

// ----------------------------------------------------------
// STEP 4 — IMPROVE EXISTING PAGES (CTA fixes + meta rewrites)
// ----------------------------------------------------------
async function improveExistingPage(improvement, publisher) {
  const { slug, issue, action } = improvement;

  const existingPages = await publisher._request(`/pages?slug=${slug}`);
  if (!existingPages.length) return;

  const page = existingPages[0];
  const currentContent = page.content.rendered;

  if (issue === "no-conversions") {
    // Add stronger CTA
    const ctaPrompt = `The following page has traffic but zero form conversions. Rewrite ONLY the last section (after the final H2) to include a much stronger, more urgent call to action. Keep everything else the same. Return only the improved closing section HTML.

Current content ending:
${currentContent.slice(-800)}

Action needed: ${action}`;

    const response = await client.messages.create({
      model: CONFIG.anthropic.contentModel,
      max_tokens: 500,
      messages: [{ role: "user", content: ctaPrompt }],
    });

    const newClosing = response.content[0].text;
    const updatedContent = currentContent.replace(
      currentContent.slice(-800),
      newClosing
    );

    await publisher._request(`/pages/${page.id}`, "POST", {
      content: updatedContent,
    });
    console.log(`  ✓ CTA improved: ${slug}`);
  }
}

// ----------------------------------------------------------
// STEP 5 — SEND WEEKLY SUMMARY REPORT
// ----------------------------------------------------------
async function sendWeeklySummary(plan, results) {
  const summary = {
    weekOf: plan.weekOf,
    strategy: plan.summary,
    pagesCreated: results.cityPages?.success?.length || 0,
    pagesFailed: results.cityPages?.failed?.length || 0,
    blogPostsCreated: results.blogPosts?.length || 0,
    improvementsMade: results.improvements?.length || 0,
    nextWeekFocus: plan.newCityPages?.slice(0, 3).map(p => `${p.city} ${p.service}`).join(", "),
  };

  console.log("\n=== WEEKLY SUMMARY ===");
  console.log(JSON.stringify(summary, null, 2));

  // Save to log
  const logFile = `./logs/weekly-summary-${plan.weekOf}.json`;
  fs.writeFileSync(logFile, JSON.stringify({ plan, results, summary }, null, 2));
  console.log(`\n✓ Summary saved: ${logFile}`);

  // Optionally send via Zapier webhook
  if (CONFIG.zapier.leadWebhookUrl) {
    try {
      await fetch(CONFIG.zapier.leadWebhookUrl + "-report", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify(summary),
      });
    } catch { /* non-critical */ }
  }
}

// ----------------------------------------------------------
// MAIN ORCHESTRATOR — runs everything in sequence
// ----------------------------------------------------------
async function runWeeklyOrchestrator() {
  const startTime = Date.now();
  console.log(`\n${"=".repeat(50)}`);
  console.log(`RESTORATION SYSTEM WEEKLY RUN`);
  console.log(`${new Date().toISOString()}`);
  console.log(`${"=".repeat(50)}\n`);

  const results = {};

  try {
    // 1. Gather all data
    const data = await gatherAllData();

    // 2. Ask Claude to plan
    const plan = await generateWeeklyPlan(data);
    if (!plan) throw new Error("Failed to generate weekly plan");

    console.log(`\n📋 Week of ${plan.weekOf}: ${plan.summary}`);
    console.log(`  City pages: ${plan.newCityPages?.length || 0}`);
    console.log(`  Blog posts: ${plan.blogPosts?.length || 0}`);
    console.log(`  Improvements: ${plan.pageImprovements?.length || 0}`);

    // 3. Generate new city pages
    if (plan.newCityPages?.length > 0) {
      console.log("\n=== GENERATING CITY PAGES ===");
      results.cityPages = await batchGenerateCityPages(plan.newCityPages, {
        generateHeroImage: false, // set true when you have image budget
      });
    }

    // 4. Generate blog posts
    if (plan.blogPosts?.length > 0) {
      console.log("\n=== GENERATING BLOG POSTS ===");
      results.blogPosts = [];
      for (const post of plan.blogPosts) {
        try {
          const result = await generateBlogPost(post);
          results.blogPosts.push(result);
          console.log(`  ✓ Published: ${post.title}`);
          await new Promise(r => setTimeout(r, 2000));
        } catch (err) {
          console.error(`  Failed: ${post.title} — ${err.message}`);
        }
      }
    }

    // 5. Improve existing pages
    if (plan.pageImprovements?.length > 0) {
      console.log("\n=== IMPROVING EXISTING PAGES ===");
      const publisher = new WordPressPublisher("leadCapture");
      results.improvements = [];
      for (const improvement of plan.pageImprovements) {
        try {
          await improveExistingPage(improvement, publisher);
          results.improvements.push(improvement);
        } catch (err) {
          console.error(`  Failed improvement: ${improvement.slug} — ${err.message}`);
        }
      }
    }

    // 6. Send summary
    await sendWeeklySummary(plan, results);

    const elapsed = ((Date.now() - startTime) / 1000 / 60).toFixed(1);
    console.log(`\n✓ Orchestrator complete in ${elapsed} minutes`);

  } catch (err) {
    console.error(`\n✗ Orchestrator failed: ${err.message}`);
    fs.writeFileSync(
      `./logs/error-${new Date().toISOString().split("T")[0]}.log`,
      err.stack
    );
    process.exit(1);
  }
}

// ----------------------------------------------------------
// RUN
// ----------------------------------------------------------
await runWeeklyOrchestrator();
