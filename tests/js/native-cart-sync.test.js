import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { createHash } from 'node:crypto';

import {
  NativeCartSynchronizer,
  presentationKey,
  readBoundedUtf8Html,
  safeSameOriginUrl,
  sanitizeImportedTree,
} from '../../assets/js/native-cart-sync.js';

const RECEIPT_ID = '223e4567-e89b-42d3-a456-426614174000';

function cart(overrides = {}) {
  return {
    item_count: 2,
    line_count: 1,
    cart_hash: 'abc123',
    cart_url: 'https://shop.example.test/cart/',
    ...overrides,
  };
}

function receipt(overrides = {}) {
  return { id: RECEIPT_ID, ...overrides };
}

function fakeTimers() {
  let nextId = 1;
  const pending = new Map();
  return {
    pending,
    setTimeout(callback, delay) {
      const id = nextId++;
      pending.set(id, { callback, delay });
      return id;
    },
    clearTimeout(id) {
      pending.delete(id);
    },
    runAll() {
      const rows = [...pending.entries()].sort((a, b) => a[1].delay - b[1].delay);
      pending.clear();
      for (const [, row] of rows) row.callback();
    },
  };
}

class FakeElement {
  constructor(tagName, attributes = {}, children = []) {
    this.tagName = String(tagName).toLowerCase();
    this.parent = null;
    this.removed = false;
    this.attributes = Object.entries(attributes).map(([name, value]) => ({ name, value: String(value) }));
    this.children = children;
    for (const child of children) child.parent = this;
  }

  descendants() {
    return this.children.flatMap((child) => [child, ...child.descendants()]).filter((child) => !child.removed);
  }

  querySelectorAll(selector) {
    const rows = this.descendants();
    if (selector === '*') return rows;
    const blocked = new Set(selector.split(',').map((value) => value.trim().toLowerCase()));
    return rows.filter((row) => blocked.has(row.tagName));
  }

  remove() {
    this.removed = true;
    if (this.parent) this.parent.children = this.parent.children.filter((child) => child !== this);
  }

  removeAttribute(name) {
    const lower = String(name).toLowerCase();
    this.attributes = this.attributes.filter((attribute) => attribute.name.toLowerCase() !== lower);
  }

  setAttribute(name, value) {
    this.removeAttribute(name);
    this.attributes.push({ name, value: String(value) });
  }

  attribute(name) {
    const lower = String(name).toLowerCase();
    return this.attributes.find((attribute) => attribute.name.toLowerCase() === lower)?.value;
  }
}

test('same-origin cart URLs reject foreign origins, credentials, unsafe schemes, and oversized inputs', () => {
  const base = 'https://shop.example.test/staging/product/';
  assert.equal(
    safeSameOriginUrl('/staging/cart/?a=1', base),
    'https://shop.example.test/staging/cart/?a=1',
  );
  assert.equal(safeSameOriginUrl('https://evil.example/cart', base), null);
  assert.equal(safeSameOriginUrl('javascript:alert(1)', base), null);
  assert.equal(safeSameOriginUrl('https://user:pass@shop.example.test/cart', base), null);
  assert.equal(safeSameOriginUrl('a'.repeat(2049), base), null);
});

test('presentation keys require a verified receipt identity and bounded cart revision facts', () => {
  assert.equal(
    presentationKey(receipt(), cart()),
    `${RECEIPT_ID}:abc123:2:1`,
  );
  assert.equal(presentationKey({ id: 'not-a-receipt' }, cart()), '');
  assert.equal(presentationKey(receipt(), cart({ item_count: -1 })), '');
  assert.notEqual(
    presentationKey(receipt(), cart()),
    presentationKey(receipt(), cart({ cart_hash: 'new-hash' })),
  );
});

test('bounded HTML reads enforce success, HTML media type, byte limits, and fatal UTF-8 decoding', async () => {
  const html = await readBoundedUtf8Html(new Response('<html><body>سلة</body></html>', {
    status: 200,
    headers: { 'Content-Type': 'text/html; charset=UTF-8' },
  }), { maxBytes: 4096 });
  assert.match(html, /سلة/);

  let aborted = false;
  await assert.rejects(
    readBoundedUtf8Html(new Response('<p>x</p>', {
      status: 200,
      headers: { 'Content-Type': 'text/html', 'Content-Length': '9000' },
    }), { maxBytes: 4096, abort: () => { aborted = true; } }),
    (error) => error?.code === 'response_too_large',
  );
  assert.equal(aborted, true);

  await assert.rejects(
    readBoundedUtf8Html(new Response('{}', {
      status: 200,
      headers: { 'Content-Type': 'application/json' },
    }), { maxBytes: 4096 }),
    (error) => error?.code === 'invalid_content_type',
  );

  await assert.rejects(
    readBoundedUtf8Html(new Response(new Uint8Array([0xC3, 0x28]), {
      status: 200,
      headers: { 'Content-Type': 'text/html' },
    }), { maxBytes: 4096 }),
    (error) => error?.code === 'invalid_utf8',
  );

  const stream = new ReadableStream({
    start(controller) {
      controller.enqueue(new Uint8Array(3000));
      controller.enqueue(new Uint8Array(3000));
      controller.close();
    },
  });
  await assert.rejects(
    readBoundedUtf8Html(new Response(stream, {
      status: 200,
      headers: { 'Content-Type': 'text/html' },
    }), { maxBytes: 4096 }),
    (error) => error?.code === 'response_too_large',
  );
});

test('import sanitization covers the root and descendants and removes executable or foreign content', () => {
  const script = new FakeElement('script', { src: '/payload.js' });
  const stylesheet = new FakeElement('link', { rel: 'stylesheet', href: '/payload.css' });
  const custom = new FakeElement('shop-cart-hook', { commandfor: 'checkout' });
  const tableCell = new FakeElement('td', { background: '/media/tile.png', 'xml:base': '/other/' });
  const image = new FakeElement('img', {
    src: '/media/product.jpg',
    onerror: 'steal()',
    srcset: 'https://evil.example/a 2x',
    'data-lazy-src': 'https://evil.example/lazy.jpg',
  });
  const link = new FakeElement('a', {
    href: 'https://evil.example/cart',
    onclick: 'steal()',
    target: '_blank',
  });
  const root = new FakeElement('div', {
    onload: 'steal()',
    style: 'background:url(javascript:alert(1))',
    'data-product-id': '12',
  }, [script, stylesheet, custom, tableCell, image, link]);

  assert.equal(sanitizeImportedTree(root, 'https://shop.example.test/cart/'), true);
  assert.equal(script.removed, true);
  assert.equal(stylesheet.removed, true);
  assert.equal(custom.removed, true);
  assert.equal(root.attribute('onload'), undefined);
  assert.equal(root.attribute('style'), undefined);
  assert.equal(root.attribute('data-product-id'), '12');
  assert.equal(image.attribute('onerror'), undefined);
  assert.equal(image.attribute('srcset'), undefined);
  assert.equal(image.attribute('data-lazy-src'), undefined);
  assert.equal(image.attribute('src'), 'https://shop.example.test/media/product.jpg');
  assert.equal(tableCell.attribute('background'), 'https://shop.example.test/media/tile.png');
  assert.equal(tableCell.attribute('xml:base'), undefined);
  assert.equal(link.attribute('href'), undefined);
  assert.equal(link.attribute('onclick'), undefined);
  assert.equal(link.attribute('target'), undefined);
});

test('import sanitization rejects blocked or custom roots and pathological node counts', () => {
  assert.equal(sanitizeImportedTree(new FakeElement('script'), 'https://shop.example.test/cart/'), false);
  assert.equal(sanitizeImportedTree(new FakeElement('shop-cart-hook'), 'https://shop.example.test/cart/'), false);
  const children = Array.from({ length: 5000 }, () => new FakeElement('span'));
  assert.equal(
    sanitizeImportedTree(new FakeElement('div', {}, children), 'https://shop.example.test/cart/'),
    false,
  );
});

test('verified receipts schedule a bounded, deduplicated fan-out to classic, checkout, and Blocks refresh APIs', async () => {
  const timers = fakeTimers();
  const jqueryEvents = [];
  const domEvents = [];
  let invalidations = 0;
  const body = {
    dispatchEvent(event) {
      domEvents.push(event.type);
      return true;
    },
  };
  const documentObject = {
    body,
    querySelector(selector) {
      if (selector.includes('checkout')) return {};
      if (selector.includes('wp-block-woocommerce')) return {};
      return null;
    },
  };
  const windowObject = {
    location: { href: 'https://shop.example.test/product/' },
    document: documentObject,
    setTimeout: timers.setTimeout,
    clearTimeout: timers.clearTimeout,
    CustomEvent: class CustomEvent {
      constructor(type, options = {}) {
        this.type = type;
        this.detail = options.detail;
      }
    },
    jQuery() {
      return { trigger: (name) => jqueryEvents.push(name) };
    },
    wp: {
      data: {
        dispatch() {
          return { invalidateResolutionForStore: () => { invalidations += 1; } };
        },
      },
    },
  };
  const sync = new NativeCartSynchronizer({
    windowObject,
    documentObject,
    setTimeoutFn: timers.setTimeout,
    clearTimeoutFn: timers.clearTimeout,
    retryDelays: [0, 10, 20],
  });

  assert.equal(sync.converge(receipt(), cart()), true);
  assert.equal(sync.converge(receipt(), cart()), false, 'the same verified receipt/revision must be deduplicated');
  assert.equal(timers.pending.size, 3);
  timers.runAll();
  await Promise.resolve();
  await Promise.resolve();
  await sync.serial;

  assert.equal(jqueryEvents.filter((name) => name === 'wc_fragment_refresh').length, 3);
  assert.equal(jqueryEvents.filter((name) => name === 'update_checkout').length, 3);
  assert.equal(invalidations, 3);
  assert.equal(domEvents.filter((name) => name === 'wc-blocks_added_to_cart').length, 3);
  assert.equal(domEvents.filter((name) => name === 'ysai:cart-presentation-refresh').length, 3);

  sync.destroy();
});

test('newer verified revisions supersede pending retries and teardown cancels all presentation work', () => {
  const timers = fakeTimers();
  const sync = new NativeCartSynchronizer({
    windowObject: {
      location: { href: 'https://shop.example.test/' },
      document: { body: null, querySelector: () => null },
      setTimeout: timers.setTimeout,
      clearTimeout: timers.clearTimeout,
    },
    documentObject: { body: null, querySelector: () => null },
    setTimeoutFn: timers.setTimeout,
    clearTimeoutFn: timers.clearTimeout,
    retryDelays: [0, 10, 20],
  });

  assert.equal(sync.converge(receipt(), cart()), true);
  assert.equal(timers.pending.size, 3);
  assert.equal(sync.converge({ id: '323e4567-e89b-42d3-a456-426614174000' }, cart({ cart_hash: 'new' })), true);
  assert.equal(timers.pending.size, 3, 'the newer revision must cancel the older retry set');
  sync.destroy();
  assert.equal(timers.pending.size, 0);
  assert.equal(sync.converge(receipt(), cart({ cart_hash: 'after-destroy' })), false);
});

test('classic cart refresh never reads a foreign cart URL', async () => {
  const live = { replaceWith() {} };
  const marker = { closest: () => live };
  const documentObject = {
    body: {},
    querySelector(selector) {
      return selector.includes('woocommerce-cart-form') ? marker : null;
    },
  };
  let fetches = 0;
  const sync = new NativeCartSynchronizer({
    windowObject: {
      location: { href: 'https://shop.example.test/product/' },
      document: documentObject,
      DOMParser: class {},
      setTimeout,
      clearTimeout,
    },
    documentObject,
    fetchImpl: async () => { fetches += 1; return null; },
    retryDelays: [0],
  });
  await sync.refreshClassicCart(cart({ cart_url: 'https://evil.example/cart/' }), sync.generation);
  assert.equal(fetches, 0);
  sync.destroy();
});

test('classic cart refresh imports one sanitized same-origin cart container and emits the native completion event', async () => {
  const jqueryEvents = [];
  let replacement = null;
  let request = null;
  let sanitizedBeforeImport = false;
  const live = { replaceWith(value) { replacement = value; } };
  const liveMarker = { closest: () => live };
  const incoming = new FakeElement('div', { class: 'woocommerce', onload: 'run()' }, [
    new FakeElement('script', { src: '/payload.js' }),
    new FakeElement('a', { href: '/checkout/', onclick: 'run()' }),
  ]);
  const incomingMarker = { closest: () => incoming };
  const parsedDocument = {
    querySelector(selector) {
      return selector.includes('woocommerce-cart-form') ? incomingMarker : null;
    },
  };
  const documentObject = {
    body: {},
    querySelector(selector) {
      return selector.includes('woocommerce-cart-form') ? liveMarker : null;
    },
    importNode(node) {
      sanitizedBeforeImport = node.attribute('onload') === undefined
        && node.children.some((child) => child.tagName === 'script') === false;
      return node;
    },
  };
  const windowObject = {
    location: { href: 'https://shop.example.test/product/' },
    document: documentObject,
    DOMParser: class DOMParser {
      parseFromString() { return parsedDocument; }
    },
    jQuery() {
      return { trigger: (name) => jqueryEvents.push(name) };
    },
    setTimeout,
    clearTimeout,
  };
  const sync = new NativeCartSynchronizer({
    windowObject,
    documentObject,
    fetchImpl: async (url, options) => {
      request = { url, options };
      return new Response('<html><body>cart</body></html>', {
        status: 200,
        headers: { 'Content-Type': 'text/html; charset=UTF-8' },
      });
    },
    retryDelays: [0],
  });

  assert.equal(await sync.refreshClassicCart(cart(), sync.generation), true);
  assert.equal(request.url, 'https://shop.example.test/cart/');
  assert.equal(request.options.method, 'GET');
  assert.equal(request.options.mode, 'same-origin');
  assert.equal(request.options.credentials, 'same-origin');
  assert.equal(request.options.cache, 'no-store');
  assert.equal(request.options.referrerPolicy, 'same-origin');
  assert.equal(sanitizedBeforeImport, true);
  assert.equal(replacement, incoming);
  assert.equal(incoming.attribute('onload'), undefined);
  assert.equal(incoming.children.some((child) => child.tagName === 'script'), false);
  assert.equal(incoming.children[0].attribute('onclick'), undefined);
  assert.equal(incoming.children[0].attribute('href'), 'https://shop.example.test/checkout/');
  assert.deepEqual(jqueryEvents, ['updated_wc_div']);
  sync.destroy();
});

test('widget integration invokes native convergence only for validated cart receipts and destroys it with the widget', () => {
  const widget = readFileSync(new URL('../../assets/js/widget.js', import.meta.url), 'utf8');
  const synchronizer = readFileSync(new URL('../../assets/js/native-cart-sync.js', import.meta.url), 'utf8');
  const moduleRevision = createHash('sha256').update(synchronizer).digest('hex').slice(0, 12);
  assert.ok(widget.includes(`import { NativeCartSynchronizer } from './native-cart-sync.js?ver=2.5.4.${moduleRevision}';`));
  assert.match(widget, /response\.kind === 'cart_receipt'[\s\S]{0,180}nativeCartSynchronizer\.converge\(response\.receipt, response\.cart\)/);
  assert.match(widget, /nativeCartSynchronizer\?\.destroy\(\)/);
  assert.doesNotMatch(widget, /renderCart\([^)]*\)[\s\S]{0,80}nativeCartSynchronizer\.converge/);

  assert.doesNotMatch(synchronizer, /\/turn|cart_apply|cart_replace|cart_clear|method:\s*'POST'/);

  const storefront = readFileSync(new URL('../../src/Presentation/Storefront/StorefrontWidget.php', import.meta.url), 'utf8');
  assert.match(storefront, /assetVersion\('assets\/js\/widget\.js'\)/);
  assert.match(storefront, /filemtime\(\$path\)/);
});
