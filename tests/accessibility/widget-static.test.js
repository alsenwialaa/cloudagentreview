import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

const template = readFileSync(new URL('../../templates/widget.php', import.meta.url), 'utf8');
const widget = readFileSync(new URL('../../assets/js/widget.js', import.meta.url), 'utf8');
const css = readFileSync(new URL('../../assets/css/widget.css', import.meta.url), 'utf8');

test('widget template exposes labelled dialog, live regions, and keyboard-operable controls', () => {
  assert.match(template, /data-ysai-root[\s\S]*dir="rtl"/);
  const launcher = template.match(/<button\b[^>]*data-ysai-open[^>]*>/)?.[0] || '';
  assert.match(launcher, /aria-expanded="false"/);
  assert.match(launcher, /aria-controls="ysai-panel"/);
  assert.match(template, /id="ysai-panel"[^>]*role="dialog"[^>]*aria-modal="false"[^>]*aria-labelledby="ysai-title"/);
  assert.match(template, /data-ysai-status[^>]*|role="status"/);
  assert.match(template, /role="status"[^>]*aria-live="polite"[^>]*data-ysai-status/);
  assert.match(template, /role="log"[^>]*aria-live="polite"[^>]*aria-relevant="additions"/);
  assert.match(template, /role="alert"[^>]*data-ysai-error/);
  const panelClose = template.indexOf('</section>');
  const announcer = template.indexOf('data-ysai-announcer');
  assert.ok(panelClose >= 0 && announcer > panelClose, 'the minimized-widget live region must remain outside the hidden panel');
  assert.match(template, /<label[^>]*for="ysai-message"/);
  assert.match(template, /<textarea id="ysai-message"[\s\S]{0,500}data-ysai-input/);
  const suggestions = template.match(/<div\b[^>]*ysai-suggestions[^>]*>/)?.[0] || '';
  assert.match(suggestions, /role="group"/);
  assert.match(suggestions, /aria-label=/);

  for (const opening of template.match(/<button\b[^>]*>/g) || []) {
    assert.match(opening, /\btype="(?:button|submit)"/, `button lacks explicit type: ${opening}`);
  }
  assert.doesNotMatch(template, /<img\s+src=""/);
  assert.match(template, /data-ysai-avatar-image/);
  assert.match(template, /tabindex="-1"[\s\S]{0,100}hidden[\s\S]{0,100}data-ysai-panel/);
});

test('widget script preserves focus, Escape dismissal, and list semantics', () => {
  assert.match(widget, /this\.openButton\.setAttribute\('aria-expanded', 'true'\)/);
  assert.match(widget, /this\.openButton\.setAttribute\('aria-expanded', 'false'\)/);
  assert.match(widget, /this\.openButton\.focus\(\{ preventScroll: true \}\)/);
  assert.match(widget, /event\.key === 'Escape'/);
  assert.match(widget, /event\.key === 'Tab'/);
  assert.match(widget, /this\.panel\.setAttribute\('aria-modal', 'true'\)/);
  assert.match(widget, /sibling\.setAttribute\('inert', ''\)/);
  assert.match(widget, /this\.input\?\.focus\(\{ preventScroll: true \}\)/);
  assert.match(widget, /list\.setAttribute\('role', 'list'\)/);
  assert.match(widget, /card\.setAttribute\('role', 'listitem'\)/);
  assert.match(widget, /ask\.setAttribute\('aria-label', namedLabel\)/);
  assert.match(widget, /this\.openButton\.setAttribute\([\s\S]*'aria-label'/);
  assert.match(widget, /this\.lifecycleActive\(lifecycle\)/);
});

test('widget styles retain visible focus, screen-reader text, and reduced-motion behavior', () => {
  assert.match(css, /:focus-visible\{/);
  assert.match(css, /\.screen-reader-text\{/);
  assert.match(css, /@media\(prefers-reduced-motion:reduce\)/);
  assert.match(css, /outline:[^;]+;/);
  assert.match(css, /--ysai-viewport-width/);
  assert.match(css, /--ysai-viewport-offset-left/);
});
