// ============================================================
// SCRIPT 1: WordPress REST API Publisher
// Handles creating, updating, and managing pages/posts
// on both restoration sites via the WP REST API
// ============================================================
// Usage: node scripts/wp-publisher.js
// ============================================================

import { CONFIG } from "../config/config.js";
import { logger, validateImageUrl } from "./security.js";
const MAX_IMAGE_BYTES = 5 * 1024 * 1024;
import fs from "fs";
import path from "path";

// ----------------------------------------------------------
// CORE WP API CLASS
// ----------------------------------------------------------
class WordPressPublisher {
  constructor(siteKey) {
    if (siteKey !== 'authority') {
      throw new Error(`Unknown site "${siteKey}" — FRP is authority-only since iRP was retired 2026-04-17`);
    }
    const site = CONFIG.sites.authority;
    this.site = site;
    this.baseUrl = `${site.url}/wp-json/wp/v2`;
    this.authHeader = this._buildAuthHeader();
    this.siteName = siteKey;
  }

  _buildAuthHeader() {
    const credentials = `${this.site.username}:${this.site.appPassword}`;
    const encoded = Buffer.from(credentials).toString("base64");
    return `Basic ${encoded}`;
  }

  async _request(endpoint, method = "GET", body = null) {
    const options = {
      method,
      headers: {
        "Authorization": this.authHeader,
        "Content-Type": "application/json",
      },
    };
    if (body) options.body = JSON.stringify(body);

    const res = await fetch(`${this.baseUrl}${endpoint}`, options);
    if (!res.ok) {
      const err = await res.text();
      throw new Error(`WP API error ${res.status} on ${endpoint}: ${err}`);
    }
    return res.json();
  }

  // ----------------------------------------------------------
  // CATEGORIES — get or create
  // ----------------------------------------------------------
  async getOrCreateCategory(name, type = "categories") {
    const slug = name.toLowerCase().replace(/\s+/g, "-");
    const existing = await this._request(`/${type}?slug=${slug}`);
    if (existing.length > 0) return existing[0].id;

    const created = await this._request(`/${type}`, "POST", {
      name,
      slug,
    });
    console.log(`  Created category: ${name} (id: ${created.id})`);
    return created.id;
  }

  // ----------------------------------------------------------
  // MEDIA — upload image from URL or local path
  // ----------------------------------------------------------
  async uploadImage(imageUrlOrPath, altText, filename) {
    let imageBuffer;
    let mimeType = "image/jpeg";

    if (imageUrlOrPath.startsWith("http")) {
      const res = await fetch(imageUrlOrPath);
      imageBuffer = Buffer.from(await res.arrayBuffer());
      if (imageUrlOrPath.includes(".png")) mimeType = "image/png";
      if (imageUrlOrPath.includes(".webp")) mimeType = "image/webp";
    } else {
      imageBuffer = fs.readFileSync(imageUrlOrPath);
      if (imageUrlOrPath.endsWith(".png")) mimeType = "image/png";
    }

    const uploadRes = await fetch(`${this.baseUrl}/media`, {
      method: "POST",
      headers: {
        "Authorization": this.authHeader,
        "Content-Type": mimeType,
        "Content-Disposition": `attachment; filename="${filename}"`,
      },
      body: imageBuffer,
    });

    if (!uploadRes.ok) throw new Error(`Image upload failed: ${await uploadRes.text()}`);
    const media = await uploadRes.json();

    // Update alt text
    await this._request(`/media/${media.id}`, "POST", { alt_text: altText });

    console.log(`  Uploaded image: ${filename} (id: ${media.id})`);
    return media.id;
  }

  // ----------------------------------------------------------
  // PAGES — create or update a city landing page
  // ----------------------------------------------------------
  async createOrUpdatePage(pageData) {
    const {
      title,
      slug,
      content,
      metaTitle,
      metaDescription,
      featuredImageId,
      status = "publish",
      parentId = null,
      elementorJson = null,
    } = pageData;

    // Check if page already exists
    const existing = await this._request(`/pages?slug=${slug}`);
    const isUpdate = existing.length > 0;
    const pageId = isUpdate ? existing[0].id : null;

    const payload = {
      title,
      slug,
      content,
      status,
      ...(parentId && { parent: parentId }),
      ...(featuredImageId && { featured_media: featuredImageId }),
      meta: {
        // RankMath SEO fields
        rank_math_title: metaTitle || title,
        rank_math_description: metaDescription || "",
        rank_math_focus_keyword: pageData.focusKeyword || "",
        // Elementor data
        ...(elementorJson && { _elementor_data: JSON.stringify(elementorJson) }),
        ...(elementorJson && { _elementor_edit_mode: "builder" }),
        ...(elementorJson && { _elementor_template_type: "wp-page" }),
        ...(elementorJson && { _elementor_version: "3.18.0" }),
      },
    };

    let result;
    if (isUpdate) {
      result = await this._request(`/pages/${pageId}`, "POST", payload);
      console.log(`  Updated page: ${title} → ${this.site.url}/${slug}`);
    } else {
      result = await this._request("/pages", "POST", payload);
      console.log(`  Created page: ${title} → ${this.site.url}/${slug}`);
    }

    return { id: result.id, url: result.link, isNew: !isUpdate };
  }

  // ----------------------------------------------------------
  // POSTS — create or update a blog post
  // ----------------------------------------------------------
  async createOrUpdatePost(postData) {
    const {
      title,
      slug,
      content,
      excerpt,
      metaTitle,
      metaDescription,
      focusKeyword,
      categories = [],
      tags = [],
      featuredImageId,
      status = "publish",
    } = postData;

    const existing = await this._request(`/posts?slug=${slug}`);
    const isUpdate = existing.length > 0;
    const postId = isUpdate ? existing[0].id : null;

    // Resolve category IDs
    const categoryIds = await Promise.all(
      categories.map(cat => this.getOrCreateCategory(cat))
    );

    // Resolve tag IDs
    const tagIds = await Promise.all(
      tags.map(tag => this.getOrCreateCategory(tag, "tags"))
    );

    const payload = {
      title,
      slug,
      content,
      excerpt: excerpt || "",
      status,
      categories: categoryIds,
      tags: tagIds,
      ...(featuredImageId && { featured_media: featuredImageId }),
      meta: {
        rank_math_title: metaTitle || title,
        rank_math_description: metaDescription || "",
        rank_math_focus_keyword: focusKeyword || "",
      },
    };

    let result;
    if (isUpdate) {
      result = await this._request(`/posts/${postId}`, "POST", payload);
      console.log(`  Updated post: ${title}`);
    } else {
      result = await this._request("/posts", "POST", payload);
      console.log(`  Created post: ${title}`);
    }

    return { id: result.id, url: result.link, isNew: !isUpdate };
  }

  // ----------------------------------------------------------
  // BULK OPERATIONS — publish a batch from Airtable queue
  // ----------------------------------------------------------
  async publishFromQueue(items) {
    const results = [];
    for (const item of items) {
      try {
        console.log(`\nPublishing: ${item.title}`);
        let result;
        if (item.type === "page") {
          result = await this.createOrUpdatePage(item);
        } else {
          result = await this.createOrUpdatePost(item);
        }
        results.push({ ...item, ...result, status: "success" });
        // Small delay to avoid overwhelming the server
        await new Promise(r => setTimeout(r, 1000));
      } catch (err) {
        console.error(`  Failed: ${item.title} — ${err.message}`);
        results.push({ ...item, status: "failed", error: err.message });
      }
    }
    return results;
  }

  // ----------------------------------------------------------
  // AUDIT — get all pages/posts for a content refresh pass
  // ----------------------------------------------------------
  async getAllPages(olderThanDays = null) {
    let pages = [];
    let page = 1;
    while (true) {
      const batch = await this._request(`/pages?per_page=100&page=${page}&status=publish`);
      if (batch.length === 0) break;
      pages = [...pages, ...batch];
      page++;
    }

    if (olderThanDays) {
      const cutoff = new Date();
      cutoff.setDate(cutoff.getDate() - olderThanDays);
      pages = pages.filter(p => new Date(p.modified) < cutoff);
    }

    return pages.map(p => ({
      id: p.id,
      title: p.title.rendered,
      slug: p.slug,
      url: p.link,
      modified: p.modified,
      wordCount: p.content.rendered.replace(/<[^>]+>/g, "").split(/\s+/).length,
    }));
  }

  async getAllPosts(olderThanDays = null) {
    let posts = [];
    let page = 1;
    while (true) {
      const batch = await this._request(`/posts?per_page=100&page=${page}&status=publish`);
      if (batch.length === 0) break;
      posts = [...posts, ...batch];
      page++;
    }

    if (olderThanDays) {
      const cutoff = new Date();
      cutoff.setDate(cutoff.getDate() - olderThanDays);
      posts = posts.filter(p => new Date(p.modified) < cutoff);
    }

    return posts.map(p => ({
      id: p.id,
      title: p.title.rendered,
      slug: p.slug,
      url: p.link,
      modified: p.modified,
      wordCount: p.content.rendered.replace(/<[^>]+>/g, "").split(/\s+/).length,
    }));
  }
}

// ----------------------------------------------------------
// ELEMENTOR JSON TEMPLATE BUILDER
// Takes a page config and wraps it in Elementor structure
// ----------------------------------------------------------
export function buildElementorTemplate(pageConfig) {
  const { heroTitle, heroSubtitle, services, city, phone, formShortcode } = pageConfig;

  return [
    {
      id: "hero-section",
      elType: "section",
      settings: {
        background_color: "#0559ba",
        padding: { top: "80", bottom: "80", unit: "px" },
      },
      elements: [
        {
          id: "hero-col",
          elType: "column",
          settings: { _column_size: 100 },
          elements: [
            {
              id: "hero-heading",
              elType: "widget",
              widgetType: "heading",
              settings: {
                title: heroTitle,
                header_size: "h1",
                align: "center",
                title_color: "#ffffff",
                typography_font_size: { size: 48, unit: "px" },
              },
            },
            {
              id: "hero-sub",
              elType: "widget",
              widgetType: "text-editor",
              settings: {
                editor: `<p style="text-align:center;color:#ffffff;font-size:20px;">${heroSubtitle}</p>`,
              },
            },
            {
              id: "hero-phone",
              elType: "widget",
              widgetType: "button",
              settings: {
                text: `Call Now: ${phone}`,
                link: { url: `tel:${phone.replace(/\D/g, "")}` },
                align: "center",
                background_color: "#ffffff",
                button_text_color: "#0559ba",
                border_radius: { size: 50, unit: "px" },
                padding: { top: "16", bottom: "16", left: "40", right: "40", unit: "px" },
              },
            },
          ],
        },
      ],
    },
    {
      id: "form-section",
      elType: "section",
      settings: { padding: { top: "60", bottom: "60", unit: "px" } },
      elements: [
        {
          id: "form-col",
          elType: "column",
          settings: { _column_size: 100 },
          elements: [
            {
              id: "form-heading",
              elType: "widget",
              widgetType: "heading",
              settings: {
                title: `Get Free Restoration Help in ${city}`,
                header_size: "h2",
                align: "center",
              },
            },
            {
              id: "lead-form",
              elType: "widget",
              widgetType: "shortcode",
              settings: { shortcode: formShortcode },
            },
          ],
        },
      ],
    },
  ];
}

// ----------------------------------------------------------
// EXPORT
// ----------------------------------------------------------
export { WordPressPublisher };

// ----------------------------------------------------------
// CLI TEST — run directly to verify connection
// node scripts/wp-publisher.js
// ----------------------------------------------------------
import { fileURLToPath } from 'node:url';
if (process.argv[1] === fileURLToPath(import.meta.url)) {
  const publisher = new WordPressPublisher('authority');
  const pages = await publisher.getAllPages();
  console.log(`\n✓ Connected to ${CONFIG.sites.authority.url}`);
  console.log(`  Found ${pages.length} published pages`);
  console.log(`  Sample pages:`);
  pages.slice(0, 5).forEach(p => console.log(`    - ${p.title} (${p.wordCount} words)`));
}
