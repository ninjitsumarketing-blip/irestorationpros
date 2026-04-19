import { frpPost, frpGet, frpDelete } from './wp-client.mjs';

// Create a minimal restoration_pro post for test fixture.
// opts: { business_name?, meta: {...} }
// Returns the created post object (has .id).
export async function createTestPro(opts = {}) {
  const title = opts.business_name || `Test Pro ${Date.now()}`;
  const { status, body } = await frpPost('/wp-json/wp/v2/restoration_pro', {
    title,
    status: 'publish',
    meta: { test_fixture: '1', ...( opts.meta || {} ) },
  }, { auth: true });
  if (status !== 201) throw new Error(`createTestPro failed: ${status} ${JSON.stringify(body)}`);
  return body;
}

// Delete a restoration_pro post by ID (force-delete, bypasses trash).
export async function deleteTestPro(id) {
  const { status } = await frpDelete(`/wp-json/wp/v2/restoration_pro/${id}?force=true`, { auth: true });
  // 200 = deleted, 404 = already gone — both acceptable
  if (status !== 200 && status !== 404) {
    throw new Error(`deleteTestPro(${id}) failed: ${status}`);
  }
}

// Delete all restoration_pro posts that have meta test_fixture=1.
// Call in afterEach / test cleanup to leave staging clean.
export async function resetTestPros() {
  const { status, body } = await frpGet('/wp-json/wp/v2/restoration_pro?meta_key=test_fixture&meta_value=1&per_page=100', { auth: true });
  if (status !== 200 || !Array.isArray(body)) return;
  await Promise.all(body.map(p => deleteTestPro(p.id)));
}
