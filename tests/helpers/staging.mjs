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

// Create a seeded restoration_pro with arbitrary meta fields (including private ones).
// Uses set-post-meta admin endpoint to bypass WP REST API's show_in_rest restrictions.
// opts: { business_name?, license_number?, google_place_id?, yelp_id?,
//          phone?, website?, state?, contact_email?, ...any other meta }
// Returns { pro_id: number }
export async function seedPro(opts = {}) {
  const { business_name, ...metaFields } = opts;
  const post = await createTestPro({ business_name });
  if (Object.keys(metaFields).length > 0) {
    const { status, body } = await frpPost('/wp-json/frp/v1/admin/set-post-meta', {
      post_id: post.id,
      meta: metaFields,
    }, { auth: true });
    if (status !== 200) throw new Error(`seedPro meta failed: ${status} ${JSON.stringify(body)}`);
  }
  return { pro_id: post.id };
}

// Read a post meta value via admin endpoint. Returns '' if not set.
export async function readMeta(postId, key) {
  const { status, body } = await frpGet(`/wp-json/frp/v1/admin/get-post-meta?post_id=${postId}&key=${key}`, { auth: true });
  if (status !== 200) throw new Error(`readMeta(${postId}, ${key}) failed: ${status} ${JSON.stringify(body)}`);
  return body.value;
}

// Alias for readMeta — returns raw meta value ('' if not set, never undefined).
export async function readMetaRaw(postId, key) {
  return readMeta(postId, key);
}

// Set a single post meta key via admin endpoint.
export async function setPostMeta(postId, key, value) {
  const { status, body } = await frpPost('/wp-json/frp/v1/admin/set-post-meta', {
    post_id: postId,
    meta: { [key]: value },
  }, { auth: true });
  if (status !== 200) throw new Error(`setPostMeta(${postId}, ${key}) failed: ${status} ${JSON.stringify(body)}`);
}

// Count all restoration_pro posts accessible to admin (any status).
// Used in apply-claim tests to assert delta (±N posts) from a baseline.
export async function countPros() {
  const { status, body } = await frpGet('/wp-json/wp/v2/restoration_pro?per_page=100&status=any', { auth: true });
  return (status === 200 && Array.isArray(body)) ? body.length : 0;
}
