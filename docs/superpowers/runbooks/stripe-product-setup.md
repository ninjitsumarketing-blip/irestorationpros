# Stripe Product Setup Runbook

This runbook covers the one-time Stripe configuration required before FRP billing goes live. Complete it once for staging (test mode) and once for production (live mode).

---

## (a) Create / Log into Stripe and Switch to the Correct Mode

1. Go to [https://dashboard.stripe.com](https://dashboard.stripe.com) and log in.
2. In the top-left corner, select the correct Stripe account for Find Restoration Pros.
3. Toggle the **Test mode** switch (top-right) **ON** when setting up staging.
   - Test mode uses `sk_test_...` / `pk_test_...` keys and `price_test_...` IDs.
   - For production, turn Test mode **OFF** before creating live products.

---

## (b) Create a Product and Price for Each Tier

Repeat the following steps for all three tiers:

| Tier | Product Name | Monthly Price |
|------|-------------|--------------|
| Basic | FRP Basic Listing | $49 / month |
| Paid | FRP Paid Listing | $199 / month |
| Featured | FRP Featured Listing | $499 / month |

**Steps per tier:**

1. In the Stripe Dashboard, go to **Products** → click **+ Add product**.
2. Enter the **Product name** (e.g. `FRP Basic Listing`).
3. Leave the product type as **Service**.
4. Under **Pricing**, select **Recurring**.
5. Set the **Amount** (e.g. `49.00`) and **Currency** to `USD`.
6. Set **Billing period** to **Monthly**.
7. Click **Save product**.

---

## (c) Copy Each Price ID

After saving each product:

1. In the product detail page, scroll to the **Pricing** section.
2. Click the price row to expand it.
3. Copy the **API ID** — it looks like `price_1ABCdef...` (test mode) or `price_1XYZabc...` (live mode).

Keep all three price IDs handy for the next step.

---

## (d) Paste Price IDs into WordPress Admin

1. Log into the WordPress admin at `https://findrestorationpros.com/wp-admin` (or the staging URL).
2. Go to **Settings → FRP Billing**.
3. Paste the three price IDs into the corresponding fields:
   - **Basic Listing** → `price_...` for the $49/month price
   - **Paid Listing** → `price_...` for the $199/month price
   - **Featured Listing** → `price_...` for the $499/month price
4. Click **Save Changes**.

---

## (e) Set Up the Stripe Webhook Endpoint

1. In the Stripe Dashboard, go to **Developers → Webhooks**.
2. Click **+ Add endpoint**.
3. Set the **Endpoint URL** to:
   ```
   https://findrestorationpros.com/wp-json/frp/v1/stripe/webhook
   ```
   (For staging, use the staging domain instead.)
4. Under **Events to send**, select the following events:
   - `checkout.session.completed`
   - `customer.subscription.updated`
   - `customer.subscription.deleted`
5. Click **Add endpoint**.

---

## (f) Copy the Webhook Signing Secret

1. After creating the endpoint, click into it.
2. Under **Signing secret**, click **Reveal** and copy the `whsec_...` value.
3. Add it to `wp-config.php` on the server:
   ```php
   define( 'FRP_STRIPE_WEBHOOK_SECRET', 'whsec_...' );
   ```
   Replace `whsec_...` with the actual signing secret.

---

## (g) Add the Stripe Secret Key to wp-config.php

1. In the Stripe Dashboard, go to **Developers → API keys**.
2. Copy the **Secret key**:
   - Test mode: starts with `sk_test_...`
   - Live mode: starts with `sk_live_...`
3. Add it to `wp-config.php` on the server:
   ```php
   define( 'FRP_STRIPE_SECRET_KEY', 'sk_live_...' );
   ```
   Use `sk_test_...` on staging, `sk_live_...` on production.

---

## Verification Checklist

- [ ] Three products created in Stripe (Basic, Paid, Featured)
- [ ] All three price IDs pasted and saved in WP admin → Settings → FRP Billing
- [ ] Webhook endpoint URL set and pointing to the correct domain
- [ ] Three webhook events selected: `checkout.session.completed`, `customer.subscription.updated`, `customer.subscription.deleted`
- [ ] `FRP_STRIPE_WEBHOOK_SECRET` defined in `wp-config.php`
- [ ] `FRP_STRIPE_SECRET_KEY` defined in `wp-config.php`
- [ ] Verify with `GET /wp-json/frp/v1/billing/catalog` — all three tiers should show `"price_configured": true`
