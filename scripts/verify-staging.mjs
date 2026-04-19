#!/usr/bin/env node
// FRP staging pre-flight check.
// Verifies each deliverable from Task 0.0 of the FRP launch plan.
// Run this AFTER ops finishes provisioning. Exit 0 iff all checks pass.
//
// Usage: node scripts/verify-staging.mjs
// Reads credentials from `.env.test` (NOT `.env`).
//
// Note: this script reads only the 7 env vars it actively probes. The Stripe
// triad (STRIPE_TEST_*) and Turnstile sitekey (TURNSTILE_TEST_SITEKEY) are
// declared in .env.test.example for downstream tasks (1.7 Stripe webhook,
// Cloudflare widget rendering) — validated there, not here.

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

// Placeholder sentinel: .env.test.example ships the subscriber creds as
// "xxxx xxxx xxxx xxxx xxxx xxxx". Treat placeholder values as "deferred
// — configure before Task 1.3 auth-gating tests". The subscriber slot is
// the only slot this applies to; everything else is required.
const isPlaceholder = (v) => !v || /^x{4}(\s+x{4})*$/i.test(String(v).trim());
const SUBSCRIBER_DEFERRED =
  isPlaceholder(FRP_STAGING_SUBSCRIBER_USERNAME) ||
  isPlaceholder(FRP_STAGING_SUBSCRIBER_APP_PASSWORD);

const required = {
  FRP_STAGING_URL,
  FRP_STAGING_USERNAME,
  FRP_STAGING_APP_PASSWORD,
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

// status: 'pass' | 'fail' | 'skip'. Skipped checks don't fail the script but
// are reported so the operator knows coverage is incomplete.
function record(n, label, status, detail) {
  results.push({ n, label, status, detail });
  const mark = status === 'pass' ? '✅' : status === 'skip' ? '⏭️ ' : '❌';
  console.log(`[${n}/7] ${pad(label)} ${mark} ${detail}`);
}

// WP Application Passwords are displayed as six space-separated 4-char groups
// (e.g. "aBcD eFgH iJkL mNoP qRsT uVwX"). Operators paste them verbatim.
// Stripping whitespace here prevents "status 401 — check creds" false negatives
// when the creds are actually correct but contain display-format spaces.
const basic = (user, pass) =>
  'Basic ' + Buffer.from(`${user}:${String(pass).replace(/\s+/g, '')}`).toString('base64');

// Per-check fetch timeout. Staging DNS/origin hiccups are common during
// provisioning; don't wait Node's default ~300s before reporting ❌.
const FETCH_TIMEOUT_MS = 15_000;
function timed(init = {}) {
  const ac = new AbortController();
  const timer = setTimeout(() => ac.abort(), FETCH_TIMEOUT_MS);
  return {
    init: { ...init, signal: ac.signal },
    done: () => clearTimeout(timer),
  };
}
async function tfetch(url, init = {}) {
  const t = timed(init);
  try {
    return await fetch(url, t.init);
  } finally {
    t.done();
  }
}

async function check1_wpReachable() {
  try {
    const res = await tfetch(`${FRP_STAGING_URL}/wp-json/wp/v2/types`);
    if (res.status !== 200) {
      record(1, 'WP REST reachable', 'fail', `status ${res.status}`);
      return null;
    }
    const body = await res.json();
    const count = body && typeof body === 'object' ? Object.keys(body).length : 0;
    record(1, 'WP REST reachable', 'pass', `200 / ${count} types`);
    return res;
  } catch (err) {
    record(1, 'WP REST reachable', 'fail', `connection error: ${err.message}`);
    return null;
  }
}

async function checkUserMe(n, label, user, pass, shouldBeAdmin) {
  try {
    const res = await tfetch(
      `${FRP_STAGING_URL}/wp-json/wp/v2/users/me?context=edit`,
      { headers: { Authorization: basic(user, pass) } }
    );
    if (res.status !== 200) {
      record(n, label, 'fail', `status ${res.status} — check creds`);
      return;
    }
    const body = await res.json();
    const caps = (body && body.capabilities) || {};
    const isAdmin = !!caps.manage_options || !!caps.administrator;
    if (shouldBeAdmin && !isAdmin) {
      record(n, label, 'fail', `${user} lacks manage_options`);
    } else if (!shouldBeAdmin && isAdmin) {
      record(n, label, 'fail', `${user} has manage_options — create a separate subscriber account`);
    } else if (shouldBeAdmin) {
      record(n, label, 'pass', `${user} is administrator`);
    } else {
      record(n, label, 'pass', `${user} is non-admin (ok)`);
    }
  } catch (err) {
    record(n, label, 'fail', `connection error: ${err.message}`);
  }
}

async function check4_mailhog() {
  try {
    const res = await tfetch(FRP_MAILHOG_URL);
    if (res.status === 200) {
      record(4, 'MailHog UI', 'pass', `${FRP_MAILHOG_URL} returned 200`);
    } else {
      record(4, 'MailHog UI', 'fail', `status ${res.status} at ${FRP_MAILHOG_URL}`);
    }
  } catch (err) {
    record(4, 'MailHog UI', 'fail', `connection error: ${err.message}`);
  }
}

async function check5_stripeWebhook() {
  const url = `${FRP_STAGING_URL}/wp-json/frp/v1/stripe/webhook`;
  try {
    const res = await tfetch(url, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: '{}',
    });
    // 404 = handler not built yet (Task 1.7). 400/401 = handler exists, rejected unsigned.
    // Any other status is unexpected — fail loudly so we don't silently approve
    // (e.g.) a 200 that would indicate the webhook accepted an unsigned payload.
    if (res.status === 404) {
      record(5, 'Stripe webhook endpoint', 'pass', `returned 404 (expected until Task 1.7)`);
    } else if (res.status === 400 || res.status === 401) {
      record(5, 'Stripe webhook endpoint', 'pass', `returned ${res.status} (handler rejects unsigned — ok)`);
    } else if (res.status >= 500) {
      record(5, 'Stripe webhook endpoint', 'fail', `server error ${res.status}`);
    } else {
      record(5, 'Stripe webhook endpoint', 'fail', `unexpected status ${res.status} — expected 404/400/401`);
    }
  } catch (err) {
    record(5, 'Stripe webhook endpoint', 'fail', `connection error: ${err.message}`);
  }
}

// Cloudflare-in-front is informational. Staging currently serves direct from
// SiteGround (no CF proxy) by design — we're deferring CF on staging until
// post-launch. Report the state as a skip, not a failure, so it doesn't
// block Task 0.0 sign-off. Flip to a hard check before first prod deploy.
async function check6_cloudflare(wpRes) {
  if (!wpRes) {
    record(6, 'Cloudflare-in-front', 'fail', `skipped — WP unreachable`);
    return;
  }
  const cfRay = wpRes.headers.get('cf-ray');
  const cfCache = wpRes.headers.get('cf-cache-status');
  if (cfRay) {
    record(6, 'Cloudflare-in-front', 'pass', `cf-ray: ${cfRay.slice(0, 20)}`);
  } else if (cfCache) {
    record(6, 'Cloudflare-in-front', 'pass', `cf-cache-status: ${cfCache}`);
  } else {
    record(6, 'Cloudflare-in-front', 'skip', `no cf-ray/cf-cache-status — staging direct-from-origin (deferred; re-enable before prod)`);
  }
}

async function check7_turnstile() {
  try {
    const form = new URLSearchParams({
      secret: TURNSTILE_TEST_SECRET,
      response: 'XXXX.DUMMY.TOKEN.XXXX',
    });
    const res = await tfetch('https://challenges.cloudflare.com/turnstile/v0/siteverify', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: form,
    });
    const body = await res.json().catch(() => null);
    if (body && body.success === true) {
      record(7, 'Turnstile test keys', 'pass', `siteverify success=true`);
    } else {
      const codes = body && body['error-codes'] ? body['error-codes'].join(',') : 'unknown';
      record(7, 'Turnstile test keys', 'fail', `status ${res.status} / siteverify success=false (${codes})`);
    }
  } catch (err) {
    record(7, 'Turnstile test keys', 'fail', `connection error: ${err.message}`);
  }
}

console.log('FRP staging pre-flight check (reading .env.test)\n');

const wpRes = await check1_wpReachable();
await checkUserMe(2, 'Admin app password', FRP_STAGING_USERNAME, FRP_STAGING_APP_PASSWORD, true);
if (SUBSCRIBER_DEFERRED) {
  record(3, 'Subscriber app password', 'skip', 'deferred — provision a subscriber + app password before Task 1.3');
} else {
  await checkUserMe(3, 'Subscriber app password', FRP_STAGING_SUBSCRIBER_USERNAME, FRP_STAGING_SUBSCRIBER_APP_PASSWORD, false);
}
await check4_mailhog();
await check5_stripeWebhook();
await check6_cloudflare(wpRes);
await check7_turnstile();

const passed = results.filter((r) => r.status === 'pass').length;
const failed = results.filter((r) => r.status === 'fail').length;
const skipped = results.filter((r) => r.status === 'skip').length;
const total = results.length;
const summary = `\n${passed} passed, ${failed} failed, ${skipped} skipped (of ${total}).`;
if (failed > 0) {
  console.log(summary + ' Fix ❌ items before starting Task 1.1.');
  process.exit(1);
} else if (skipped > 0) {
  console.log(summary + ' ⏭️  Skipped checks are tracked deferrals — revisit before the relevant downstream task.');
  process.exit(0);
} else {
  console.log(summary);
  process.exit(0);
}
