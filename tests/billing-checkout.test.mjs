import test from 'node:test';
import assert from 'node:assert/strict';
import { frpPost } from './helpers/wp-client.mjs';
import { loginAsPro } from './helpers/staging.mjs';

test('unauthenticated checkout returns 401', async () => {
  const { status } = await frpPost('/wp-json/frp/v1/billing/checkout', { tier: 'paid' });
  assert.equal(status, 401);
});

test('authenticated pro can create a checkout session', async () => {
  const { auth } = await loginAsPro('testpro1');
  const { status, body } = await frpPost('/wp-json/frp/v1/billing/checkout',
    { tier: 'paid' },
    { auth });
  assert.equal(status, 200);
  assert.match(body.checkout_url, /^https:\/\/checkout\.stripe\.com\//);
});
