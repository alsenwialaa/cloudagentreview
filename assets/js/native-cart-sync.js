const DEFAULT_MAX_HTML_BYTES = 786432;
const DEFAULT_RETRY_DELAYS = Object.freeze([0, 350, 1200]);
const MAX_SEEN_KEYS = 32;
const UUID_V4 = /^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i;
const CLASSIC_MARKER = 'form.woocommerce-cart-form, .wc-empty-cart-message, .cart-empty.woocommerce-info, .cart-empty';
const BLOCK_SURFACE = '.wp-block-woocommerce-cart, .wp-block-woocommerce-checkout, .wc-block-mini-cart';
const CHECKOUT_SURFACE = 'form.checkout.woocommerce-checkout';
const BLOCKED_ELEMENTS = 'script, style, link, iframe, frame, object, embed, applet, base, meta, template, noscript, svg, math, audio, video, source, track';
const URL_ATTRIBUTES = new Set(['action', 'background', 'href', 'poster', 'src', 'xlink:href']);
const DROP_ATTRIBUTES = new Set([
  'autofocus',
  'contenteditable',
  'crossorigin',
  'form',
  'formaction',
  'formenctype',
  'formmethod',
  'formtarget',
  'integrity',
  'is',
  'nonce',
  'ping',
  'popover',
  'popovertarget',
  'referrerpolicy',
  'srcdoc',
  'srcset',
  'style',
  'target',
  'xml:base',
  'command',
  'commandfor',
]);

function boundedInteger(value, minimum, maximum) {
  return Number.isSafeInteger(value) && value >= minimum && value <= maximum;
}

function errorWithCode(code, message) {
  const error = new Error(message);
  error.code = code;
  return error;
}

export function safeSameOriginUrl(value, baseHref) {
  const source = typeof value === 'string' ? value.trim() : '';
  const base = typeof baseHref === 'string' ? baseHref.trim() : '';
  if (source === '' || source.length > 2048 || base === '') return null;
  try {
    const baseUrl = new URL(base);
    const candidate = new URL(source, baseUrl);
    if (!['http:', 'https:'].includes(baseUrl.protocol)
      || !['http:', 'https:'].includes(candidate.protocol)
      || candidate.origin !== baseUrl.origin
      || candidate.username !== ''
      || candidate.password !== '') {
      return null;
    }
    return candidate.href;
  } catch (error) {
    return null;
  }
}

export function presentationKey(receipt, cart) {
  if (!receipt || typeof receipt !== 'object' || !UUID_V4.test(String(receipt.id || ''))
    || !cart || typeof cart !== 'object') {
    return '';
  }
  const hash = typeof cart.cart_hash === 'string' && cart.cart_hash.length <= 256
    ? cart.cart_hash
    : '';
  const itemCount = boundedInteger(cart.item_count, 0, 2_000_000_000) ? cart.item_count : -1;
  const lineCount = boundedInteger(cart.line_count, 0, 100_000) ? cart.line_count : -1;
  if (itemCount < 0 || lineCount < 0) return '';
  return `${String(receipt.id).toLowerCase()}:${hash}:${itemCount}:${lineCount}`;
}

function declaredLength(response) {
  const raw = String(response?.headers?.get?.('content-length') || '').trim();
  if (raw === '') return null;
  if (!/^(?:0|[1-9][0-9]*)$/.test(raw)) {
    throw errorWithCode('invalid_content_length', 'The cart page length header is invalid.');
  }
  const value = Number(raw);
  if (!Number.isSafeInteger(value)) {
    throw errorWithCode('invalid_content_length', 'The cart page length header is invalid.');
  }
  return value;
}

function htmlContentType(response) {
  const value = String(response?.headers?.get?.('content-type') || '').toLowerCase();
  const mediaType = value.split(';', 1)[0].trim();
  return mediaType === 'text/html' || mediaType === 'application/xhtml+xml';
}

export async function readBoundedUtf8Html(response, {
  maxBytes = DEFAULT_MAX_HTML_BYTES,
  abort = null,
} = {}) {
  if (!boundedInteger(maxBytes, 1024, 2_097_152)) {
    throw errorWithCode('invalid_limit', 'The cart page response limit is invalid.');
  }
  if (!response || response.ok !== true) {
    throw errorWithCode('http_error', 'The cart page could not be read.');
  }
  if (!htmlContentType(response)) {
    throw errorWithCode('invalid_content_type', 'The cart page response is not HTML.');
  }
  const expected = declaredLength(response);
  if (expected !== null && expected > maxBytes) {
    if (typeof abort === 'function') abort();
    throw errorWithCode('response_too_large', 'The cart page response is too large.');
  }

  const decoder = new TextDecoder('utf-8', { fatal: true });
  const chunks = [];
  let bytes = 0;
  const reader = response.body?.getReader?.();
  try {
    if (reader) {
      while (true) {
        const row = await reader.read();
        if (row.done) break;
        if (!(row.value instanceof Uint8Array)) {
          throw errorWithCode('invalid_body', 'The cart page response body is invalid.');
        }
        bytes += row.value.byteLength;
        if (bytes > maxBytes) {
          if (typeof abort === 'function') abort();
          try { await reader.cancel(); } catch (error) { /* Best-effort stream cleanup. */ }
          throw errorWithCode('response_too_large', 'The cart page response is too large.');
        }
        chunks.push(decoder.decode(row.value, { stream: true }));
      }
      chunks.push(decoder.decode());
    } else if (typeof response.arrayBuffer === 'function') {
      const buffer = await response.arrayBuffer();
      bytes = buffer.byteLength;
      if (bytes > maxBytes) {
        if (typeof abort === 'function') abort();
        throw errorWithCode('response_too_large', 'The cart page response is too large.');
      }
      chunks.push(decoder.decode(buffer));
    } else {
      throw errorWithCode('invalid_body', 'The cart page response body is unavailable.');
    }
  } catch (error) {
    if (['invalid_body', 'response_too_large'].includes(String(error?.code || ''))) throw error;
    throw errorWithCode('invalid_utf8', 'The cart page response is not valid UTF-8.');
  } finally {
    try { reader?.releaseLock?.(); } catch (error) { /* Best-effort stream cleanup. */ }
  }

  const html = chunks.join('');
  if (html.trim() === '') {
    throw errorWithCode('empty_response', 'The cart page response is empty.');
  }
  return html;
}

function allElements(root) {
  const descendants = typeof root?.querySelectorAll === 'function'
    ? Array.from(root.querySelectorAll('*'))
    : [];
  return root ? [root, ...descendants] : descendants;
}

export function sanitizeImportedTree(root, baseHref) {
  const safeBase = safeSameOriginUrl(baseHref, baseHref);
  if (!root || !safeBase) return false;

  const rootName = String(root?.localName || root?.tagName || '').toLowerCase();
  if (rootName.includes('-') || BLOCKED_ELEMENTS.split(',').map((name) => name.trim()).includes(rootName)) {
    return false;
  }

  if (typeof root.querySelectorAll === 'function') {
    for (const element of Array.from(root.querySelectorAll(BLOCKED_ELEMENTS))) {
      try { element.remove(); } catch (error) { /* Detached nodes are already inert. */ }
    }
  }

  const elements = allElements(root);
  if (elements.length > 5000) return false;
  for (const element of elements) {
    const tagName = String(element?.localName || element?.tagName || '').toLowerCase();
    if (tagName.includes('-')) {
      try { element.remove?.(); } catch (error) { /* Detached custom elements are already inert. */ }
      continue;
    }
    const attributes = Array.from(element?.attributes || []);
    for (const attribute of attributes) {
      const name = String(attribute?.name || '').toLowerCase();
      const value = String(attribute?.value || '');
      if (name === '' || name.startsWith('on') || DROP_ATTRIBUTES.has(name)
        || (name.startsWith('data-') && /(?:src|href|url|action|html)/.test(name))) {
        element.removeAttribute?.(attribute.name);
        continue;
      }
      if (!URL_ATTRIBUTES.has(name)) continue;
      const normalized = safeSameOriginUrl(value, safeBase);
      if (!normalized) {
        element.removeAttribute?.(attribute.name);
      } else {
        element.setAttribute?.(attribute.name, normalized);
      }
    }
  }
  return true;
}

function classicMarker(documentObject) {
  return documentObject?.querySelector?.(CLASSIC_MARKER) || null;
}

function classicContainer(documentObject) {
  const marker = classicMarker(documentObject);
  if (!marker) return null;
  return marker.closest?.('.woocommerce')
    || documentObject?.querySelector?.('main .woocommerce, .site-main .woocommerce, #primary .woocommerce, .entry-content .woocommerce, .woocommerce')
    || null;
}

function hasSurface(documentObject, selector) {
  return Boolean(documentObject?.querySelector?.(selector));
}

export class NativeCartSynchronizer {
  constructor({
    windowObject = globalThis.window,
    documentObject = windowObject?.document,
    fetchImpl = typeof windowObject?.fetch === 'function' ? windowObject.fetch.bind(windowObject) : null,
    setTimeoutFn = typeof windowObject?.setTimeout === 'function' ? windowObject.setTimeout.bind(windowObject) : globalThis.setTimeout,
    clearTimeoutFn = typeof windowObject?.clearTimeout === 'function' ? windowObject.clearTimeout.bind(windowObject) : globalThis.clearTimeout,
    retryDelays = DEFAULT_RETRY_DELAYS,
    maxHtmlBytes = DEFAULT_MAX_HTML_BYTES,
  } = {}) {
    this.window = windowObject || null;
    this.document = documentObject || null;
    this.fetch = fetchImpl;
    this.setTimeout = setTimeoutFn;
    this.clearTimeout = clearTimeoutFn;
    this.retryDelays = Array.isArray(retryDelays)
      ? retryDelays.filter((value) => boundedInteger(value, 0, 5000)).slice(0, 4)
      : [...DEFAULT_RETRY_DELAYS];
    if (this.retryDelays.length === 0) this.retryDelays = [...DEFAULT_RETRY_DELAYS];
    this.maxHtmlBytes = boundedInteger(maxHtmlBytes, 1024, 2_097_152)
      ? maxHtmlBytes
      : DEFAULT_MAX_HTML_BYTES;
    this.seen = new Map();
    this.timers = new Set();
    this.controllers = new Set();
    this.classicCompleted = new Set();
    this.generation = 0;
    this.destroyed = false;
    this.serial = Promise.resolve();
  }

  converge(receipt, cart) {
    const key = presentationKey(receipt, cart);
    if (this.destroyed || key === '' || this.seen.has(key)) return false;

    this.remember(key);
    this.cancelPending();
    const generation = ++this.generation;
    for (const [index, delay] of this.retryDelays.entries()) {
      const timer = this.setTimeout(() => {
        this.timers.delete(timer);
        if (this.destroyed || generation !== this.generation) return;
        const run = () => this.attempt(cart, key, generation, index);
        this.serial = this.serial.then(run, run).catch(() => undefined);
      }, delay);
      this.timers.add(timer);
    }
    return true;
  }

  remember(key) {
    this.seen.set(key, true);
    while (this.seen.size > MAX_SEEN_KEYS) {
      const oldest = this.seen.keys().next().value;
      this.seen.delete(oldest);
      this.classicCompleted.delete(oldest);
    }
  }

  cancelPending() {
    for (const timer of this.timers) this.clearTimeout(timer);
    this.timers.clear();
    for (const controller of this.controllers) {
      try { controller.abort(); } catch (error) { /* Best-effort cancellation. */ }
    }
    this.controllers.clear();
  }

  async attempt(cart, key, generation, attemptIndex) {
    if (this.destroyed || generation !== this.generation) return;
    this.requestWooRefreshes(cart);
    if (attemptIndex > 0 && this.classicCompleted.has(key)) return;
    try {
      const refreshed = await this.refreshClassicCart(cart, generation);
      if (refreshed) this.classicCompleted.add(key);
    } catch (error) {
      // Native surfaces are presentation-only. A failure must never repeat or
      // reinterpret the already-verified cart mutation.
    }
  }

  jqueryBody() {
    if (typeof this.window?.jQuery !== 'function' || !this.document?.body) return null;
    try { return this.window.jQuery(this.document.body); } catch (error) { return null; }
  }

  triggerJquery(eventName) {
    const body = this.jqueryBody();
    if (!body || typeof body.trigger !== 'function') return false;
    try {
      body.trigger(eventName);
      return true;
    } catch (error) {
      return false;
    }
  }

  requestWooRefreshes(cart) {
    this.triggerJquery('wc_fragment_refresh');
    if (hasSurface(this.document, CHECKOUT_SURFACE)) this.triggerJquery('update_checkout');

    const body = this.document?.body;
    if (body?.dispatchEvent && typeof this.window?.CustomEvent === 'function') {
      try {
        body.dispatchEvent(new this.window.CustomEvent('ysai:cart-presentation-refresh', {
          bubbles: true,
          detail: Object.freeze({
            itemCount: boundedInteger(cart?.item_count, 0, 2_000_000_000) ? cart.item_count : 0,
            cartHash: typeof cart?.cart_hash === 'string' ? cart.cart_hash : '',
          }),
        }));
      } catch (error) { /* Compatibility notification only. */ }
    }

    if (!hasSurface(this.document, BLOCK_SURFACE)) return;
    const data = this.window?.wp?.data;
    const descriptor = this.window?.wc?.wcBlocksData?.cartStore || 'wc/store/cart';
    let invalidated = false;
    if (data && typeof data.dispatch === 'function') {
      try {
        const dispatcher = data.dispatch(descriptor);
        if (typeof dispatcher?.invalidateResolutionForStore === 'function') {
          dispatcher.invalidateResolutionForStore();
          invalidated = true;
        } else if (typeof dispatcher?.invalidateResolution === 'function') {
          dispatcher.invalidateResolution('getCartData');
          dispatcher.invalidateResolution('getCart');
          invalidated = true;
        }
      } catch (error) { /* Fall through to the compatibility event. */ }
    }
    if (body?.dispatchEvent && typeof this.window?.CustomEvent === 'function') {
      try {
        body.dispatchEvent(new this.window.CustomEvent('wc-blocks_added_to_cart', {
          bubbles: true,
          cancelable: true,
          detail: { preserveCartData: false, source: 'yassin-ai-assistant' },
        }));
      } catch (error) { /* Compatibility event only. */ }
    }
    return invalidated;
  }

  async refreshClassicCart(cart, generation) {
    const current = classicContainer(this.document);
    if (!current || typeof this.fetch !== 'function' || typeof this.window?.DOMParser !== 'function') {
      return false;
    }
    const requestedUrl = safeSameOriginUrl(cart?.cart_url, this.window?.location?.href || '');
    if (!requestedUrl) return false;

    const controller = typeof this.window?.AbortController === 'function'
      ? new this.window.AbortController()
      : (typeof AbortController === 'function' ? new AbortController() : null);
    if (controller) this.controllers.add(controller);
    try {
      const response = await this.fetch(requestedUrl, {
        method: 'GET',
        mode: 'same-origin',
        credentials: 'same-origin',
        cache: 'no-store',
        redirect: 'follow',
        referrerPolicy: 'same-origin',
        headers: { Accept: 'text/html,application/xhtml+xml;q=0.9' },
        signal: controller?.signal,
      });
      if (this.destroyed || generation !== this.generation) return false;
      const finalUrl = safeSameOriginUrl(response?.url || requestedUrl, this.window?.location?.href || '');
      if (!finalUrl) return false;
      const html = await readBoundedUtf8Html(response, {
        maxBytes: this.maxHtmlBytes,
        abort: () => controller?.abort?.(),
      });
      if (this.destroyed || generation !== this.generation) return false;

      const parsed = new this.window.DOMParser().parseFromString(html, 'text/html');
      const incoming = classicContainer(parsed);
      const live = classicContainer(this.document);
      if (!incoming || !live || typeof live.replaceWith !== 'function') return false;

      // Sanitize while the tree is still detached in the parser document.
      // This prevents custom elements or active attributes from crossing the
      // import boundary before they have been removed. Sanitize the imported
      // copy again as a defense against DOM implementation differences.
      if (!sanitizeImportedTree(incoming, finalUrl)) return false;
      const imported = typeof this.document?.importNode === 'function'
        ? this.document.importNode(incoming, true)
        : incoming.cloneNode?.(true);
      if (!imported || !sanitizeImportedTree(imported, finalUrl)) return false;
      if (this.destroyed || generation !== this.generation) return false;
      live.replaceWith(imported);
      this.triggerJquery('updated_wc_div');
      return true;
    } finally {
      if (controller) this.controllers.delete(controller);
    }
  }

  destroy() {
    if (this.destroyed) return;
    this.destroyed = true;
    this.generation += 1;
    this.cancelPending();
    this.seen.clear();
    this.classicCompleted.clear();
  }
}
