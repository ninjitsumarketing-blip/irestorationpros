import test from 'node:test';
import assert from 'node:assert/strict';
import { frpPost, frpGet } from './helpers/wp-client.mjs';
import { seedPro, resetTestPros, countPros } from './helpers/staging.mjs';

test.before(async () => { await resetTestPros(); });
test.after(async () => { await resetTestPros(); });

test('strong match → claim_sent, no new pro created', async () => {
  await seedPro({ license_number: 'CSLB-CLAIM1', contact_email: 'owner@biz.test', business_name: 'Claim Biz One' });
  const countBefore = await countPros();
  const { status, body } = await frpPost('/wp-json/frp/v1/apply', {
    business_name: 'Claim Biz One', contact_email: 'applicant@somewhere.test',
    dispatch_phone: '5551234567', state: 'CA', license_number: 'CSLB-CLAIM1',
  });
  const countAfter = await countPros();
  assert.equal(status, 200);
  assert.equal(body.status, 'claim_sent');
  assert.equal(body.match_tier, 'strong');
  assert.ok(body.pro_id > 0);
  assert.equal(countAfter - countBefore, 0); // NO new pro
});

test('weak match → pending_manual_review, admin review ticket created', async () => {
  await seedPro({ business_name: 'Similar Name Restoration Co', state: 'CA', phone: '5550000001' });
  const { status, body } = await frpPost('/wp-json/frp/v1/apply', {
    business_name: 'Similar Name Restoration Co', contact_email: 'x@x.test',
    dispatch_phone: '5559998888', state: 'TX',
  });
  assert.equal(status, 200);
  assert.equal(body.status, 'pending_manual_review');
  assert.ok(body.ticket_id > 0);
  // verify the ticket exists
  const { status: ts, body: ticket } = await frpGet(`/wp-json/wp/v2/frp_claim_review/${body.ticket_id}`, { auth: true });
  assert.equal(ts, 200);
  assert.match(ticket.title?.rendered ?? '', /Review:/i);
});

test('no match → original insert behavior, status pending_review', async () => {
  const countBefore = await countPros();
  // Unique name so repeated runs don't accumulate drafts that the weak-tier
  // fuzzy matcher picks up as a ≥0.90 similarity hit on the next run.
  const { status, body } = await frpPost('/wp-json/frp/v1/apply', {
    business_name: `Unique Co ${Date.now()} Never Seen`, contact_email: 'new@new.test',
    dispatch_phone: '5550001111', state: 'NV',
  });
  const countAfter = await countPros();
  assert.equal(status, 200);
  assert.equal(body.status, 'pending_review');
  assert.equal(countAfter - countBefore, 1);
});

test('strong match with no on-file email → pending_manual_review', async () => {
  await seedPro({ license_number: 'CSLB-NOEMAIL', contact_email: '', business_name: 'No Email Biz' });
  const { status, body } = await frpPost('/wp-json/frp/v1/apply', {
    business_name: 'No Email Biz', contact_email: 'a@a.test',
    dispatch_phone: '5554443333', state: 'CA', license_number: 'CSLB-NOEMAIL',
  });
  assert.equal(status, 200);
  assert.equal(body.status, 'pending_manual_review');
  assert.match(body.reason, /no on-file email/i);
});

test('apply.test.mjs still passes — original validation still works', async () => {
  // Missing required fields → 400
  const { status: s1 } = await frpPost('/wp-json/frp/v1/apply', { business_name: 'x' });
  assert.equal(s1, 400);
  // Invalid service → 400
  const { status: s2 } = await frpPost('/wp-json/frp/v1/apply', {
    business_name: 'X', contact_email: 'x@x.test', dispatch_phone: '5551234567',
    state: 'CA', services: ['rocket-science'],
  });
  assert.equal(s2, 400);
});
