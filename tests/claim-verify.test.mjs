import test from 'node:test';
import assert from 'node:assert/strict';
import { frpPost, frpGet } from './helpers/wp-client.mjs';
import { seedPro, resetTestPros, setPostMeta, readMeta, readMetaRaw } from './helpers/staging.mjs';

test.before(async () => { await resetTestPros(); });
test.after(async () => { await resetTestPros(); });

test('valid token + confirm → pro is claimed, user bound', async () => {
  const seeded = await seedPro({ contact_email: 'owner-cv1@biz.test' });
  await setPostMeta(seeded.pro_id, 'claim_token', 'tok-valid-1');
  await setPostMeta(seeded.pro_id, 'claim_token_expiry', new Date(Date.now() + 3600_000).toISOString());
  await setPostMeta(seeded.pro_id, 'claim_status', 'claim_pending');

  const { status, body } = await frpPost(`/wp-json/frp/v1/claim/${seeded.pro_id}`, { token: 'tok-valid-1', confirm: true });
  assert.equal(status, 200);
  assert.equal(body.claimed, true);
  assert.ok(body.user_id);

  assert.equal(await readMeta(seeded.pro_id, 'claim_status'), 'claimed');
  assert.equal(await readMeta(seeded.pro_id, 'joined_source'), 'apply_claim');
  assert.equal(Number(await readMeta(seeded.pro_id, 'claimed_by_user')), body.user_id);
});

test('expired token → 403', async () => {
  const seeded = await seedPro({});
  await setPostMeta(seeded.pro_id, 'claim_token', 'tok-expired');
  await setPostMeta(seeded.pro_id, 'claim_token_expiry', '2020-01-01T00:00:00Z');

  const { status } = await frpPost(`/wp-json/frp/v1/claim/${seeded.pro_id}`, { token: 'tok-expired', confirm: true });
  assert.equal(status, 403);
});

test('wrong token → 403', async () => {
  const seeded = await seedPro({});
  await setPostMeta(seeded.pro_id, 'claim_token', 'correct-tok');
  await setPostMeta(seeded.pro_id, 'claim_token_expiry', new Date(Date.now() + 3600_000).toISOString());

  const { status } = await frpPost(`/wp-json/frp/v1/claim/${seeded.pro_id}`, { token: 'wrong-tok', confirm: true });
  assert.equal(status, 403);
});

test('second claimer after success → 409 conflict', async () => {
  const seeded = await seedPro({ contact_email: 'owner-cv4@biz.test' });
  await setPostMeta(seeded.pro_id, 'claim_token', 'tok-claimed');
  await setPostMeta(seeded.pro_id, 'claim_token_expiry', new Date(Date.now() + 3600_000).toISOString());
  await setPostMeta(seeded.pro_id, 'claim_status', 'claimed');

  const { status } = await frpPost(`/wp-json/frp/v1/claim/${seeded.pro_id}`, { token: 'tok-claimed', confirm: true });
  assert.equal(status, 409);
});

test('preview returns business name without claiming', async () => {
  const seeded = await seedPro({ business_name: 'Acme Restoration CV' });
  await setPostMeta(seeded.pro_id, 'claim_token', 'tok-preview');
  await setPostMeta(seeded.pro_id, 'claim_token_expiry', new Date(Date.now() + 3600_000).toISOString());
  await setPostMeta(seeded.pro_id, 'claim_status', 'claim_pending');

  const { status, body } = await frpPost(`/wp-json/frp/v1/claim/${seeded.pro_id}`, { token: 'tok-preview', confirm: false });
  assert.equal(status, 200);
  assert.equal(body.preview, true);
  assert.equal(body.business, 'Acme Restoration CV');
  assert.equal(await readMeta(seeded.pro_id, 'claim_status'), 'claim_pending');
});

test('missing token → 403 regardless of claim_status', async () => {
  const seeded = await seedPro({});
  await setPostMeta(seeded.pro_id, 'claim_status', 'claimed');

  const { status } = await frpPost(`/wp-json/frp/v1/claim/${seeded.pro_id}`, { confirm: true });
  assert.equal(status, 403);
});

test('applicant meta cleared after successful claim', async () => {
  const seeded = await seedPro({ contact_email: 'owner-cv7@biz.test' });
  await setPostMeta(seeded.pro_id, 'claim_token', 'tok-clear');
  await setPostMeta(seeded.pro_id, 'claim_token_expiry', new Date(Date.now() + 3600_000).toISOString());
  await setPostMeta(seeded.pro_id, 'claim_applicant_email', 'stranger@x.test');
  await setPostMeta(seeded.pro_id, 'claim_applicant_phone', '5559990000');

  await frpPost(`/wp-json/frp/v1/claim/${seeded.pro_id}`, { token: 'tok-clear', confirm: true });
  assert.equal(await readMetaRaw(seeded.pro_id, 'claim_applicant_email'), '');
  assert.equal(await readMetaRaw(seeded.pro_id, 'claim_applicant_phone'), '');
});
