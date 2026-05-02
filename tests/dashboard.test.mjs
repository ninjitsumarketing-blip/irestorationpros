import test from 'node:test';
import assert from 'node:assert/strict';
import { frpGet } from './helpers/wp-client.mjs';
import { loginAsPro, createTestLead, resetTestLeads } from './helpers/staging.mjs';

// loginAsPro returns { auth: 'pro', pro_id } — pro_id is the restoration_pro post ID
// bound to the staging pro user via user meta frp_pro_id.

test.before(async () => {
  await resetTestLeads();
});

test.after(async () => {
  await resetTestLeads();
});

test('logged-in pro sees their leads, not others', async () => {
  const { pro_id } = await loginAsPro('testpro1');
  assert.ok(pro_id, 'staging pro user must have frp_pro_id set in user meta');

  // Create one lead assigned to THIS pro, one assigned to a nonexistent pro
  const myLead   = await createTestLead({ assignTo: pro_id });
  const otherLead = await createTestLead({ assignTo: 9999999 });

  // Fetch leads HTML via REST endpoint (Basic auth works here; cookie auth would require wp-login.php session)
  const { status, body } = await frpGet('/wp-json/frp/v1/me/leads-html', { auth: 'pro' });
  assert.equal(status, 200, `leads-html endpoint failed: ${JSON.stringify(body)}`);

  const html = body.html ?? '';
  assert.ok(
    new RegExp(`data-lead-id=['"]${myLead.lead_id}['"]`).test(html),
    `Expected pro's own lead (${myLead.lead_id}) in HTML`
  );
  assert.ok(
    !new RegExp(`data-lead-id=['"]${otherLead.lead_id}['"]`).test(html),
    `Did not expect other pro's lead (${otherLead.lead_id}) in HTML`
  );
});

test('unauthenticated request to leads-html returns 401', async () => {
  const { status } = await frpGet('/wp-json/frp/v1/me/leads-html');
  assert.equal(status, 401);
});

test('authenticated pro dashboard has analytics tab', async () => {
  const STAGING_URL           = process.env.FRP_STAGING_URL;
  const STAGING_PRO_USERNAME  = process.env.FRP_STAGING_PRO_USERNAME;
  const STAGING_PRO_APP_PASSWORD = process.env.FRP_STAGING_PRO_APP_PASSWORD;
  const url  = `${STAGING_URL}/contractor/dashboard/`;
  const cred = Buffer.from(`${STAGING_PRO_USERNAME}:${STAGING_PRO_APP_PASSWORD}`).toString('base64');
  const res  = await fetch(url, {
    headers: { 'Authorization': `Basic ${cred}`, 'Accept': 'text/html' },
    redirect: 'follow',
  });
  const html = await res.text();
  assert.equal(res.status, 200, `Dashboard page failed: ${res.status}`);
  assert.ok(html.includes('frp-dashboard'),     'Page must include frp-dashboard class');
  assert.ok(html.includes('frp-analytics-tab'), 'Page must include frp-analytics-tab element');
});
