import test from 'node:test';
import assert from 'node:assert/strict';
import { CONFIG } from '../config/config.js';

test('config has no iRP / leadCapture references', () => {
  assert.equal(CONFIG.sites.leadCapture, undefined, 'sites.leadCapture must be removed');
  assert.equal(CONFIG.google.gscSiteUrls.leadCapture, undefined, 'gscSiteUrls.leadCapture must be removed');
  assert.equal(CONFIG.google.ga4PropertyIds.leadCapture, undefined, 'ga4PropertyIds.leadCapture must be removed');
});

test('config still has FRP (authority) site', () => {
  assert.ok(CONFIG.sites.authority);
  assert.equal(CONFIG.sites.authority.name, 'findrestorationpros');
});
