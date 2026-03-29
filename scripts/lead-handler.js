// ============================================================
// SECURE LEAD HANDLER
// Safely processes customer form submissions
// ============================================================
// WHY THIS FILE EXISTS (for new programmers):
//
// When a homeowner fills out your form at 2am with a burst pipe,
// they're trusting you with their name, phone, and address.
// This file is the "security guard" for that data — it checks
// every submission before anything gets stored or sent.
//
// The flow is:
//   Form submit → honeypot check → rate limit → validate
//   → sanitize → deduplicate → sign → send to buyer
// ============================================================

import crypto from "crypto";
import { CONFIG } from "../config/config.js";
import {
  validateLeadData,
  checkRateLimit,
  checkHoneypot,
  signWebhookPayload,
  logger,
} from "./security.js";

// ----------------------------------------------------------
// IN-MEMORY DEDUP STORE
// ----------------------------------------------------------
// WHY: Prevents sending the same lead to a buyer twice.
// If someone submits the form twice in 24 hours, only the
// first submission goes through. This protects your reputation
// with buyers — duplicate leads = they stop paying.

const recentLeads = new Map(); // { phoneHash: timestamp }

function isDuplicate(phone) {
  // We hash the phone number before storing it
  // WHY: Even this temporary store shouldn't hold raw phone numbers
  const phoneHash = crypto
    .createHash("sha256")
    .update(phone + process.env.MAKE_WEBHOOK_SECRET)
    .digest("hex");

  const lastSeen = recentLeads.get(phoneHash);
  if (!lastSeen) {
    recentLeads.set(phoneHash, Date.now());
    return false;
  }

  const windowMs = CONFIG.settings.leadRouting.dedupeWindowHours * 60 * 60 * 1000;
  if (Date.now() - lastSeen < windowMs) {
    return true; // Duplicate within window
  }

  // Outside window — update timestamp and allow
  recentLeads.set(phoneHash, Date.now());
  return false;
}

// Clean up old entries every 6 hours
setInterval(() => {
  const windowMs = CONFIG.settings.leadRouting.dedupeWindowHours * 60 * 60 * 1000;
  const now = Date.now();
  for (const [hash, timestamp] of recentLeads.entries()) {
    if (now - timestamp > windowMs) recentLeads.delete(hash);
  }
}, 6 * 60 * 60 * 1000);

// ----------------------------------------------------------
// MAIN LEAD PROCESSOR
// ----------------------------------------------------------
// This is called whenever a form is submitted.
// ipAddress comes from the web server (not the form itself
// — users can't fake it at the server level).

export async function processLeadSubmission(rawData, ipAddress) {
  const result = {
    accepted: false,
    reason: null,
    leadId: null,
  };

  // --- STEP 1: Honeypot check ---
  // If the invisible bot-trap field has any value, reject silently
  // We don't tell the bot it was caught — just pretend it worked
  const honeypot = checkHoneypot(rawData._honeypot || rawData.website || "");
  if (honeypot.isBot) {
    logger.warn(`Bot submission blocked from IP: ${ipAddress}`);
    // Return "success" to confuse the bot — don't reveal you caught it
    result.accepted = true;
    result.reason = "bot_silently_rejected";
    return result;
  }

  // --- STEP 2: Rate limit check ---
  const rateLimit = checkRateLimit(ipAddress);
  if (!rateLimit.allowed) {
    logger.warn(`Rate limit hit from IP: ${ipAddress}`);
    result.accepted = false;
    result.reason = rateLimit.message;
    return result;
  }

  // --- STEP 3: Validate and sanitize all fields ---
  const { isValid, errors, sanitized } = validateLeadData({
    name: rawData.name || rawData.your_name || "",
    phone: rawData.phone || rawData.your_phone || "",
    email: rawData.email || rawData.your_email || "",
    city: rawData.city || rawData.your_city || "",
    service: rawData.service || rawData.damage_type || "other",
    message: rawData.message || rawData.your_message || "",
  });

  if (!isValid) {
    result.accepted = false;
    result.reason = errors.map(e => e.message).join(", ");
    logger.info(`Lead validation failed: ${result.reason}`);
    return result;
  }

  // --- STEP 4: Duplicate check ---
  if (isDuplicate(sanitized.phone)) {
    logger.info("Duplicate lead submission blocked");
    // Tell the user it was received (it was, just not re-sent)
    result.accepted = true;
    result.reason = "duplicate_suppressed";
    return result;
  }

  // --- STEP 5: Build the clean lead object ---
  // Only include fields we explicitly allow — no extras
  const lead = {
    id: crypto.randomUUID(),            // Unique ID for this lead
    receivedAt: new Date().toISOString(),
    name: sanitized.name,
    phone: sanitized.phone,
    email: sanitized.email || null,
    city: sanitized.city,
    service: sanitized.service,
    message: sanitized.message || null,
    source: "web_form",
    // Note: we do NOT store the IP address in the lead record
    // for privacy reasons — we only used it for rate limiting
  };

  // --- STEP 6: Send to buyer via signed webhook ---
  try {
    await sendToMake(lead);
    logger.info(`Lead accepted and sent: ${lead.id}`);
    result.accepted = true;
    result.leadId = lead.id;
  } catch (err) {
    logger.error(`Failed to route lead ${lead.id}`, err);
    result.accepted = false;
    result.reason = "routing_error";
  }

  return result;
}

// ----------------------------------------------------------
// MAKE.COM WEBHOOK SENDER WITH SIGNATURE
// ----------------------------------------------------------
async function sendToMake(lead) {
  const { body, headers } = signWebhookPayload(lead);

  const res = await fetch(CONFIG.make.leadWebhookUrl, {
    method: "POST",
    headers,
    body,
    // Timeout after 10 seconds — don't hang forever
    signal: AbortSignal.timeout(10000),
  });

  if (!res.ok) {
    throw new Error(`Make.com webhook failed: HTTP ${res.status}`);
  }

  return res;
}

// ----------------------------------------------------------
// WORDPRESS PLUGIN INTEGRATION
// ----------------------------------------------------------
// Add this to your WordPress functions.php or a custom plugin
// to connect WPForms submissions to this secure handler.
//
// HOW: WPForms fires a webhook on submission. That webhook
// hits a small endpoint on your Node server which calls
// processLeadSubmission() above.
//
// The PHP code below is what goes in WordPress — it sends
// the form data to your Node.js security handler first,
// rather than directly to Zapier.

export const WORDPRESS_WEBHOOK_PHP = `
<?php
/**
 * Add to functions.php or a custom plugin
 * Routes WPForms submissions through the Node.js security handler
 */
add_action('wpforms_process_complete', function($fields, $entry, $form_data) {
    $lead_data = [
        'name'      => sanitize_text_field($fields[1]['value'] ?? ''),
        'phone'     => sanitize_text_field($fields[2]['value'] ?? ''),
        'email'     => sanitize_email($fields[3]['value'] ?? ''),
        'city'      => sanitize_text_field($fields[4]['value'] ?? ''),
        'service'   => sanitize_text_field($fields[5]['value'] ?? ''),
        'message'   => sanitize_textarea_field($fields[6]['value'] ?? ''),
        '_honeypot' => sanitize_text_field($_POST['website'] ?? ''),
    ];

    // Send to your Node.js security handler
    wp_remote_post('https://your-node-server.com/leads', [
        'body'    => json_encode($lead_data),
        'headers' => ['Content-Type' => 'application/json'],
        'timeout' => 10,
    ]);
}, 10, 3);
?>
`;
