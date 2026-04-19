import test from 'node:test';
import assert from 'node:assert/strict';
import { WordPressPublisher } from '../scripts/wp-publisher.js';

test('publisher rejects leadCapture site key', () => {
  assert.throws(() => new WordPressPublisher('leadCapture'), /unknown site|retired|authority only/i);
});

test('publisher accepts authority', () => {
  const p = new WordPressPublisher('authority');
  assert.equal(p.siteName, 'authority');
});
