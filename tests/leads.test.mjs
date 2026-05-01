// tests/leads.test.mjs
import test from 'node:test';
import assert from 'node:assert/strict';
import { frpPost, frpPostLead } from './helpers/wp-client.mjs';
import { resetTestLeads, readMeta } from './helpers/staging.mjs';

test.before(async () => { await resetTestLeads(); });
test.after(async ()  => { await resetTestLeads(); });

test('dispatch writes routing history, current assignee, and deadline', async () => {
  // Submit a real lead via REST — reuses same endpoint as production
  const uid = Date.now();
  const { status, body } = await frpPostLead('/wp-json/frp/v1/leads', {
    phone:         `555${uid.toString().slice(-7)}`,
    service:       'water-damage',
    urgency:       'now',
    property_type: 'residential',
    has_insurance: 'yes',
    zip:           '90210',
    source:        'guided_flow',
  });

  assert.equal(status, 200, `Lead create failed: ${JSON.stringify(body)}`);
  const lead_id = body.lead_id;
  assert.ok(lead_id, 'Response must include lead_id');

  // Tag for cleanup
  await frpPost('/wp-json/frp/v1/admin/set-post-meta',
    { post_id: lead_id, meta: { test_fixture: '1' } }, { auth: true });

  // Read the three new meta keys
  const historyRaw  = await readMeta(lead_id, 'lead_routing_history');
  const assigneeRaw = await readMeta(lead_id, 'lead_current_assignee');
  const deadlineRaw = await readMeta(lead_id, 'lead_response_deadline');

  // Routing history: valid JSON array with at least one entry
  let history;
  try { history = JSON.parse(historyRaw); } catch { assert.fail(`lead_routing_history is not valid JSON: ${historyRaw}`); }
  assert.ok(Array.isArray(history) && history.length > 0, 'lead_routing_history must be non-empty array');

  const entry = history[0];
  assert.equal(typeof entry.pro_id,      'number', 'entry.pro_id must be number');
  assert.equal(typeof entry.assigned_at, 'number', 'entry.assigned_at must be unix timestamp');
  assert.equal(entry.responded_at, null,            'entry.responded_at must be null on creation');
  assert.equal(entry.status,       'pending',       'entry.status must be "pending"');

  // Current assignee: matches pro_id in history
  assert.equal(Number(assigneeRaw), entry.pro_id, 'lead_current_assignee must match first history entry pro_id');

  // Deadline: unix timestamp in the future and approximately now + 24 hours
  const deadline = Number(deadlineRaw);
  assert.ok(deadline > Math.floor(Date.now() / 1000), 'lead_response_deadline must be in the future');

  // Deadline should be approximately now + 24 hours (within a 60-second tolerance)
  const expectedMin = Math.floor(Date.now() / 1000) + 86400 - 60;
  assert.ok(deadline >= expectedMin, `deadline too soon: expected >= ${expectedMin}, got ${deadline}`);
});

test('dispatch uses 1-hour deadline for emergency urgency', async () => {
  const uid = Date.now();
  const { status, body } = await frpPost('/wp-json/frp/v1/leads', {
    phone:         `555${uid.toString().slice(-7)}`,
    service:       'water-damage',
    urgency:       'emergency',
    property_type: 'residential',
    has_insurance: 'yes',
    zip:           '90210',
    source:        'guided_flow',
  });

  assert.equal(status, 200, `Emergency lead create failed: ${JSON.stringify(body)}`);
  const lead_id = body.lead_id;
  await frpPost('/wp-json/frp/v1/admin/set-post-meta',
    { post_id: lead_id, meta: { test_fixture: '1' } }, { auth: true });

  const deadlineRaw = await readMeta(lead_id, 'lead_response_deadline');
  const deadline    = Number(deadlineRaw);
  const now         = Math.floor(Date.now() / 1000);

  // Should be approximately now + 1 hour (within 60s tolerance)
  assert.ok(deadline > now,               'emergency deadline must be in the future');
  assert.ok(deadline <= now + 3600 + 60,  'emergency deadline must not exceed ~1 hour');
  assert.ok(deadline >= now + 3600 - 60,  'emergency deadline must be approximately 1 hour from now');
});
