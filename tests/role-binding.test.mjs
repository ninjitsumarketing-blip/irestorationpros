import test from 'node:test';
import assert from 'node:assert/strict';
import { frpPost, frpGet } from './helpers/wp-client.mjs';

test('admin can bind a WP user to a restoration_pro post', async () => {
  const { status, body } = await frpPost('/wp-json/frp/v1/admin/bind-pro-user',
    { user_login: 'testpro1', pro_id: 0 /* auto-create */ },
    { auth: true }
  );
  assert.equal(status, 200);
  assert.ok(body.user_id > 0);
  assert.ok(body.pro_id > 0);
});
