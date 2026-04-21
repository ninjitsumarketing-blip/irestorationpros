import test from 'node:test';
import assert from 'node:assert/strict';
import { frpGet } from './helpers/wp-client.mjs';
import { createTestLead, resetTestLeads, resetTestPros } from './helpers/staging.mjs';

test.before(async () => { await resetTestPros(); await resetTestLeads(); });
test.after(async () => { await resetTestLeads(); });

test('freshly created lead has token and expiry meta', async () => {
  const { lead_id } = await createTestLead();
  const { status, body: lead } = await frpGet(
    `/wp-json/frp/v1/admin/debug/lead-raw/${lead_id}`, { auth: true });
  assert.equal(status, 200);
  assert.match(lead.lead_update_token, /^[a-f0-9]{32}$/);
  assert.ok(Date.parse(lead.lead_update_token_expiry) > Date.now());
});
