import test from 'node:test';
import assert from 'node:assert/strict';
import { frpPost, frpGet } from './helpers/wp-client.mjs';
import { seedPro, resetTestPros, readMeta, countPros, createReviewTicket } from './helpers/staging.mjs';

test.before(async () => { await resetTestPros(); });
test.after(async () => { await resetTestPros(); });

test('admin can approve a weak-match ticket → triggers claim email + sets claim_pending', async () => {
  const seeded = await seedPro({ contact_email: 'owner-rd1@biz.test' });
  const ticket = await createReviewTicket({
    candidate_pro_id: seeded.pro_id,
    match_tier: 'weak',
    applicant_json: JSON.stringify({ business: 'Test Co', email: 'applicant@x.test', phone: '5551234567', contact: '', license: '', years: 0, zips: '', city: '', state: 'CA', services: [] }),
  });
  const { status } = await frpPost(`/wp-json/frp/v1/admin/claim-review/${ticket}/approve`, {}, { auth: true });
  assert.equal(status, 200);
  assert.equal(await readMeta(seeded.pro_id, 'claim_status'), 'claim_pending');
});

test('admin can reject a ticket → inserts applicant as new pro', async () => {
  const ticket = await createReviewTicket({
    match_tier: 'weak',
    applicant_json: JSON.stringify({ business: 'Brand New Restore LLC', email: 'new-rd2@x.test', phone: '5559990000', contact: '', license: '', years: 0, zips: '', city: '', state: 'CA', services: [] }),
  });
  const countBefore = await countPros();
  const { status } = await frpPost(`/wp-json/frp/v1/admin/claim-review/${ticket}/reject`, {}, { auth: true });
  assert.equal(status, 200);
  assert.ok(await countPros() - countBefore >= 1);
});

test('non-admin cannot hit approve or reject — 403', async () => {
  const ticket = await createReviewTicket({});
  const approve = await frpPost(`/wp-json/frp/v1/admin/claim-review/${ticket}/approve`, {}, { auth: false });
  assert.ok(approve.status === 401 || approve.status === 403);
  const reject  = await frpPost(`/wp-json/frp/v1/admin/claim-review/${ticket}/reject`,  {}, { auth: false });
  assert.ok(reject.status === 401 || reject.status === 403);
});

test('resolving a ticket marks it resolved + records admin id + timestamp', async () => {
  const ticket = await createReviewTicket({
    applicant_json: JSON.stringify({ business: 'Resolve Test Co', email: 'resolve-rd4@x.test', phone: '5551112222', contact: '', license: '', years: 0, zips: '', city: '', state: 'CA', services: [] }),
  });
  await frpPost(`/wp-json/frp/v1/admin/claim-review/${ticket}/reject`, {}, { auth: true });
  assert.equal(await readMeta(ticket, 'review_status'), 'resolved');
  assert.ok(await readMeta(ticket, 'resolved_by'));
  assert.ok(await readMeta(ticket, 'resolved_at'));
});
