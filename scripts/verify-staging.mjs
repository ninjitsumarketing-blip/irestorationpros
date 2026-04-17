#!/usr/bin/env node
// FRP staging pre-flight check.
// Verifies each deliverable from Task 0.0 of the FRP launch plan.
// Run this AFTER ops finishes provisioning. Exit 0 iff all checks pass.
//
// Usage: node scripts/verify-staging.mjs
// Reads credentials from `.env.test` (NOT `.env`).

import dotenv from 'dotenv';
import { fileURLToPath } from 'node:url';
import { dirname, resolve } from 'node:path';

const __dirname = dirname(fileURLToPath(import.meta.url));
const envPath = resolve(__dirname, '..', '.env.test');
const loaded = dotenv.config({ path: envPath });

if (loaded.error) {
  console.error(`Could not read ${envPath}: ${loaded.error.message}`);
  console.error('Copy .env.test.example to .env.test and fill in values.');
  process.exit(2);
}

const {
  FRP_STAGING_URL,
  FRP_STAGING_USERNAME,
  FRP_STAGING_APP_PASSWORD,
  FRP_STAGING_SUBSCRIBER_USERNAME,
  FRP_STAGING_SUBSCRIBER_APP_PASSWORD,
  FRP_MAILHOG_URL,
  TURNSTILE_TEST_SECRET,
} = process.env;

const required = {
  FRP_STAGING_URL,
  FRP_STAGING_USERNAME,
  FRP_STAGING_APP_PASSWORD,
  FRP_STAGING_SUBSCRIBER_USERNAME,
  FRP_STAGING_SUBSCRIBER_APP_PASSWORD,
  FRP_MAILHOG_URL,
  TURNSTILE_TEST_SECRET,
};
const missing = Object.entries(required).filter(([, v]) => !v).map(([k]) => k);
if (missing.length) {
  console.error(`Missing required .env.test vars: ${missing.join(', ')}`);
  process.exit(2);
}

const results = [];
const pad = (label) => label.padEnd(40, '.');

function record(n, label, ok, detail) {
  results.push({ n, label, ok, detail });
  const mark = ok ? '✅' : '❌';
  console.log(`[${n}/7] ${pad(label)} ${mark} ${detail}`);
}

const basic = (user, pass) =>
  'Basic ' + Buffer.from(`${user}:${pass}`).toString('base64');

async function check1_wpReachable() {
  try {
    const res = await fetch(`${FRP_STAGING_URL}/wp-json/wp/v2/types`);
    if (res.status !== 200) {
      record(1, 'WP REST reachable', false, `status ${res.status}`);
      return null;
    }
    const body = await res.json();
    const count = body && typeof body === 'object' ? Object.keys(body).length : 0;
    record(1, 'WP REST reachable', true, `200 / ${count} types`);
    return res;
  } catch (err) {
    record(1, 'WP REST reachable', false, `connection error: ${err.message}`);
    return null;
  }
}

async function checkUserMe(n, label, user, pass, shouldBeAdmin) {
  try {
    const res = await fetch(
      `${FRP_STAGING_URL}/wp-json/wp/v2/users/me?context=edit`,
      { headers: { Authorization: basic(user, pass) } }
    );
    if (res.status !== 200) {
      record(n, label, false, `status ${res.status} — check creds`);
      return;
    }
    const body = await res.json();
    const caps = (body && body.capabilities) || {};
    const isAdmin = !!caps.manage_options || !!caps.administrator;
    if (shouldBeAdmin && !isAdmin) {
      record(n, label, false, `${user} lacks manage_options`);
    } else if (!shouldBeAdmin && isAdmin) {
      record(n, label, false, `${user} has manage_options — create a separate subscriber account`);
    } else if (shouldBeAdmin) {
      record(n, label, true, `${user} is administrator`);
    } else {
      record(n, label, true, `${user} is non-admin (ok)`);
    }
  } catch (err) {
    record(n, label, false, `connection error: ${err.message}`);
  }
}

async function check4_mailhog() {
  try {
    const res = await fetch(FRP_MAILHOG_URL);
    if (res.status === 200) {
      record(4, 'MailHog UI', true, `${FRP_MAILHOG_URL} returned 200`);
    } else {
      record(4, 'MailHog UI', false, `status ${res.status} at ${FRP_MAILHOG_URL}`);
    }
  } catch (err) {
    record(4, 'MailHog UI', false, `connection error: ${err.message}`);
  }
}

async function check5_stripeWebhook() {
  const url = `${FRP_STAGING_URL}/wp-json/frp/v1/stripe/webhook`;
  try {
    const res = await fetch(url, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: '{}',
    });
    // 404 = handler not built yet (Task 1.7). 400/401 = handler exists, rejected unsigned.
    if (res.status === 404) {
      record(5, 'Stripe webhook endpoint', true, `returned 404 (expected until Task 1.7)`);
    } else if (res.status === 400 || res.status === 401) {
      record(5, 'Stripe webhook endpoint', true, `returned ${res.status} (handler rejects unsigned — ok)`);
    } else if (res.status >= 500) {
      record(5, 'Stripe webhook endpoint', false, `server error ${res.status}`);
    } else {
      record(5, 'Stripe webhook endpoint', true, `returned ${res.status}`);
    }
  } catch (err) {
    record(5, 'Stripe webhook endpoint', false, `connection error: ${err.message}`);
  }
}

async function check6_cloudflare(wpRes) {
  if (!wpRes) {
    record(6, 'Cloudflare-in-front', false, `skipped — WP unreachable`);
    return;
  }
  const cfRay = wpRes.headers.get('cf-ray');
  const cfCache = wpRes.headers.get('cf-cache-status');
  if (cfRay) {
    record(6, 'Cloudflare-in-front', true, `cf-ray: ${cfRay.slice(0, 20)}`);
  } else if (cfCache) {
    record(6, 'Cloudflare-in-front', true, `cf-cache-status: ${cfCache}`);
  } else {
    record(6, 'Cloudflare-in-front', false, `no cf-ray/cf-cache-status — SiteGround may be direct`);
  }
}

async function check7_turnstile() {
  try {
    const form = new URLSearchParams({
      secret: TURNSTILE_TEST_SECRET,
      response: 'XXXX.DUMMY.TOKEN.XXXX',
    });
    const res = await fetch('https://challenges.cloudflare.com/turnstile/v0/siteverify', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: form,
    });
    const body = await res.json().catch(() => null);
    if (body && body.success === true) {
      record(7, 'Turnstile test keys', true, `siteverify success=true`);
    } else {
      const codes = body && body['error-codes'] ? body['error-codes'].join(',') : 'unknown';
      record(7, 'Turnstile test keys', false, `siteverify success=false (${codes})`);
    }
  } catch (err) {
    record(7, 'Turnstile test keys', false, `connection error: ${err.message}`);
  }
}

console.log('FRP staging pre-flight check (reading .env.test)\n');

const wpRes = await check1_wpReachable();
await checkUserMe(2, 'Admin app password', FRP_STAGING_USERNAME, FRP_STAGING_APP_PASSWORD, true);
await checkUserMe(3, 'Subscriber app password', FRP_STAGING_SUBSCRIBER_USERNAME, FRP_STAGING_SUBSCRIBER_APP_PASSWORD, false);
await check4_mailhog();
await check5_stripeWebhook();
await check6_cloudflare(wpRes);
await check7_turnstile();

const passed = results.filter((r) => r.ok).length;
const total = results.length;
console.log(`\n${passed} of ${total} checks passed.${passed < total ? ' Fix ❌ items before starting Task 1.1.' : ''}`);
process.exit(passed === total ? 0 : 1);
