import test from 'node:test';
import assert from 'node:assert/strict';
import { frpPost } from './helpers/wp-client.mjs';
import { seedPro, resetTestPros } from './helpers/staging.mjs';

// Wipe any fixtures left by a prior crashed run before starting, then clean up after.
test.before(async () => {
  await resetTestPros();
});

test.after(async () => {
  await resetTestPros();
});

test('strong match: license_number exact', async () => {
  const seeded = await seedPro({ license_number: 'CSLB-123456', state: 'CA', business_name: 'Acme Restoration' });
  const { body } = await frpPost('/wp-json/frp/v1/admin/match-test', {
    applicant: { license_number: 'CSLB-123456', state: 'CA', business_name: 'Different Name Inc' },
  }, { auth: true });
  assert.equal(body.tier, 'strong');
  assert.equal(body.pro_id, seeded.pro_id);
  assert.match(body.reason, /license/i);
});

test('strong match: google_place_id', async () => {
  const seeded = await seedPro({ google_place_id: 'ChIJxxxxxx', business_name: 'Acme' });
  const { body } = await frpPost('/wp-json/frp/v1/admin/match-test', {
    applicant: { google_place_id: 'ChIJxxxxxx' },
  }, { auth: true });
  assert.equal(body.tier, 'strong');
  assert.equal(body.pro_id, seeded.pro_id);
});

test('medium match: phone + fuzzy-name ≥0.85', async () => {
  const seeded = await seedPro({ phone: '5551234567', business_name: 'Acme Restoration Services, LLC' });
  const { body } = await frpPost('/wp-json/frp/v1/admin/match-test', {
    applicant: { dispatch_phone: '(555) 123-4567', business_name: 'Acme Restoration Services LLC' },
  }, { auth: true });
  assert.equal(body.tier, 'medium');
  assert.equal(body.pro_id, seeded.pro_id);
});

test('medium match: website-domain + state', async () => {
  const seeded = await seedPro({ website: 'https://www.acmerestoration.com/contact', state: 'CA' });
  const { body } = await frpPost('/wp-json/frp/v1/admin/match-test', {
    applicant: { website: 'http://acmerestoration.com', state: 'CA' },
  }, { auth: true });
  assert.equal(body.tier, 'medium');
  assert.equal(body.pro_id, seeded.pro_id);
});

test('weak match: name-only fuzzy, different phone/state', async () => {
  await seedPro({ business_name: 'Acme Restoration', state: 'CA', phone: '5550000000' });
  const { body } = await frpPost('/wp-json/frp/v1/admin/match-test', {
    applicant: { business_name: 'Acme Restoration', state: 'TX', dispatch_phone: '5559999999' },
  }, { auth: true });
  assert.equal(body.tier, 'weak');
});

test('no match: distinct signals', async () => {
  const { body } = await frpPost('/wp-json/frp/v1/admin/match-test', {
    applicant: { business_name: 'Totally New Biz XYZ', dispatch_phone: '5552220000', state: 'NV' },
  }, { auth: true });
  assert.equal(body.tier, 'none');
  assert.equal(body.pro_id, 0);
});

test('strong beats medium — license wins over phone mismatch', async () => {
  const withLicense = await seedPro({ license_number: 'CSLB-A', business_name: 'A Corp', phone: '5551110000' });
  await seedPro({ business_name: 'A Corp', phone: '5552220000' }); // decoy fuzzy-name
  const { body } = await frpPost('/wp-json/frp/v1/admin/match-test', {
    applicant: { license_number: 'CSLB-A', business_name: 'A Corp', dispatch_phone: '5552220000' },
  }, { auth: true });
  assert.equal(body.tier, 'strong');
  assert.equal(body.pro_id, withLicense.pro_id);
});
