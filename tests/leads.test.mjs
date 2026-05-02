// tests/leads.test.mjs
import test from 'node:test';
import assert from 'node:assert/strict';
import { frpPost, frpPostLead } from './helpers/wp-client.mjs';
import { resetTestLeads, readMeta, loginAsPro, setPostMeta } from './helpers/staging.mjs';

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

// ── Status endpoint tests ─────────────────────────────────────────────────

test('status endpoint — happy path: mark contacted', async () => {
  const { pro_id } = await loginAsPro('testpro1');
  assert.ok(pro_id, 'testpro1 must have frp_pro_id configured');

  const uid = Date.now();
  const createRes = await frpPost('/wp-json/frp/v1/leads', {
    phone: `555${uid.toString().slice(-7)}`,
    service: 'water-damage', urgency: 'now',
    property_type: 'residential', has_insurance: 'yes',
    zip: '90210', source: 'guided_flow',
  });
  assert.equal(createRes.status, 200);
  const lead_id = createRes.body.lead_id;
  await frpPost('/wp-json/frp/v1/admin/set-post-meta',
    { post_id: lead_id, meta: { test_fixture: '1' } }, { auth: true });

  const history = [{ pro_id, assigned_at: Math.floor(Date.now()/1000), responded_at: null, status: 'pending' }];
  await setPostMeta(lead_id, 'lead_routing_history',   JSON.stringify(history));
  await setPostMeta(lead_id, 'lead_current_assignee',  String(pro_id));
  await setPostMeta(lead_id, 'lead_response_deadline', String(Math.floor(Date.now()/1000) + 3600));

  const { status, body } = await frpPost(
    `/wp-json/frp/v1/leads/${lead_id}/status`,
    { status: 'contacted' },
    { auth: 'pro' }
  );
  assert.equal(status, 200, `status endpoint failed: ${JSON.stringify(body)}`);
  assert.equal(body.ok, true);
  assert.equal(body.status, 'contacted');

  const historyRaw = await readMeta(lead_id, 'lead_routing_history');
  const updated    = JSON.parse(historyRaw);
  assert.equal(updated[0].status, 'contacted');
  assert.ok(updated[0].responded_at !== null, 'responded_at should be set');

  const deadline = await readMeta(lead_id, 'lead_response_deadline');
  assert.ok(!deadline || deadline === '', 'deadline should be cleared after response');
});

test('status endpoint — wrong assignee returns 403', async () => {
  const { pro_id } = await loginAsPro('testpro1');

  const uid = Date.now();
  const createRes = await frpPost('/wp-json/frp/v1/leads', {
    phone: `555${uid.toString().slice(-7)}`,
    service: 'water-damage', urgency: 'now',
    property_type: 'residential', has_insurance: 'yes',
    zip: '90210', source: 'guided_flow',
  });
  const lead_id = createRes.body.lead_id;
  await frpPost('/wp-json/frp/v1/admin/set-post-meta',
    { post_id: lead_id, meta: { test_fixture: '1' } }, { auth: true });

  const history = [{ pro_id: 9999999, assigned_at: Math.floor(Date.now()/1000), responded_at: null, status: 'pending' }];
  await setPostMeta(lead_id, 'lead_routing_history',  JSON.stringify(history));
  await setPostMeta(lead_id, 'lead_current_assignee', '9999999');

  const { status } = await frpPost(
    `/wp-json/frp/v1/leads/${lead_id}/status`,
    { status: 'contacted' },
    { auth: 'pro' }
  );
  assert.equal(status, 403, 'Wrong assignee must get 403');
});

test('status endpoint — unauthenticated returns 401', async () => {
  const { status } = await frpPost('/wp-json/frp/v1/leads/1/status', { status: 'contacted' });
  assert.equal(status, 401);
});

test('status endpoint — non-existent lead returns 404', async () => {
  const { status } = await frpPost(
    '/wp-json/frp/v1/leads/99999999/status',
    { status: 'contacted' },
    { auth: 'pro' }
  );
  assert.equal(status, 404, 'Non-existent lead must return 404');
});

test('status endpoint — won clears current assignee', async () => {
  const { pro_id } = await loginAsPro('testpro1');
  const uid = Date.now();
  const createRes = await frpPost('/wp-json/frp/v1/leads', {
    phone: `555${uid.toString().slice(-7)}`,
    service: 'water-damage', urgency: 'now',
    property_type: 'residential', has_insurance: 'yes',
    zip: '90210', source: 'guided_flow',
  });
  const lead_id = createRes.body.lead_id;
  await frpPost('/wp-json/frp/v1/admin/set-post-meta',
    { post_id: lead_id, meta: { test_fixture: '1' } }, { auth: true });

  const history = [{ pro_id, assigned_at: Math.floor(Date.now()/1000), responded_at: null, status: 'pending' }];
  await setPostMeta(lead_id, 'lead_routing_history',  JSON.stringify(history));
  await setPostMeta(lead_id, 'lead_current_assignee', String(pro_id));
  await setPostMeta(lead_id, 'lead_response_deadline', String(Math.floor(Date.now()/1000) + 3600));

  const { status, body } = await frpPost(
    `/wp-json/frp/v1/leads/${lead_id}/status`,
    { status: 'won' },
    { auth: 'pro' }
  );
  assert.equal(status, 200, `won status failed: ${JSON.stringify(body)}`);
  assert.equal(body.status, 'won');

  const assigneeRaw = await readMeta(lead_id, 'lead_current_assignee');
  assert.ok(!assigneeRaw || assigneeRaw === '', 'lead_current_assignee must be empty after won');
});

test('status endpoint — lost closes lead (clears assignee)', async () => {
  const { pro_id } = await loginAsPro('testpro1');
  const uid = Date.now();
  const createRes = await frpPost('/wp-json/frp/v1/leads', {
    phone: `555${uid.toString().slice(-7)}`,
    service: 'water-damage', urgency: 'now',
    property_type: 'residential', has_insurance: 'yes',
    zip: '90210', source: 'guided_flow',
  });
  assert.equal(createRes.status, 200);
  const lead_id = createRes.body.lead_id;
  await frpPost('/wp-json/frp/v1/admin/set-post-meta',
    { post_id: lead_id, meta: { test_fixture: '1' } }, { auth: true });

  const history = [{ pro_id, assigned_at: Math.floor(Date.now()/1000), responded_at: null, status: 'pending' }];
  await setPostMeta(lead_id, 'lead_routing_history',  JSON.stringify(history));
  await setPostMeta(lead_id, 'lead_current_assignee', String(pro_id));
  await setPostMeta(lead_id, 'lead_response_deadline', String(Math.floor(Date.now()/1000) + 3600));

  const { status, body } = await frpPost(
    `/wp-json/frp/v1/leads/${lead_id}/status`,
    { status: 'lost' },
    { auth: 'pro' }
  );
  assert.equal(status, 200, `lost status failed: ${JSON.stringify(body)}`);
  assert.equal(body.status, 'lost');

  // Assignee must be cleared (same as won — both are terminal)
  const assigneeRaw = await readMeta(lead_id, 'lead_current_assignee');
  assert.ok(!assigneeRaw || assigneeRaw === '', 'lead_current_assignee must be empty after lost');
});

test('status endpoint — invalid status returns 400', async () => {
  const { pro_id } = await loginAsPro('testpro1');
  const uid = Date.now();
  const createRes = await frpPost('/wp-json/frp/v1/leads', {
    phone: `555${uid.toString().slice(-7)}`,
    service: 'water-damage', urgency: 'now',
    property_type: 'residential', has_insurance: 'yes',
    zip: '90210', source: 'guided_flow',
  });
  const lead_id = createRes.body.lead_id;
  await frpPost('/wp-json/frp/v1/admin/set-post-meta',
    { post_id: lead_id, meta: { test_fixture: '1' } }, { auth: true });

  const history = [{ pro_id, assigned_at: Math.floor(Date.now()/1000), responded_at: null, status: 'pending' }];
  await setPostMeta(lead_id, 'lead_routing_history',  JSON.stringify(history));
  await setPostMeta(lead_id, 'lead_current_assignee', String(pro_id));

  const { status } = await frpPost(
    `/wp-json/frp/v1/leads/${lead_id}/status`,
    { status: 'banana' },
    { auth: 'pro' }
  );
  assert.equal(status, 400, 'Invalid status value must return 400');
});

test('status endpoint — already responded returns 409', async () => {
  const { pro_id } = await loginAsPro('testpro1');
  const uid = Date.now();
  const createRes = await frpPost('/wp-json/frp/v1/leads', {
    phone: `555${uid.toString().slice(-7)}`,
    service: 'water-damage', urgency: 'now',
    property_type: 'residential', has_insurance: 'yes',
    zip: '90210', source: 'guided_flow',
  });
  const lead_id = createRes.body.lead_id;
  await frpPost('/wp-json/frp/v1/admin/set-post-meta',
    { post_id: lead_id, meta: { test_fixture: '1' } }, { auth: true });

  const history = [{ pro_id, assigned_at: Math.floor(Date.now()/1000) - 60, responded_at: Math.floor(Date.now()/1000), status: 'contacted' }];
  await setPostMeta(lead_id, 'lead_routing_history',  JSON.stringify(history));
  await setPostMeta(lead_id, 'lead_current_assignee', String(pro_id));

  const { status } = await frpPost(
    `/wp-json/frp/v1/leads/${lead_id}/status`,
    { status: 'won' },
    { auth: 'pro' }
  );
  assert.equal(status, 409, 'Already-actioned lead must return 409');
});

test('deadline fallback: overdue lead marks missed and reassigns or exhausts', async () => {
  const { pro_id } = await loginAsPro('testpro1');
  assert.ok(pro_id, 'testpro1 must have frp_pro_id configured');

  const uid = Date.now();
  const createRes = await frpPost('/wp-json/frp/v1/leads', {
    phone: `555${uid.toString().slice(-7)}`,
    service: 'water-damage', urgency: 'now',
    property_type: 'residential', has_insurance: 'yes',
    zip: '90210', source: 'guided_flow',
  });
  assert.equal(createRes.status, 200);
  const lead_id = createRes.body.lead_id;
  await frpPost('/wp-json/frp/v1/admin/set-post-meta',
    { post_id: lead_id, meta: { test_fixture: '1' } }, { auth: true });

  const pastDeadline = String(Math.floor(Date.now() / 1000) - 7200); // 2 hours ago
  const history = [{ pro_id, assigned_at: Math.floor(Date.now()/1000) - 7200, responded_at: null, status: 'pending' }];
  await setPostMeta(lead_id, 'lead_routing_history',   JSON.stringify(history));
  await setPostMeta(lead_id, 'lead_current_assignee',  String(pro_id));
  await setPostMeta(lead_id, 'lead_response_deadline', pastDeadline);

  // Trigger the cron job manually
  const { status: triggerStatus, body: triggerBody } = await frpPost(
    '/wp-json/frp/v1/admin/trigger-deadline-cron', {}, { auth: true }
  );
  assert.equal(triggerStatus, 200, `trigger-deadline-cron failed: ${JSON.stringify(triggerBody)}`);
  assert.ok(typeof triggerBody.processed === 'number', 'trigger response must include numeric processed count');
  assert.ok(triggerBody.processed >= 1, 'processed count must be at least 1 (our test lead)');

  // Verify the original pro is now marked missed
  const historyRaw = await readMeta(lead_id, 'lead_routing_history');
  const updatedHistory = JSON.parse(historyRaw);
  const proEntry = updatedHistory.find(e => e.pro_id === pro_id);
  assert.ok(proEntry, 'Original pro entry must still exist in history');
  assert.equal(proEntry.status, 'missed', 'Original pro must be marked "missed"');
  assert.ok(proEntry.responded_at !== null, 'responded_at must be set on missed entry');

  // Assignee must be cleared (null/empty) or updated to next pro
  const assigneeRaw = await readMeta(lead_id, 'lead_current_assignee');
  const assignee = Number(assigneeRaw);
  assert.ok(
    !assigneeRaw || assigneeRaw === '' || assignee !== pro_id,
    `Assignee must change from original pro (was ${pro_id}, now ${assigneeRaw})`
  );

  // Idempotency: triggering the cron again on an exhausted lead must not re-process it
  if (!assigneeRaw || assigneeRaw === '') {
    const { status: s2 } = await frpPost(
      '/wp-json/frp/v1/admin/trigger-deadline-cron', {}, { auth: true }
    );
    assert.equal(s2, 200);
    const histAfter = JSON.parse(await readMeta(lead_id, 'lead_routing_history'));
    const ourEntries = histAfter.filter(e => e.pro_id === pro_id);
    assert.equal(ourEntries.length, 1, 'Exhausted lead must not accumulate duplicate missed entries on re-trigger');
  }
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
