import test from 'node:test';
import assert from 'node:assert/strict';
import { frpPost, frpPostLead } from './helpers/wp-client.mjs';
import { seedPro, resetTestPros, resetTestLeads, readMeta } from './helpers/staging.mjs';

let proId = 0;

// Factory — overrides replace any key
function profilePayload(overrides = {}) {
  return {
    phone: `555${Date.now().toString().slice(-7)}`,
    lead_contact_email: `lead${Date.now()}@example.com`,
    zip: '90210',
    service: 'water-damage',
    urgency: 'now',
    property_type: 'residential',
    has_insurance: 'not-sure',
    source: 'profile_form',
    preferred_pro_id: proId,   // resolved at call-time — seeded by test.before
    ...overrides,
  };
}

// Tag a lead post_id as test_fixture=1 so resetTestLeads() cleans it up.
// Required for every lead created directly via frpPostLead (not via createTestLead).
async function tagLead(leadId) {
  await frpPost('/wp-json/frp/v1/admin/set-post-meta',
    { post_id: leadId, meta: { test_fixture: '1' } }, { auth: true });
}

test.before(async () => {
  await resetTestLeads();
  await resetTestPros();
  const result = await seedPro({ business_name: 'Profile Form Test Pro', listing_tier: 'free' });
  proId = result.pro_id;
});

test.after(async () => {
  await resetTestLeads();
  await resetTestPros();
});

// ── source allowlist ────────────────────────────────────────────────

test('profile_form source accepted — returns 200', async () => {
  const { status, body } = await frpPostLead('/wp-json/frp/v1/leads', profilePayload());
  assert.equal(status, 200, JSON.stringify(body));
  assert.ok(body.lead_id, 'response must include lead_id');
  await tagLead(body.lead_id);
});

// ── lead_contact_email validation ───────────────────────────────────

test('profile_form missing email returns 400', async () => {
  const p = profilePayload();
  delete p.lead_contact_email;
  const { status } = await frpPostLead('/wp-json/frp/v1/leads', p);
  assert.equal(status, 400);
});

test('profile_form invalid email format returns 400', async () => {
  const { status } = await frpPostLead('/wp-json/frp/v1/leads',
    profilePayload({ lead_contact_email: 'notanemail' })
  );
  assert.equal(status, 400);
});

// ── preferred_pro_id validation ─────────────────────────────────────

test('profile_form missing preferred_pro_id returns 400', async () => {
  const p = profilePayload();
  delete p.preferred_pro_id;
  const { status } = await frpPostLead('/wp-json/frp/v1/leads', p);
  assert.equal(status, 400);
});

test('profile_form preferred_pro_id zero returns 400', async () => {
  const { status } = await frpPostLead('/wp-json/frp/v1/leads',
    profilePayload({ preferred_pro_id: 0 })
  );
  assert.equal(status, 400);
});

test('profile_form nonexistent preferred_pro_id returns 400', async () => {
  const { status } = await frpPostLead('/wp-json/frp/v1/leads',
    profilePayload({ preferred_pro_id: 999999999 })
  );
  assert.equal(status, 400);
});

// ── meta stored correctly ───────────────────────────────────────────

test('profile_form lead stores lead_contact_email and property_address', async () => {
  const email = `stored${Date.now()}@example.com`;
  const addr = '123 Main St';
  const { status, body } = await frpPostLead('/wp-json/frp/v1/leads',
    profilePayload({ lead_contact_email: email, property_address: addr })
  );
  assert.equal(status, 200, JSON.stringify(body));
  await tagLead(body.lead_id);
  assert.equal(await readMeta(body.lead_id, 'lead_contact_email'), email);
  assert.equal(await readMeta(body.lead_id, 'property_address'), addr);
  assert.equal(await readMeta(body.lead_id, 'lead_source'), 'profile_form');
  // lead_email must also carry the homeowner email (dual-write via $email variable overwrite)
  assert.equal(await readMeta(body.lead_id, 'lead_email'), email);
});

test('profile_form lead stores preferred_pro_id', async () => {
  const { status, body } = await frpPostLead('/wp-json/frp/v1/leads', profilePayload());
  assert.equal(status, 200, JSON.stringify(body));
  await tagLead(body.lead_id);
  assert.equal(await readMeta(body.lead_id, 'preferred_pro_id'), String(proId));
});

// ── duplicate bypass ────────────────────────────────────────────────

test('profile_form bypasses duplicate detection — same phone produces two leads', async () => {
  const phone = `555${Date.now().toString().slice(-7)}`;
  const { body: b1 } = await frpPostLead('/wp-json/frp/v1/leads', profilePayload({ phone }));
  const { body: b2 } = await frpPostLead('/wp-json/frp/v1/leads', profilePayload({ phone }));
  assert.ok(b1.lead_id, 'first lead created');
  assert.ok(b2.lead_id, 'second lead created');
  assert.notEqual(b1.lead_id, b2.lead_id, 'must be two distinct leads');
  // Tag both for cleanup
  if (b1.lead_id) await tagLead(b1.lead_id);
  if (b2.lead_id) await tagLead(b2.lead_id);
});

// ── backwards compatibility ─────────────────────────────────────────

test('guided_flow still accepts missing email without 400', async () => {
  const { status, body } = await frpPostLead('/wp-json/frp/v1/leads', {
    phone: `555${Date.now().toString().slice(-7)}`,
    zip: '90210',
    service: 'water-damage',
    urgency: 'now',
    property_type: 'residential',
    has_insurance: 'yes',
    source: 'guided_flow',
  });
  assert.equal(status, 200, `guided_flow without email failed: ${JSON.stringify(body)}`);
  if (body.lead_id) await tagLead(body.lead_id);
});
