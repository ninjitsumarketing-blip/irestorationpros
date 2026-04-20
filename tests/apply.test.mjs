import test from 'node:test';
import assert from 'node:assert/strict';
import { frpPost } from './helpers/wp-client.mjs';

test('apply creates a draft pro and returns application id', async () => {
  const { status, body } = await frpPost('/wp-json/frp/v1/apply', {
    business_name: 'Acme Restoration LLC',
    contact_name: 'Jane Doe',
    contact_email: 'jane@acmerestoration.test',
    dispatch_phone: '5551234567',
    license_number: 'CSLB-12345',
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
