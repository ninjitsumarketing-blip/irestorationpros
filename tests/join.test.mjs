import test from 'node:test';
import assert from 'node:assert/strict';
import { frpGet, frpPost } from './helpers/wp-client.mjs';
import { readMeta, resetTestPros } from './helpers/staging.mjs';

test.before(async () => { await resetTestPros(); });
test.after(async () => { await resetTestPros(); });

test('GET /join/ returns 200 and shortcode is rendered', async () => {
  const { status, body } = await frpGet('/join/');
  assert.equal(status, 200, `Expected 200, got ${status}`);
  // frpGet returns raw text when JSON parse fails (which it will for HTML pages)
  const html = typeof body === 'string' ? body : JSON.stringify(body);
  assert.ok(
    html.includes('frp-join-form'),
    'Expected page HTML to contain frp-join-form. Is [frp_join_form] shortcode placed on the /join/ page?'
  );
});

// Requires Chunk 1 (Task 1 — frp-directory.php iicrc + phone meta writes) to be
// deployed to staging before the readMeta assertions below will pass.
test('join form full payload accepted by /apply endpoint', async () => {
  const uid = Date.now();
  const { status, body } = await frpPost('/wp-json/frp/v1/apply', {
    business_name:     `Join Full Payload ${uid}`,
    dispatch_phone:    '5551234567',
    contact_email:     `joinpayload${uid}@test.invalid`,
    state:             'CA',
    services:          ['water-damage', 'fire-damage'],
    iicrc_certified:   'yes',
    license_number:    'CA-TEST-123',
    years_in_business: 7,
    service_area_zips: '90210,90211',
  });
  assert.equal(status, 200, `apply rejected join-form payload: ${JSON.stringify(body)}`);
  assert.ok(body.application_id > 0, 'expected application_id in response');
  const iicrc = await readMeta(body.application_id, 'iicrc_certified');
  assert.equal(iicrc, 'yes', `iicrc_certified not stored: '${iicrc}'`);
  const phone = await readMeta(body.application_id, 'phone');
  assert.equal(phone, '5551234567', `phone meta key not written: '${phone}'`);
});
