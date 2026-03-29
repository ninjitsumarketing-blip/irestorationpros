// ============================================================
// SCRIPT 2: Google Search Console Data Reader
// Pulls ranking data and feeds it to Claude for decisions
// ============================================================
// Setup:
//   1. console.cloud.google.com → New Project
//   2. Enable "Google Search Console API"
//   3. Enable "Google Analytics Data API"
//   4. IAM → Service Accounts → Create → Download JSON
//   5. GSC → Settings → Users → Add (service account email, restricted)
// Usage: node scripts/gsc-reader.js
// ============================================================

import { CONFIG } from "../config/config.js";
import { google } from "googleapis";
import fs from "fs";

// ----------------------------------------------------------
// AUTH — shared Google auth from service account
// ----------------------------------------------------------
function getGoogleAuth() {
  const serviceAccount = JSON.parse(
    fs.readFileSync(CONFIG.google.serviceAccountPath, "utf8")
  );
  return new google.auth.GoogleAuth({
    credentials: serviceAccount,
    scopes: [
      "https://www.googleapis.com/auth/webmasters.readonly",
      "https://www.googleapis.com/auth/analytics.readonly",
    ],
  });
}

// ----------------------------------------------------------
// GSC DATA READER CLASS
// ----------------------------------------------------------
class GSCReader {
  constructor(siteKey) {
    this.siteUrl = CONFIG.google.gscSiteUrls[siteKey];
    this.auth = getGoogleAuth();
    this.searchconsole = google.searchconsole({ version: "v1", auth: this.auth });
  }

  // ----------------------------------------------------------
  // GET RANKING DATA — all queries for a date range
  // ----------------------------------------------------------
  async getRankingData(days = 28) {
    const endDate = new Date().toISOString().split("T")[0];
    const startDate = new Date(Date.now() - days * 86400000).toISOString().split("T")[0];

    const res = await this.searchconsole.searchanalytics.query({
      siteUrl: this.siteUrl,
      requestBody: {
        startDate,
        endDate,
        dimensions: ["query", "page"],
        rowLimit: 1000,
        dimensionFilterGroups: [],
      },
    });

    return (res.data.rows || []).map(row => ({
      query: row.keys[0],
      page: row.keys[1],
      clicks: row.clicks,
      impressions: row.impressions,
      ctr: (row.ctr * 100).toFixed(2),
      position: parseFloat(row.position.toFixed(1)),
    }));
  }

  // ----------------------------------------------------------
  // QUICK WIN FINDER
  // Pages ranking 5-15 — small push can get them to page 1
  // ----------------------------------------------------------
  async findQuickWins() {
    const data = await this.getRankingData(28);
    return data
      .filter(r => r.position >= 5 && r.position <= 15 && r.impressions > 50)
      .sort((a, b) => b.impressions - a.impressions)
      .slice(0, 20)
      .map(r => ({
        ...r,
        action: `Improve page: position ${r.position} with ${r.impressions} impressions`,
        priority: r.position <= 10 ? "high" : "medium",
      }));
  }

  // ----------------------------------------------------------
  // LOW CTR FINDER
  // Good impressions but poor click rate = title/meta needs work
  // ----------------------------------------------------------
  async findLowCTRPages() {
    const data = await this.getRankingData(28);
    return data
      .filter(r => r.impressions > 100 && parseFloat(r.ctr) < 2.0 && r.position <= 20)
      .sort((a, b) => b.impressions - a.impressions)
      .slice(0, 15)
      .map(r => ({
        ...r,
        action: `Rewrite title/meta — ${r.impressions} impressions, only ${r.ctr}% CTR`,
      }));
  }

  // ----------------------------------------------------------
  // KEYWORD GAP FINDER
  // Queries with impressions but no dedicated page yet
  // ----------------------------------------------------------
  async findKeywordGaps() {
    const data = await this.getRankingData(90);

    // Group by page to find pages with many keyword variations
    const pageMap = {};
    data.forEach(r => {
      if (!pageMap[r.page]) pageMap[r.page] = [];
      pageMap[r.page].push(r);
    });

    // Find queries that are driving impressions but ranking poorly
    const gaps = data
      .filter(r => r.impressions > 30 && r.position > 20)
      .map(r => ({
        keyword: r.query,
        impressions: r.impressions,
        currentPage: r.page,
        currentPosition: r.position,
        recommendation: this._getKeywordRecommendation(r.query),
      }));

    // Dedupe and sort
    const unique = [...new Map(gaps.map(g => [g.keyword, g])).values()];
    return unique.sort((a, b) => b.impressions - a.impressions).slice(0, 25);
  }

  _getKeywordRecommendation(keyword) {
    const kw = keyword.toLowerCase();
    if (kw.includes(" in ") || /\b(city|town|area|county)\b/.test(kw)) {
      return "Create dedicated city landing page";
    }
    if (kw.includes("cost") || kw.includes("price") || kw.includes("how much")) {
      return "Create cost guide blog post";
    }
    if (kw.includes("how to") || kw.includes("what to do")) {
      return "Create how-to guide blog post";
    }
    if (kw.includes("near me")) {
      return "Optimize local landing page with location signals";
    }
    return "Expand existing page or create new focused page";
  }

  // ----------------------------------------------------------
  // FULL WEEKLY REPORT
  // Everything Claude needs to plan next week's content
  // ----------------------------------------------------------
  async getWeeklyReport() {
    console.log(`Pulling GSC data for ${this.siteUrl}...`);

    const [rankingData, quickWins, lowCTR, keywordGaps] = await Promise.all([
      this.getRankingData(7),    // last 7 days for overview
      this.findQuickWins(),
      this.findLowCTRPages(),
      this.findKeywordGaps(),
    ]);

    const totalClicks = rankingData.reduce((s, r) => s + r.clicks, 0);
    const totalImpressions = rankingData.reduce((s, r) => s + r.impressions, 0);
    const avgPosition = rankingData.length
      ? (rankingData.reduce((s, r) => s + r.position, 0) / rankingData.length).toFixed(1)
      : 0;

    return {
      site: this.siteUrl,
      period: "Last 7 days",
      summary: {
        totalClicks,
        totalImpressions,
        avgPosition,
        totalKeywords: rankingData.length,
      },
      quickWins,       // pages to push from page 2 to page 1
      lowCTRPages,     // pages needing title/meta rewrites
      keywordGaps,     // new pages to create
      topQueries: rankingData
        .sort((a, b) => b.clicks - a.clicks)
        .slice(0, 10),
    };
  }
}

// ----------------------------------------------------------
// GA4 READER CLASS
// Conversion and behavior data per city page
// ----------------------------------------------------------
class GA4Reader {
  constructor(siteKey) {
    this.propertyId = CONFIG.google.ga4PropertyIds[siteKey];
    this.auth = getGoogleAuth();
    this.analytics = google.analyticsdata({ version: "v1beta", auth: this.auth });
  }

  async getPageConversions(days = 28) {
    const res = await this.analytics.properties.runReport({
      property: `properties/${this.propertyId}`,
      requestBody: {
        dateRanges: [{ startDate: `${days}daysAgo`, endDate: "today" }],
        dimensions: [{ name: "pagePath" }, { name: "pageTitle" }],
        metrics: [
          { name: "sessions" },
          { name: "bounceRate" },
          { name: "averageSessionDuration" },
          { name: "conversions" },       // requires goal setup in GA4
        ],
        orderBys: [{ metric: { metricName: "conversions" }, desc: true }],
        limit: 50,
      },
    });

    return (res.data.rows || []).map(row => ({
      path: row.dimensionValues[0].value,
      title: row.dimensionValues[1].value,
      sessions: parseInt(row.metricValues[0].value),
      bounceRate: (parseFloat(row.metricValues[1].value) * 100).toFixed(1),
      avgDuration: parseInt(row.metricValues[2].value),
      conversions: parseInt(row.metricValues[3].value),
      conversionRate: row.metricValues[0].value > 0
        ? ((parseInt(row.metricValues[3].value) / parseInt(row.metricValues[0].value)) * 100).toFixed(2)
        : "0.00",
    }));
  }

  // Find pages with traffic but zero conversions — need CTA fixes
  async findNonConvertingPages(minSessions = 50) {
    const pages = await this.getPageConversions(28);
    return pages
      .filter(p => p.sessions >= minSessions && p.conversions === 0)
      .sort((a, b) => b.sessions - a.sessions);
  }
}

// ----------------------------------------------------------
// COMBINED INTELLIGENCE REPORT
// This is what gets fed to Claude to plan content strategy
// ----------------------------------------------------------
export async function generateIntelligenceReport(siteKey) {
  const gsc = new GSCReader(siteKey);
  const ga4 = new GA4Reader(siteKey);

  const [weeklyReport, pageConversions, nonConverting] = await Promise.all([
    gsc.getWeeklyReport(),
    ga4.getPageConversions(28),
    ga4.findNonConvertingPages(50),
  ]);

  const report = {
    generatedAt: new Date().toISOString(),
    site: CONFIG.google.gscSiteUrls[siteKey],
    gsc: weeklyReport,
    ga4: {
      topConvertingPages: pageConversions.slice(0, 10),
      nonConvertingPages: nonConverting,
    },
    actionItems: buildActionItems(weeklyReport, nonConverting),
  };

  // Save report to file for Claude to read
  const filename = `./logs/report-${siteKey}-${new Date().toISOString().split("T")[0]}.json`;
  fs.writeFileSync(filename, JSON.stringify(report, null, 2));
  console.log(`\n✓ Intelligence report saved: ${filename}`);

  return report;
}

function buildActionItems(gscReport, nonConverting) {
  const items = [];

  // Priority 1: Quick wins (high impact, fast results)
  gscReport.quickWins.slice(0, 5).forEach(w => {
    items.push({
      priority: 1,
      type: "content_improvement",
      action: `Improve page ranking for "${w.query}" — currently position ${w.position}`,
      page: w.page,
      expectedImpact: "high",
    });
  });

  // Priority 2: Fix non-converting pages
  nonConverting.slice(0, 3).forEach(p => {
    items.push({
      priority: 2,
      type: "cta_fix",
      action: `Fix CTA on ${p.path} — ${p.sessions} sessions, 0 conversions`,
      page: p.path,
      expectedImpact: "high",
    });
  });

  // Priority 3: Rewrite low CTR pages
  gscReport.lowCTRPages.slice(0, 5).forEach(p => {
    items.push({
      priority: 3,
      type: "meta_rewrite",
      action: `Rewrite title/meta for ${p.page} — ${p.ctr}% CTR`,
      page: p.page,
      expectedImpact: "medium",
    });
  });

  // Priority 4: New pages to create
  gscReport.keywordGaps.slice(0, 5).forEach(g => {
    items.push({
      priority: 4,
      type: "new_page",
      action: `Create new page for "${g.keyword}" — ${g.impressions} impressions, not ranking`,
      keyword: g.keyword,
      recommendation: g.recommendation,
      expectedImpact: "medium",
    });
  });

  return items.sort((a, b) => a.priority - b.priority);
}

export { GSCReader, GA4Reader };

// ----------------------------------------------------------
// CLI TEST — run directly
// node scripts/gsc-reader.js
// ----------------------------------------------------------
if (process.argv[1].includes("gsc-reader")) {
  console.log("Generating intelligence report for both sites...\n");
  for (const site of ["authority", "leadCapture"]) {
    try {
      const report = await generateIntelligenceReport(site);
      console.log(`\n=== ${site.toUpperCase()} SITE ===`);
      console.log(`Clicks (7d): ${report.gsc.summary.totalClicks}`);
      console.log(`Impressions (7d): ${report.gsc.summary.totalImpressions}`);
      console.log(`Avg Position: ${report.gsc.summary.avgPosition}`);
      console.log(`Quick Wins: ${report.gsc.quickWins.length}`);
      console.log(`Keyword Gaps: ${report.gsc.keywordGaps.length}`);
      console.log(`Non-converting Pages: ${report.ga4.nonConvertingPages.length}`);
      console.log(`\nTop Action Items:`);
      report.actionItems.slice(0, 5).forEach((item, i) => {
        console.log(`  ${i + 1}. [${item.type}] ${item.action}`);
      });
    } catch (err) {
      console.error(`Failed for ${site}:`, err.message);
    }
  }
}
