import { frpPost, frpGet, frpDelete, frpPostLead } from './wp-client.mjs';

// Authenticate as the pro user (restoration_pro role) for billing/contractor tests.
// Returns { auth: 'pro', pro_id: number }.
// `label` is informational only — authentication always uses FRP_STAGING_PRO_USERNAME/APP_PASSWORD.
// pro_id will be 0 if the get-user-meta admin endpoint is not yet provisioned (acceptable for Task 1.6).
export async function loginAsPro(label = 'pro') {
  // Verify pro auth works — get user info
  const { status, body } = await frpGet('/wp-json/wp/v2/users/me', { auth: 'pro' });
  if (status !== 200) {
    throw new Error(`loginAsPro("${label}"): authentication failed: ${status} ${JSON.stringify(body)}`);
  }
  const userId = body.id;
  // Fetch bound frp_pro_id from user meta via admin endpoint (if available).
  const metaRes = await frpGet(
    `/wp-json/frp/v1/admin/get-user-meta?user_id=${userId}&key=frp_pro_id`,
    { auth: true }
  );
  const pro_id = metaRes.status === 200 ? Number(metaRes.body.value) || 0 : 0;
  return { auth: 'pro', pro_id };
}

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
// Also flushes rate-limit transients so repeated test runs from the same IP
// don't exhaust per-hour quotas (e.g. the 20 req/hr /apply limit).
// Call in test.before / test.after for cleanup.
export async function resetTestPros() {
  const { status, body } = await frpGet('/wp-json/wp/v2/restoration_pro?meta_key=test_fixture&meta_value=1&per_page=100', { auth: true });
  if (status === 200 && Array.isArray(body)) {
    await Promise.all(body.map(p => deleteTestPro(p.id)));
  }
  // Delete apply-created draft pros (joined_source=apply_new) so they don't
  // accumulate and trigger false matcher hits on repeated runs.
  await frpPost('/wp-json/frp/v1/admin/cleanup-apply-drafts', {}, { auth: true }).catch(() => {});
  // Flush frp_rl_* transients — silently ignore errors (endpoint may not exist
  // on older deploys; tests will just hit rate limits naturally in that case).
  await frpPost('/wp-json/frp/v1/admin/reset-rate-limits', {}, { auth: true }).catch(() => {});
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

// Creates a test lead via the REST API. Tags it test_fixture=1 for cleanup.
// opts.tokenExpired: true → backdates lead_update_token_expiry by 2 hours
export async function createTestLead(opts = {}) {
  const uid = Date.now();
  const { status, body } = await frpPostLead('/wp-json/frp/v1/leads', {
    phone: `555${uid.toString().slice(-7)}`,
    service: 'water-damage',
    urgency: 'now',
    property_type: 'residential',
    has_insurance: 'yes',
    zip: '90210',
    source: 'guided_flow',
  });
  if (status !== 200) throw new Error(`createTestLead failed: ${status} ${JSON.stringify(body)}`);
  // Tag for cleanup
  await frpPost('/wp-json/frp/v1/admin/set-post-meta',
    { post_id: body.lead_id, meta: { test_fixture: '1' } }, { auth: true });
  if (opts.tokenExpired) {
    const expired = new Date(Date.now() - 7_200_000).toISOString();
    await frpPost('/wp-json/frp/v1/admin/set-post-meta',
      { post_id: body.lead_id, meta: { lead_update_token_expiry: expired } }, { auth: true });
  }
  if (opts.assignTo) {
    await setPostMeta(body.lead_id, 'lead_assigned_pros', JSON.stringify([opts.assignTo]));
  }
  return { lead_id: body.lead_id, token: body.lead_update_token };
}

// Delete all frp_lead posts tagged test_fixture=1.
export async function resetTestLeads() {
  const { status, body } = await frpGet(
    '/wp-json/wp/v2/frp_lead?meta_key=test_fixture&meta_value=1&per_page=100&status=any',
    { auth: true }
  );
  if (status === 200 && Array.isArray(body)) {
    await Promise.all(body.map(p =>
      frpPost('/wp-json/frp/v1/admin/delete-post', { post_id: p.id }, { auth: true })
    ));
  }
}

// Assign a lead to a pro by setting lead_assigned_pros meta to JSON.stringify([proId]).
export async function assignLeadToPro(leadId, proId) {
  await setPostMeta(leadId, 'lead_assigned_pros', JSON.stringify([proId]));
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
