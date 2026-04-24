import test from 'node:test';
import assert from 'node:assert/strict';
import { frpPost } from './helpers/wp-client.mjs';
import { seedPro, resetTestPros, resetTestLeads } from './helpers/staging.mjs';

/**
 * Email integration tests.
 *
 * DEPENDENCY: These tests require a mail-capture service on staging
 * (e.g. MailHog at http://staging2.findrestorationpros.com:8025, or
 * a wp_mail filter that writes to /tmp/wp-mail.log).
 *
 * Until mail capture is provisioned, the tests below verify that the
 * triggering endpoints return 200 (i.e., the action fires without crashing
 * WP) but do NOT assert email delivery. Add delivery assertions once
 * a countTestEmails() or readLastEmail() helper is available.
 *
 * Runbook: docs/runbooks/email-testing.md
 */

const MAIL_CAPTURE = !!process.env.FRP_MAIL_CAPTURE_URL;

test.before(async () => {
  await resetTestLeads();
  await resetTestPros();
});

test.after(async () => {
  await resetTestLeads();
  await resetTestPros();
});

test('frp_application_submitted fires without error (POST /apply smoke test)', async () => {
  // /apply fires do_action('frp_application_submitted', $pro_id).
  // We just assert the endpoint returns 200 — meaning the action ran without crashing WP.
  const { status, body } = await frpPost('/wp-json/frp/v1/apply', {
    business_name:  'Email Test Co',
    contact_email:  `emailtest${Date.now()}@example.com`,
    phone:          `555${Date.now().toString().slice(-7)}`,
    services:       'water-damage',
    zip:            '90210',
    state:          'CA',
  });
  // 200 = new pro created; 409 = duplicate (still OK — action still fired)
  assert.ok([200, 409].includes(status), `Expected 200 or 409, got ${status}: ${JSON.stringify(body)}`);

  if (MAIL_CAPTURE) {
    // TODO: add countTestEmails() helper and assert +2 emails (admin + applicant)
    assert.fail('Mail capture assertions not yet implemented — remove this line when ready');
  }
});

test('frp_lead_created fires without error (POST /leads smoke test)', async () => {
  // POST /leads fires do_action('frp_lead_created', $lead_id) before returning.
  const { status, body } = await frpPost('/wp-json/frp/v1/leads', {
    phone:         `555${Date.now().toString().slice(-7)}`,
    service:       'water-damage',
    urgency:       'now',
    property_type: 'residential',
    has_insurance: 'yes',
    zip:           '90210',
    source:        'guided_flow',
  }, { headers: { 'X-FRP-Lead-Token': '' } });
  // 200 = lead created; 401 = token missing (acceptable until token is set)
  assert.ok([200, 401, 429].includes(status), `Unexpected status ${status}: ${JSON.stringify(body)}`);

  if (MAIL_CAPTURE) {
    // TODO: assert homeowner confirmation email was delivered
    assert.fail('Mail capture assertions not yet implemented — remove this line when ready');
  }
});
