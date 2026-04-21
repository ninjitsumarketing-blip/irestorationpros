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
// Uses /frp/v1/admin/delete-post (POST) instead of HTTP DELETE because
// SiteGround WAF strips Authorization headers from DELETE requests (returns 401).
export async function deleteTestPro(id) {
  const { status } = await frpPost('/wp-json/frp/v1/admin/delete-post', { post_id: id }, { auth: true });
  // 200 = deleted or already gone — handler is idempotent
  if (status !== 200) {
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
// NOTE: returns at most 100 (WP REST max per_page). Suitable only for staging
// environments with <100 pros. Do NOT use this for absolute counts in CI.
export async function countPros() {
  const { status, body } = await frpGet('/wp-json/wp/v2/restoration_pro?per_page=100&status=any', { auth: true });
  return (status === 200 && Array.isArray(body)) ? body.length : 0;
}

// Delete a single post meta key via admin endpoint.
// Used to simulate pre-migration state (e.g. no claim_status row).
export async function deleteMeta(postId, key) {
  const { status, body } = await frpPost('/wp-json/frp/v1/admin/delete-post-meta', {
    post_id: postId,
    key,
  }, { auth: true });
  if (status !== 200) throw new Error(`deleteMeta(${postId}, ${key}) failed: ${status} ${JSON.stringify(body)}`);
}

/**
 * Create an frp_claim_review ticket for testing admin endpoints.
 * fields: { match_tier?, candidate_pro_id?, reason?, applicant_json? }
 */
export async function createReviewTicket(fields = {}) {
  const title = 'Review: fixture-' + Date.now();
  const { status, body } = await frpPost('/wp-json/wp/v2/frp_claim_review', {
    title,
    status: 'publish',
  }, { auth: true });
  if (status !== 201) throw new Error(`createReviewTicket post failed: ${status} ${JSON.stringify(body)}`);
  const ticketId = body.id;
  await frpPost('/wp-json/frp/v1/admin/set-post-meta', {
    post_id: ticketId,
    meta: {
      match_tier:       fields.match_tier       ?? 'weak',
      candidate_pro_id: String(fields.candidate_pro_id ?? 0),
      reason:           fields.reason           ?? 'test fixture',
      applicant_json:   fields.applicant_json   ?? '{}',
      review_status:    'open',
    },
  }, { auth: true });
  return ticketId;
}
