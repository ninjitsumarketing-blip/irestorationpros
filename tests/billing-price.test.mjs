import test from 'node:test';
import assert from 'node:assert/strict';
import { frpGet } from './helpers/wp-client.mjs';

test('billing catalog shows paid tier at 24900 cents ($249)', async () => {
  const { status, body } = await frpGet('/wp-json/frp/v1/billing/catalog');
  assert.equal(status, 200, `catalog endpoint failed: ${JSON.stringify(body)}`);
  const paid = Array.isArray(body.tiers)
    ? body.tiers.find(t => t.id === 'paid')
    : null;
  assert.ok(paid, 'paid tier not found in catalog response');
  assert.equal(paid.price_usd_cents, 24900, `expected 24900, got ${paid.price_usd_cents}`);
});
