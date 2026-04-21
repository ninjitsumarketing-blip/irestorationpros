import test from 'node:test';
import assert from 'node:assert/strict';
import { frpPost } from './helpers/wp-client.mjs';
import { seedPro, resetTestPros, readMeta, setPostMeta, deleteMeta } from './helpers/staging.mjs';

test.before(async () => { await resetTestPros(); });
test.after(async () => { await resetTestPros(); });

test('backfill sets claim_status=unclaimed on pros with no claim_status meta', async () => {
  // seedPro creates the pro without setting claim_status — simulates pre-1.3.5 state.
  const pro = await seedPro({ business_name: 'Legacy Co Backfill' });
  // Explicitly delete claim_status in case something auto-set it.
  await deleteMeta(pro.pro_id, 'claim_status');

  const { status } = await frpPost('/wp-json/frp/v1/admin/run-migration',
    { name: 'claim_status_backfill_v1' }, { auth: true });
  assert.equal(status, 200);
  assert.equal(await readMeta(pro.pro_id, 'claim_status'), 'unclaimed');
});

test('backfill is idempotent — running twice does not overwrite claimed pros', async () => {
  const pro = await seedPro({ business_name: 'Already Claimed Co' });
  await setPostMeta(pro.pro_id, 'claim_status', 'claimed');

  // Run migration twice
  await frpPost('/wp-json/frp/v1/admin/run-migration',
    { name: 'claim_status_backfill_v1' }, { auth: true });
  await frpPost('/wp-json/frp/v1/admin/run-migration',
    { name: 'claim_status_backfill_v1' }, { auth: true });

  // claimed must survive both runs
  assert.equal(await readMeta(pro.pro_id, 'claim_status'), 'claimed');
});
