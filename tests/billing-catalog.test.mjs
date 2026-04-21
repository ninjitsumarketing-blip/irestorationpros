import test from 'node:test';
import assert from 'node:assert/strict';
import { frpGet } from './helpers/wp-client.mjs';

test('billing catalog endpoint returns the three tiers', async () => {
  const { status, body } = await frpGet('/wp-json/frp/v1/billing/catalog');
  assert.equal(status, 200);
  assert.equal(body.tiers.length, 3);
  const ids = body.tiers.map(t => t.id).sort();
  assert.deepEqual(ids, ['basic', 'featured', 'paid']);
});
