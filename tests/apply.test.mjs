import test from 'node:test';
import assert from 'node:assert/strict';
import { frpPost } from './helpers/wp-client.mjs';
import { readMeta, resetTestPros } from './helpers/staging.mjs';

test.before(async () => { await resetTestPros(); });
test.after(async () => { await resetTestPros(); });

test('apply creates a draft pro and returns application id', async () => {
  // Use a unique suffix so repeated runs don't accumulate drafts that
  // trigger false matcher hits (license_number strong-match or name weak-match).
  const uid = Date.now();
  const { status, body } = await frpPost('/wp-json/frp/v1/apply', {
    business_name: `Acme Restoration ${uid}`,
    contact_name: 'Jane Doe',
    contact_email: 'jane@acmerestoration.test',
    dispatch_phone: '5551234567',
    license_number: `CSLB-${uid}`,
    years_in_business: 8,
    services: ['water-damage', 'mold-remediation'],
    service_area_zips: '90210,90211',
    service_area_city: 'Beverly Hills',
    state: 'CA',
  });
  assert.equal(status, 200);
  assert.ok(body.application_id > 0);
});

test('apply rejects missing required fields', async () => {
  const { status } = await frpPost('/wp-json/frp/v1/apply', { business_name: 'x' });
  assert.equal(status, 400);
});

test('apply rejects invalid service in services[]', async () => {
  const { status } = await frpPost('/wp-json/frp/v1/apply', {
    business_name: 'X', contact_email: 'x@x.test', dispatch_phone: '5551234567',
    state: 'CA', services: ['rocket-science'],
  });
  assert.equal(status, 400);
});

test('apply stores iicrc_certified meta when value is yes', async () => {
  const uid = Date.now();
  const { status, body } = await frpPost('/wp-json/frp/v1/apply', {
    business_name: `IICRC Test Pro ${uid}`,
    contact_email: `iicrc${uid}@test.invalid`,
    dispatch_phone: '5550001111',
    state: 'CA',
    iicrc_certified: 'yes',
  });
  assert.equal(status, 200, `apply failed: ${JSON.stringify(body)}`);
  const proId = body.application_id;
  assert.ok(proId > 0, 'expected application_id');
  const stored = await readMeta(proId, 'iicrc_certified');
  assert.equal(stored, 'yes', `expected iicrc_certified='yes', got '${stored}'`);
});

test('apply stores iicrc_certified=in_progress', async () => {
  const uid = Date.now() + 1;
  const { status, body } = await frpPost('/wp-json/frp/v1/apply', {
    business_name: `IICRC InProgress ${uid}`,
    contact_email: `inprog${uid}@test.invalid`,
    dispatch_phone: '5550002222',
    state: 'TX',
    iicrc_certified: 'in_progress',
  });
  assert.equal(status, 200);
  const stored = await readMeta(body.application_id, 'iicrc_certified');
  assert.equal(stored, 'in_progress');
});

test('apply stores iicrc_certified=no', async () => {
  const uid = Date.now() + 2;
  const { status, body } = await frpPost('/wp-json/frp/v1/apply', {
    business_name: `IICRC No ${uid}`,
    contact_email: `iicrcno${uid}@test.invalid`,
    dispatch_phone: '5550004444',
    state: 'WA',
    iicrc_certified: 'no',
  });
  assert.equal(status, 200);
  const stored = await readMeta(body.application_id, 'iicrc_certified');
  assert.equal(stored, 'no');
});

test('apply ignores invalid iicrc_certified values', async () => {
  const uid = Date.now() + 3;
  const { status, body } = await frpPost('/wp-json/frp/v1/apply', {
    business_name: `IICRC Invalid ${uid}`,
    contact_email: `invalid${uid}@test.invalid`,
    dispatch_phone: '5550003333',
    state: 'NY',
    iicrc_certified: 'definitely-certified',
  });
  assert.equal(status, 200);
  const stored = await readMeta(body.application_id, 'iicrc_certified');
  assert.equal(stored, '', `expected empty string for invalid value, got '${stored}'`);
});

test('apply writes phone meta key (not only dispatch_phone)', async () => {
  const uid = Date.now() + 4;
  const { status, body } = await frpPost('/wp-json/frp/v1/apply', {
    business_name: `Phone Meta Test ${uid}`,
    contact_email: `phonemeta${uid}@test.invalid`,
    dispatch_phone: '5559876543',
    state: 'FL',
  });
  assert.equal(status, 200);
  const proId = body.application_id;
  const phoneMeta = await readMeta(proId, 'phone');
  assert.equal(phoneMeta, '5559876543', `expected phone meta='5559876543', got '${phoneMeta}'`);
});
