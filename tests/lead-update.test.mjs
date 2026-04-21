import test from 'node:test';
import assert from 'node:assert/strict';
import { frpPost } from './helpers/wp-client.mjs';
import { createTestLead, resetTestLeads, resetTestPros } from './helpers/staging.mjs';

test.before(async () => { await resetTestPros(); await resetTestLeads(); });
test.after(async () => { await resetTestLeads(); });

test('valid token cancels the lead', async () => {
  const { lead_id, token } = await createTestLead();
  const { status, body } = await frpPost(`/wp-json/frp/v1/leads/${lead_id}`,
    { token, action: 'cancel' });
  assert.equal(status, 200);
  assert.equal(body.status, 'cancelled');
});

test('wrong token returns 403', async () => {
  const { lead_id } = await createTestLead();
  const { status } = await frpPost(`/wp-json/frp/v1/leads/${lead_id}`,
    { token: 'nope', action: 'cancel' });
  assert.equal(status, 403);
});

test('expired token returns 403', async () => {
  const { lead_id, token } = await createTestLead({ tokenExpired: true });
  const { status } = await frpPost(`/wp-json/frp/v1/leads/${lead_id}`,
    { token, action: 'cancel' });
  assert.equal(status, 403);
});
