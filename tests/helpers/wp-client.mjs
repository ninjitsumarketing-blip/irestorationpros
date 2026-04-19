// Minimal REST client for staging FRP
import { readFileSync } from 'node:fs';

const ENV = process.env.NODE_ENV === 'test' ? '.env.test' : '.env';
try {
  const text = readFileSync(ENV, 'utf8');
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

// `auth`: false = no auth, true = admin, 'subscriber' = non-admin user.
function resolveAuth(auth) {
  if (auth === true) return AUTH;
  if (auth === 'subscriber') return SUB_AUTH;
  return null;
}

export async function frpGet(path, { auth = false } = {}) {
  const authHeader = resolveAuth(auth);
  const res = await fetch(BASE + path, {
    headers: authHeader ? { Authorization: authHeader } : {},
  });
  return { status: res.status, body: await res.json().catch(() => null) };
}

export async function frpPost(path, body, { auth = false, headers = {} } = {}) {
  const authHeader = resolveAuth(auth);
  const res = await fetch(BASE + path, {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      ...(authHeader ? { Authorization: authHeader } : {}),
      ...headers,
    },
    body: JSON.stringify(body),
  });
  return { status: res.status, body: await res.json().catch(() => null) };
}

export async function frpDelete(path, { auth = false } = {}) {
  const authHeader = resolveAuth(auth);
  const res = await fetch(BASE + path, {
    method: 'DELETE',
    headers: authHeader ? { Authorization: authHeader } : {},
  });
  return { status: res.status, body: await res.json().catch(() => null) };
}

export { BASE };
