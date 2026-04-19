import test from 'node:test';
import assert from 'node:assert/strict';
import { frpGet } from './helpers/wp-client.mjs';

test('staging WP is reachable and FRP plugin is active', async () => {
  const { status, body } = await frpGet('/wp-json/frp/v1/search?zip=90210&service=water-damage');
  assert.equal(status, 200);
  assert.ok(Array.isArray(body));
});
