// ============================================================
// SECURITY MODULE
// Protects customer data and prevents abuse
// ============================================================
// WHAT THIS FILE DOES (for new programmers):
//
// Imagine your lead form is a front door. Without this file,
// the door has no lock — anyone or any bot can walk in and
// do damage. This file adds:
//
//   1. Input validation  — checks data looks like what it should be
//   2. Sanitization      — strips out dangerous characters/code
//   3. Rate limiting     — stops bots from spamming the form
//   4. Webhook signing   — proves data came from YOU, not a hacker
//   5. Safe logging      — makes sure secrets never appear in logs
// ============================================================

import crypto from "crypto";
import { CONFIG } from "../config/config.js";

// ----------------------------------------------------------
// 1. INPUT VALIDATOR
// ----------------------------------------------------------
// WHY: Without validation, a bad actor can submit anything.
// A field labeled "phone" could receive JavaScript code that
// runs on your page (XSS attack) or database commands that
// delete your data (SQL injection).

const VALIDATION_RULES = {
  name: {
    minLength: 2,
    maxLength: 100,
    // Only letters, spaces, hyphens, apostrophes
    // Rejects: <script>, SELECT *, DROP TABLE, etc.
    pattern: /^[a-zA-Z\s\-'.]+$/,
    message: "Name must be 2-100 characters, letters only",
  },
  phone: {
    minLength: 10,
    maxLength: 15,
    // Allows: (555) 555-5555, 555-555-5555, +15555555555
    pattern: /^[\+]?[(]?[0-9]{3}[)]?[-\s\.]?[0-9]{3}[-\s\.]?[0-9]{4,6}$/,
    message: "Please enter a valid US phone number",
  },
  email: {
    minLength: 5,
    maxLength: 254, // RFC 5321 max email length
    pattern: /^[^\s@]+@[^\s@]+\.[^\s@]+$/,
    message: "Please enter a valid email address",
  },
  city: {
    minLength: 2,
    maxLength: 100,
    pattern: /^[a-zA-Z\s\-'.]+$/,
    message: "City name must be letters only",
  },
  service: {
    // Only allow values from a fixed list — nothing else
    allowedValues: ["water-damage", "fire-damage", "mold-remediation", "storm-damage", "sewage-cleanup", "other"],
    message: "Please select a valid service type",
  },
  message: {
    maxLength: 1000,
    // Allow letters, numbers, basic punctuation
    // Blocks HTML tags and script injection
    pattern: /^[a-zA-Z0-9\s\.,\-!?'"()\n]+$/,
    message: "Message contains invalid characters",
  },
};

export function validateLeadData(data) {
  const errors = [];
  const sanitized = {};

  for (const [field, value] of Object.entries(data)) {
    // Skip unknown fields entirely — don't process what we don't expect
    if (!VALIDATION_RULES[field]) continue;

    const rules = VALIDATION_RULES[field];
    const strValue = String(value || "").trim();

    // Check minimum length
    if (rules.minLength && strValue.length < rules.minLength) {
      errors.push({ field, message: rules.message });
      continue;
    }

    // Check maximum length — hard cut-off prevents memory attacks
    if (rules.maxLength && strValue.length > rules.maxLength) {
      errors.push({ field, message: `${field} is too long` });
      continue;
    }

    // Check allowed values list (for dropdown fields like service)
    if (rules.allowedValues && !rules.allowedValues.includes(strValue)) {
      errors.push({ field, message: rules.message });
      continue;
    }

    // Check against the pattern (regex)
    if (rules.pattern && !rules.pattern.test(strValue)) {
      errors.push({ field, message: rules.message });
      continue;
    }

    // If email, check it's not from a known spam/temp service
    if (field === "email") {
      const domain = strValue.split("@")[1]?.toLowerCase();
      if (CONFIG.security.blockedEmailDomains.includes(domain)) {
        errors.push({ field, message: "Please use a permanent email address" });
        continue;
      }
    }

    // Passed all checks — add to sanitized output
    sanitized[field] = sanitizeString(strValue);
  }

  return { isValid: errors.length === 0, errors, sanitized };
}

// ----------------------------------------------------------
// 2. STRING SANITIZER
// ----------------------------------------------------------
// WHY: Even after validation, we strip out any characters
// that could be used to inject HTML or JavaScript into your
// pages or emails. This is called "output encoding."

export function sanitizeString(str) {
  return str
    // Remove HTML tags — turns <script> into empty string
    .replace(/<[^>]*>/g, "")
    // Convert special HTML characters to safe equivalents
    // < becomes &lt; so it displays as text, not HTML
    .replace(/&/g, "&amp;")
    .replace(/</g, "&lt;")
    .replace(/>/g, "&gt;")
    .replace(/"/g, "&quot;")
    .replace(/'/g, "&#x27;")
    // Remove null bytes (used in some injection attacks)
    .replace(/\0/g, "")
    // Trim whitespace
    .trim();
}

// ----------------------------------------------------------
// 3. RATE LIMITER
// ----------------------------------------------------------
// WHY: Without this, a bot can submit your form thousands
// of times per minute. This ruins your relationship with
// buyers (fake leads), costs you money on notifications,
// and could crash your server.
//
// HOW IT WORKS: We keep a simple in-memory record of how
// many times each IP address has submitted recently.
// After too many submissions, that IP is blocked temporarily.

const submissionLog = new Map(); // { ip: [timestamp, timestamp, ...] }

export function checkRateLimit(ipAddress) {
  const now = Date.now();
  const windowMs = 60 * 60 * 1000; // 1 hour window
  const maxAllowed = CONFIG.security.rateLimitPerHour;

  // Get this IP's submission history
  const history = submissionLog.get(ipAddress) || [];

  // Remove submissions older than 1 hour
  const recentHistory = history.filter(t => now - t < windowMs);

  if (recentHistory.length >= maxAllowed) {
    const oldestSubmission = Math.min(...recentHistory);
    const resetInMinutes = Math.ceil((oldestSubmission + windowMs - now) / 60000);
    return {
      allowed: false,
      message: `Too many submissions. Please try again in ${resetInMinutes} minutes.`,
      remaining: 0,
    };
  }

  // Record this submission
  recentHistory.push(now);
  submissionLog.set(ipAddress, recentHistory);

  return {
    allowed: true,
    remaining: maxAllowed - recentHistory.length,
  };
}

// Clean up old entries every hour to prevent memory leak
setInterval(() => {
  const now = Date.now();
  const windowMs = 60 * 60 * 1000;
  for (const [ip, history] of submissionLog.entries()) {
    const recent = history.filter(t => now - t < windowMs);
    if (recent.length === 0) {
      submissionLog.delete(ip);
    } else {
      submissionLog.set(ip, recent);
    }
  }
}, 60 * 60 * 1000);

// ----------------------------------------------------------
// 4. HONEYPOT CHECKER
// ----------------------------------------------------------
// WHY: A honeypot is an invisible form field that real humans
// never see (it's hidden with CSS). Bots automatically fill
// in every field they find. So if this field has ANY value,
// we know it's a bot submission and reject it silently.
// This is one of the most effective spam prevention methods
// and doesn't annoy real users at all.

export function checkHoneypot(honeypotValue) {
  // If the hidden field has any content, it's a bot
  return {
    isBot: Boolean(honeypotValue && honeypotValue.trim().length > 0),
  };
}

// ----------------------------------------------------------
// 5. WEBHOOK SIGNATURE GENERATOR + VERIFIER
// ----------------------------------------------------------
// WHY: When your system sends lead data to a buyer via webhook,
// you need to prove that data genuinely came from YOUR system
// and wasn't intercepted and modified by someone else.
//
// HOW IT WORKS: You and the buyer share a secret key.
// When you send data, you create a "signature" (a mathematical
// fingerprint of the data + secret). The buyer verifies the
// signature. If someone tampers with the data in transit,
// the signature won't match and the buyer rejects it.

export function signWebhookPayload(payload) {
  const body = JSON.stringify(payload);
  const timestamp = Date.now().toString();

  // Create HMAC-SHA256 signature using your secret key
  // HMAC = Hash-based Message Authentication Code
  // It's like a wax seal on a letter — proves it came from you
  const signature = crypto
    .createHmac("sha256", CONFIG.zapier.webhookSecret)
    .update(`${timestamp}.${body}`)
    .digest("hex");

  return {
    body,
    headers: {
      "Content-Type": "application/json",
      "X-Signature-Timestamp": timestamp,
      "X-Signature": `sha256=${signature}`,
      // These headers tell the receiver how to verify the signature
    },
  };
}

export function verifyWebhookSignature(body, timestamp, signature, secret) {
  const expectedSig = crypto
    .createHmac("sha256", secret)
    .update(`${timestamp}.${body}`)
    .digest("hex");

  // timingSafeEqual prevents "timing attacks" where an attacker
  // could figure out the secret by measuring how long comparison takes
  const sigBuffer = Buffer.from(signature.replace("sha256=", ""), "hex");
  const expectedBuffer = Buffer.from(expectedSig, "hex");

  if (sigBuffer.length !== expectedBuffer.length) return false;
  return crypto.timingSafeEqual(sigBuffer, expectedBuffer);
}

// ----------------------------------------------------------
// 6. SAFE LOGGER
// ----------------------------------------------------------
// WHY: Regular console.log() would happily print your API keys
// and customer phone numbers to log files. This logger scrubs
// sensitive patterns before writing anything to logs.

const SENSITIVE_PATTERNS = [
  // API key patterns (long random strings)
  /sk-[a-zA-Z0-9]{20,}/g,
  /key-[a-zA-Z0-9]{20,}/g,
  // Credit card numbers
  /\b\d{4}[\s-]?\d{4}[\s-]?\d{4}[\s-]?\d{4}\b/g,
  // Social Security Numbers
  /\b\d{3}-\d{2}-\d{4}\b/g,
  // Phone numbers in logs
  /\b[\+]?[(]?[0-9]{3}[)]?[-\s\.]?[0-9]{3}[-\s\.]?[0-9]{4,6}\b/g,
  // Email addresses in logs
  /[^\s@]+@[^\s@]+\.[^\s@]+/g,
  // Passwords (basic heuristic)
  /password['":\s]+[^\s,}'"]{6,}/gi,
];

function scrubSensitiveData(message) {
  let scrubbed = String(message);
  for (const pattern of SENSITIVE_PATTERNS) {
    scrubbed = scrubbed.replace(pattern, "[REDACTED]");
  }
  return scrubbed;
}

export const logger = {
  info: (msg, data = null) => {
    const clean = scrubSensitiveData(msg);
    const timestamp = new Date().toISOString();
    console.log(`[${timestamp}] INFO: ${clean}`);
    if (data && process.env.LOG_LEVEL === "debug") {
      console.log(scrubSensitiveData(JSON.stringify(data)));
    }
  },
  warn: (msg) => {
    console.warn(`[${new Date().toISOString()}] WARN: ${scrubSensitiveData(msg)}`);
  },
  error: (msg, err = null) => {
    // Log the error message but never the full stack trace in production
    // Stack traces can reveal your file structure to attackers
    const clean = scrubSensitiveData(msg);
    console.error(`[${new Date().toISOString()}] ERROR: ${clean}`);
    if (err && process.env.NODE_ENV !== "production") {
      console.error(scrubSensitiveData(err.message));
    }
  },
};

// ----------------------------------------------------------
// 7. SAFE URL VALIDATOR
// ----------------------------------------------------------
// WHY: The image upload function accepts URLs. Without checking
// them, an attacker could pass a malicious URL that:
//   - Points to an internal server (SSRF attack)
//   - Has a path like ../../etc/passwd (path traversal)
//   - Downloads malware disguised as an image

export function validateImageUrl(url) {
  try {
    const parsed = new URL(url);

    // Must be HTTPS — never HTTP
    if (parsed.protocol !== "https:") {
      return { valid: false, reason: "URL must use HTTPS" };
    }

    // Must be from an approved domain
    const isAllowed = CONFIG.security.allowedImageDomains
      .some(domain => parsed.hostname === domain || parsed.hostname.endsWith(`.${domain}`));

    if (!isAllowed) {
      return { valid: false, reason: `Domain not in approved list: ${parsed.hostname}` };
    }

    // Block internal/local addresses (SSRF prevention)
    const blocked = ["localhost", "127.0.0.1", "0.0.0.0", "169.254.169.254"];
    if (blocked.includes(parsed.hostname)) {
      return { valid: false, reason: "Internal addresses not allowed" };
    }

    return { valid: true };
  } catch {
    return { valid: false, reason: "Invalid URL format" };
  }
}

// ----------------------------------------------------------
// 8. SAFE FILE PATH VALIDATOR
// ----------------------------------------------------------
// WHY: If your code accepts file paths from any input,
// a path like "../../etc/passwd" could read system files.
// This ensures all file access stays within your project.

export function validateFilePath(filePath, allowedBaseDir) {
  const resolved = path.resolve(filePath);
  const base = path.resolve(allowedBaseDir);

  if (!resolved.startsWith(base)) {
    throw new Error(`Path traversal attempt blocked: ${filePath}`);
  }

  return resolved;
}

import path from "path";
