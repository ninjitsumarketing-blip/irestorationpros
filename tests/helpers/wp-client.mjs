// Minimal REST client for staging FRP
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, resolve } from 'node:path';

// Minor #6 — resolve .env relative to this file, not process cwd
const __dir = dirname(fileURLToPath(import.meta.url));
// helpers/ is 2 levels below repo root, so go up twice
const envFile = process.env.NODE_ENV === 'test' ? '.env.test' : '.env';
const envPath = resolve(__dir, '../../', envFile);
try {
  const text = readFileSync(envPath, 'utf8');
  for (const line of text.split('\n')) {
    const m = line.match(/^([A-Z_][A-Z0-9_]*)=(.*)$/);
    if (m) process.env[m[1]] ??= m[2];
  }
} catch { /* ignore */ }

// Env names match .env.test.example (provisioned by Task 0.0).
const BASE = process.env.FRP_STAGING_URL || 'https://staging2.findrestorationpros.com';
const AUTH = 'Basic ' + Buffer.from(
  `${process.env.FRP_STAGING_USERNAME}:${String(process.env.FRP_STAGING_APP_PASSWORD || '').replace(/\s+/g, '')}`
).toString('base64');

// Subscriber-role auth for admin-gating tests (see Task 1.3.5d).
// May be empty/placeholder until Task 1.3 subscriber fixture is provisioned.
const SUB_AUTH = 'Basic ' + Buffer.from(
  `${process.env.FRP_STAGING_SUBSCRIBER_USERNAME}:${String(process.env.FRP_STAGING_SUBSCRIBER_APP_PASSWORD || '').replace(/\s+/g, '')}`
).toString('base64');

// Minor #7 — warn when subscriber creds are missing/placeholder
if (!process.env.FRP_STAGING_SUBSCRIBER_USERNAME || process.env.FRP_STAGING_SUBSCRIBER_USERNAME === 'test-subscriber') {
  process.emitWarning('[wp-client] FRP_STAGING_SUBSCRIBER_USERNAME not configured — subscriber auth tests will get 401 (expected until Task 1.3)');
}

// `auth`: false = no auth, true = admin, 'subscriber' = non-admin user.
function resolveAuth(auth) {
  if (auth === true) return AUTH;
  if (auth === 'subscriber') return SUB_AUTH;
  return null;
}

// Critical #1 — AbortController timeout guard on every fetch
const TIMEOUT_MS = 15_000;

function timedFetch(url, init = {}) {
  const ac = new AbortController();
  const timer = setTimeout(() => ac.abort(), TIMEOUT_MS);
  return fetch(url, { ...init, signal: ac.signal }).finally(() => clearTimeout(timer));
}

// Important #4 — parse JSON safely, preserve raw body on failure
async function safeJson(res) {
  const text = await res.text();
  let body;
  try { body = JSON.parse(text); } catch { body = text || null; }
  return body;
}

export async function frpGet(path, { auth = false } = {}) {
  const authHeader = resolveAuth(auth);
  // Important #3 — re-throw network errors with URL + method context
  try {
    const res = await timedFetch(BASE + path, {
      headers: authHeader ? { Authorization: authHeader } : {},
    });
    return { status: res.status, body: await safeJson(res) };
  } catch (err) {
    throw new Error(`GET ${BASE + path} — ${err.message}`, { cause: err });
  }
}

export async function frpPost(path, body, { auth = false, headers = {} } = {}) {
  const authHeader = resolveAuth(auth);
  try {
    const res = await timedFetch(BASE + path, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        ...(authHeader ? { Authorization: authHeader } : {}),
        ...headers,
      },
      body: JSON.stringify(body),
    });
    return { status: res.status, body: await safeJson(res) };
  } catch (err) {
    throw new Error(`POST ${BASE + path} — ${err.message}`, { cause: err });
  }
}

export async function frpDelete(path, { auth = false } = {}) {
  const authHeader = resolveAuth(auth);
  try {
    const res = await timedFetch(BASE + path, {
      method: 'DELETE',
      headers: authHeader ? { Authorization: authHeader } : {},
    });
    return { status: res.status, body: await safeJson(res) };
  } catch (err) {
    throw new Error(`DELETE ${BASE + path} — ${err.message}`, { cause: err });
  }
}

export { BASE };
