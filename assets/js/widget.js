import {
  clampRequestTimeout,
  classifyTurnError,
  createTurnId,
  errorConfirmsConversationUnauthorized,
  isAbortError,
  normalizeDurablePending,
  normalizeRestBase,
  newTurnAction,
  operationLocks,
  pendingForDurableStorage,
  pendingRequiresImageReattach,
  readBoundedJsonResponse,
  ResilientJsonStore,
  safeHttpUrl,
  sameConversation,
  selectBootCredentials,
  truncateUnicode,
  turnRecoveryAction,
  unicodeLength,
  validateBootResponse,
  validateDeleteResponse,
  validateExportPage,
  validateTurnResponse,
} from './client-utils.js?ver=2.5.4';
import { NativeCartSynchronizer } from './native-cart-sync.js?ver=2.5.4.58504ade71fc';

const config = window.YSAIAssistantConfig || {};

class ApiError extends Error {
  constructor(message, {
    status = 0,
    code = 'network_error',
    retryable = true,
    retryMode = retryable ? 'same_turn' : 'none',
    retryAfterSeconds = 0,
    payload = null,
  } = {}) {
    super(message);
    this.name = 'ApiError';
    this.status = status;
    this.code = code;
    this.retryable = retryable;
    this.retryMode = ['none', 'same_turn', 'new_turn'].includes(retryMode)
      ? retryMode
      : (retryable ? 'same_turn' : 'none');
    this.retryAfterSeconds = Number.isSafeInteger(retryAfterSeconds)
      && retryAfterSeconds >= 1
      && retryAfterSeconds <= 86400
      ? retryAfterSeconds
      : 0;
    this.payload = payload;
  }
}

class ApiClient {
  constructor(base, timeoutMs = 180000) {
    this.base = normalizeRestBase(base);
    this.timeoutMs = clampRequestTimeout(timeoutMs);
  }

  async post(path, body) {
    const controller = typeof AbortController === 'function' ? new AbortController() : null;
    const timeout = controller ? window.setTimeout(() => controller.abort(), this.timeoutMs) : null;
    try {
      let response;
      try {
        response = await fetch(this.base + String(path).replace(/^\/+/, ''), {
          method: 'POST',
          credentials: 'same-origin',
          cache: 'no-store',
          headers: {
            'Content-Type': 'application/json',
            Accept: 'application/json',
            'X-YSAI-Client-Contract': '2',
          },
          body: JSON.stringify(body || {}),
          signal: controller?.signal,
        });
      } catch (error) {
        const timedOut = isAbortError(error);
        throw new ApiError(
          timedOut
            ? (config.texts?.timeout || config.texts?.genericError || 'Request timed out')
            : (config.texts?.genericError || 'Network error'),
          {
            status: 0,
            code: timedOut ? 'request_timeout' : 'network_error',
            retryable: true,
            retryMode: 'same_turn',
            payload: null,
          },
        );
      }

      let payload = null;
      try {
        payload = await readBoundedJsonResponse(response);
      } catch (error) {
        throw new ApiError(config.texts?.genericError || 'Invalid server response', {
          status: response.status,
          code: String(error?.code || '') === 'response_too_large'
            ? 'response_too_large'
            : 'invalid_response',
          retryable: response.status >= 500,
          retryMode: 'same_turn',
        });
      }

      if (!response.ok || payload?.ok === false) {
        throw new ApiError(payload?.error?.message || config.texts?.genericError || 'Request failed', {
          status: response.status,
          code: payload?.error?.code || 'request_failed',
          retryable: Boolean(payload?.error?.retryable),
          retryMode: String(payload?.error?.retry_mode || (
            payload?.error?.retryable ? 'same_turn' : 'none'
          )),
          retryAfterSeconds: Number(payload?.error?.retry_after_seconds || 0),
          payload,
        });
      }
      return payload;
    } finally {
      if (timeout !== null) window.clearTimeout(timeout);
    }
  }
}
class BrowserState {
  constructor(key) {
    this.key = key;
    this.pendingKey = `${key}.pending`;
    this.credentialsStore = new ResilientJsonStore(this.storage('localStorage'));
    this.pendingStore = new ResilientJsonStore(this.storage('sessionStorage'));
    this.pendingMemory = null;
  }

  credentials() {
    return this.credentialsStore.read(this.key);
  }

  saveCredentials(value) {
    this.credentialsStore.write(this.key, value);
  }

  clearCredentials() {
    this.credentialsStore.remove(this.key);
  }

  pending() {
    if (this.pendingMemory) return this.pendingMemory;
    const stored = this.pendingStore.read(this.pendingKey);
    const normalized = normalizeDurablePending(stored);
    if (!normalized) {
      if (stored) this.pendingStore.remove(this.pendingKey);
      return null;
    }
    // Rewrite legacy records immediately so image bytes and unknown fields
    // cannot remain in session storage after an upgrade. If an in-place write
    // fails, remove the oversized legacy value and retry with the small record;
    // ResilientJsonStore still keeps the normalized page-memory copy if browser
    // storage remains unavailable.
    if (!this.pendingStore.write(this.pendingKey, normalized)) {
      this.pendingStore.remove(this.pendingKey);
      this.pendingStore.write(this.pendingKey, normalized);
    }
    return normalized;
  }

  savePending(value) {
    const durable = pendingForDurableStorage(value);
    if (!durable) return false;
    this.pendingMemory = value;
    return this.pendingStore.write(this.pendingKey, durable);
  }

  clearPending() {
    this.pendingMemory = null;
    this.pendingStore.remove(this.pendingKey);
  }

  storage(name) {
    try {
      return window[name] || null;
    } catch (error) {
      return null;
    }
  }
}

class AssistantWidget {
  constructor(root) {
    this.root = root;
    this.api = new ApiClient(config.restBase, config.requestTimeoutMs);
    this.state = new BrowserState(config.storageKey || 'ysai.v2');
    this.nativeCartSynchronizer = new NativeCartSynchronizer();
    this.credentials = null;
    this.booted = false;
    this.connected = false;
    this.bootPromise = null;
    this.sending = false;
    this.recovering = false;
    this.exporting = false;
    this.deleting = false;
    this.reply = null;
    this.image = null;
    this.previewUrl = '';
    this.imageProcessing = false;
    this.imageGeneration = 0;
    this.renderedTurnIds = new Set();
    this.renderedClientTurnIds = new Set();
    this.renderingHistory = false;
    this.unreadCount = 0;
    this.lastMessageDay = '';
    this.lastMessageRole = '';
    this.lastMessageTimestamp = 0;
    this.destroyed = false;
    this.lifecycleGeneration = 1;
    this.openGeneration = 0;
    this.announcementTimer = 0;
    this.retryTimer = 0;
    this.cleanups = [];
    this.carouselCleanups = [];
    this.carouselControllers = new Map();
    this.objectUrls = new Set();
    this.pageIsolation = [];
    this.pageLock = null;
    this.modalActive = false;
    this.modalMedia = typeof window.matchMedia === 'function'
      ? window.matchMedia('(max-width: 640px), (max-height: 520px) and (max-width: 900px)')
      : null;

    this.panel = root.querySelector('[data-ysai-panel]');
    this.openButton = root.querySelector('[data-ysai-open]');
    this.launcherBaseLabel = String(this.openButton?.getAttribute('aria-label') || '');
    this.closeButton = root.querySelector('[data-ysai-close]');
    this.status = root.querySelector('[data-ysai-status]');
    this.statusText = root.querySelector('[data-ysai-status-text]');
    this.messages = root.querySelector('[data-ysai-messages]');
    this.empty = root.querySelector('[data-ysai-empty]');
    this.form = root.querySelector('[data-ysai-form]');
    this.input = root.querySelector('[data-ysai-input]');
    this.sendButton = root.querySelector('[data-ysai-send]');
    this.error = root.querySelector('[data-ysai-error]');
    this.cart = root.querySelector('[data-ysai-cart]');
    this.replyPreview = root.querySelector('[data-ysai-reply-preview]');
    this.replyText = root.querySelector('[data-ysai-reply-text]');
    this.imageInput = root.querySelector('[data-ysai-image-input]');
    this.imagePreview = root.querySelector('[data-ysai-image-preview]');
    this.imagePreviewImage = root.querySelector('[data-ysai-image-preview-img]');
    this.imageName = root.querySelector('[data-ysai-image-name]');
    this.exportButton = root.querySelector('[data-ysai-export]');
    this.deleteButton = root.querySelector('[data-ysai-delete]');
    this.privacyToggle = root.querySelector('[data-ysai-privacy-toggle]');
    this.privacyMenu = root.querySelector('[data-ysai-privacy-menu]');
    this.typing = root.querySelector('[data-ysai-typing]');
    this.latestButton = root.querySelector('[data-ysai-latest]');
    this.latestCount = root.querySelector('[data-ysai-latest-count]');
    this.launcherUnread = root.querySelector('[data-ysai-launcher-unread]');
    this.announcer = root.querySelector('[data-ysai-announcer]');
    this.replyMedia = root.querySelector('[data-ysai-reply-media]');
    this.replyImage = root.querySelector('[data-ysai-reply-image]');
    this.headerAvatar = root.querySelector('[data-ysai-header-avatar]');
    this.attachControl = root.querySelector('[data-ysai-attach]');
    this.characterCount = root.querySelector('[data-ysai-character-count]');

    this.bind();
    this.observeLifecycle();
    this.syncResponsivePanel();
    this.updateActionState();
    if (this.state.pending()) {
      this.openPanel();
    }
  }

  listen(target, type, handler, options = undefined) {
    if (!target?.addEventListener) return;
    target.addEventListener(type, handler, options);
    this.cleanups.push(() => target.removeEventListener(type, handler, options));
  }

  lifecycleToken() {
    return this.lifecycleGeneration;
  }

  lifecycleActive(token) {
    return !this.destroyed
      && token === this.lifecycleGeneration
      && this.root.isConnected;
  }

  bind() {
    this.listen(this.openButton, 'click', () => this.openPanel());
    this.listen(this.closeButton, 'click', () => this.closePanel());
    this.listen(this.form, 'submit', (event) => {
      event.preventDefault();
      this.submit();
    });
    this.listen(this.input, 'input', () => {
      this.resizeInput();
      this.updateActionState();
    });
    this.listen(this.input, 'keydown', (event) => {
      if (event.key === 'Enter' && !event.shiftKey && !event.isComposing) {
        event.preventDefault();
        if (this.state.pending()) {
          this.showError(config.texts?.processing || 'الطلب السابق ما زال قيد المعالجة.', () => this.retryPending());
          return;
        }
        if (!this.sendButton?.disabled) this.submit();
      }
    });
    this.root.querySelectorAll('[data-ysai-suggestion]').forEach((button) => {
      this.listen(button, 'click', () => {
        if (!this.input || this.input.disabled) return;
        this.input.value = button.dataset.ysaiSuggestion || '';
        this.resizeInput();
        this.updateActionState();
        this.input.focus();
      });
    });
    this.listen(this.root.querySelector('[data-ysai-reply-cancel]'), 'click', () => this.clearReply());
    this.listen(this.imageInput, 'change', (event) => this.selectImage(event.target.files?.[0] || null));
    this.listen(this.root.querySelector('[data-ysai-image-remove]'), 'click', () => this.clearImage());
    this.listen(this.exportButton, 'click', () => {
      this.closePrivacyMenu();
      this.exportConversation();
    });
    this.listen(this.deleteButton, 'click', () => {
      this.closePrivacyMenu();
      this.deleteConversation();
    });
    this.listen(this.privacyToggle, 'click', (event) => {
      event.stopPropagation();
      this.togglePrivacyMenu();
    });
    this.listen(this.privacyMenu, 'click', (event) => event.stopPropagation());
    this.listen(this.privacyMenu, 'keydown', (event) => this.handlePrivacyMenuKeydown(event));
    this.listen(this.messages, 'scroll', () => this.updateScrollControls(), { passive: true });
    this.listen(this.latestButton, 'click', () => {
      this.setUnread(0);
      this.scrollToBottom(true);
      this.input?.focus({ preventScroll: true });
    });
    this.listen(document, 'click', () => this.closePrivacyMenu());
    this.listen(document, 'keydown', (event) => {
      if (event.key === 'Tab') this.trapFocus(event);
      if (event.key === 'Escape') {
        if (this.privacyMenu && !this.privacyMenu.hidden) {
          this.closePrivacyMenu(true);
          return;
        }
        if (this.isPanelOpen()) this.closePanel();
      }
    });
    this.listen(document, 'focusin', (event) => {
      if (!this.modalActive || !this.isPanelOpen() || this.panel?.contains(event.target)) return;
      const focusable = this.focusableElements();
      (focusable[0] || this.panel)?.focus({ preventScroll: true });
    });
    this.listen(window, 'online', () => this.handleOnline());
    this.listen(window, 'offline', () => this.handleOffline());
    this.listen(window, 'resize', () => {
      this.syncResponsivePanel();
      this.scheduleCarouselUpdates();
    }, { passive: true });
    this.listen(window, 'pagehide', () => this.destroy());
    if (window.visualViewport) {
      this.listen(window.visualViewport, 'resize', () => this.updateVisualViewport(), { passive: true });
      this.listen(window.visualViewport, 'scroll', () => this.updateVisualViewport(), { passive: true });
    }
    if (this.modalMedia) {
      const onChange = () => this.syncResponsivePanel();
      if (typeof this.modalMedia.addEventListener === 'function') {
        this.modalMedia.addEventListener('change', onChange);
        this.cleanups.push(() => this.modalMedia?.removeEventListener('change', onChange));
      } else if (typeof this.modalMedia.addListener === 'function') {
        this.modalMedia.addListener(onChange);
        this.cleanups.push(() => this.modalMedia?.removeListener(onChange));
      }
    }
    this.root.querySelectorAll('[data-ysai-avatar-image]').forEach((image) => this.installImageFallback(image, 'avatar'));
  }

  observeLifecycle() {
    if (typeof MutationObserver !== 'function' || !document.documentElement) return;
    this.lifecycleObserver = new MutationObserver(() => {
      if (!this.root.isConnected) {
        this.destroy();
        return;
      }
      if (this.modalActive) this.refreshModalIsolation();
    });
    this.lifecycleObserver.observe(document.documentElement, { childList: true, subtree: true });
  }

  destroy() {
    if (this.destroyed) return;
    this.destroyed = true;
    this.lifecycleGeneration += 1;
    this.openGeneration += 1;
    this.imageGeneration += 1;
    this.imageProcessing = false;
    this.releaseModal();
    this.lifecycleObserver?.disconnect();
    this.lifecycleObserver = null;
    this.nativeCartSynchronizer?.destroy();
    for (const cleanup of this.carouselCleanups.splice(0)) {
      try { cleanup(); } catch (error) { /* Ignore teardown-only failures. */ }
    }
    for (const controller of this.carouselControllers.values()) {
      try { controller.cleanup(); } catch (error) { /* Ignore teardown-only failures. */ }
    }
    this.carouselControllers.clear();
    for (const cleanup of this.cleanups.splice(0)) {
      try { cleanup(); } catch (error) { /* Ignore teardown-only failures. */ }
    }
    for (const url of this.objectUrls) URL.revokeObjectURL(url);
    this.objectUrls.clear();
    if (this.previewUrl) URL.revokeObjectURL(this.previewUrl);
    this.previewUrl = '';
    if (this.announcementTimer) window.clearTimeout(this.announcementTimer);
    if (this.retryTimer) window.clearTimeout(this.retryTimer);
    this.announcementTimer = 0;
    this.retryTimer = 0;
  }

  isPanelOpen() {
    return Boolean(this.panel && !this.panel.hidden && this.root.classList.contains('is-open'));
  }

  isModalViewport() {
    return Boolean(this.modalMedia?.matches);
  }

  syncResponsivePanel() {
    const modal = this.isModalViewport();
    this.root.classList.toggle('is-modal', modal);
    if (this.panel) this.panel.setAttribute('aria-modal', modal && this.isPanelOpen() ? 'true' : 'false');
    this.updateVisualViewport();
    if (!this.isPanelOpen()) {
      this.releaseModal();
      return;
    }
    if (modal) this.activateModal();
    else this.releaseModal();
  }

  updateVisualViewport() {
    const viewport = window.visualViewport;
    const height = Math.max(1, Math.round(viewport?.height || window.innerHeight || 1));
    const width = Math.max(1, Math.round(viewport?.width || window.innerWidth || 1));
    const offsetTop = Math.max(0, Math.round(viewport?.offsetTop || 0));
    const offsetLeft = Math.max(0, Math.round(viewport?.offsetLeft || 0));
    this.root.style.setProperty('--ysai-viewport-height', `${height}px`);
    this.root.style.setProperty('--ysai-viewport-width', `${width}px`);
    this.root.style.setProperty('--ysai-viewport-offset-top', `${offsetTop}px`);
    this.root.style.setProperty('--ysai-viewport-offset-left', `${offsetLeft}px`);
  }

  activateModal() {
    if (this.modalActive || !this.panel || !document.body) return;
    this.modalActive = true;
    this.panel.setAttribute('aria-modal', 'true');
    const html = document.documentElement;
    const body = document.body;
    const scrollY = window.scrollY || 0;
    this.pageLock = {
      scrollY,
      htmlOverflow: html.style.overflow,
      bodyOverflow: body.style.overflow,
      bodyPosition: body.style.position,
      bodyTop: body.style.top,
      bodyWidth: body.style.width,
    };
    html.classList.add('ysai-widget-modal-open');
    body.classList.add('ysai-widget-modal-open');
    html.style.overflow = 'hidden';
    body.style.overflow = 'hidden';
    body.style.position = 'fixed';
    body.style.top = `-${scrollY}px`;
    body.style.width = '100%';

    this.pageIsolation = [];
    this.refreshModalIsolation();
  }

  refreshModalIsolation() {
    if (!this.modalActive || !document.body) return;
    let current = this.root;
    while (current && current !== document.body) {
      const parent = current.parentElement;
      if (!parent) break;
      for (const sibling of parent.children) {
        if (sibling === current || ['SCRIPT', 'STYLE', 'LINK'].includes(sibling.tagName)) continue;
        if (!this.pageIsolation.some((state) => state.node === sibling)) {
          this.pageIsolation.push({
            node: sibling,
            inert: sibling.hasAttribute('inert'),
            ariaHidden: sibling.getAttribute('aria-hidden'),
          });
        }
        sibling.setAttribute('inert', '');
        sibling.setAttribute('aria-hidden', 'true');
      }
      current = parent;
    }
  }

  releaseModal() {
    if (this.panel) this.panel.setAttribute('aria-modal', 'false');
    if (!this.modalActive) return;
    this.modalActive = false;
    for (const state of this.pageIsolation.splice(0)) {
      if (!state.inert) state.node.removeAttribute('inert');
      if (state.ariaHidden === null) state.node.removeAttribute('aria-hidden');
      else state.node.setAttribute('aria-hidden', state.ariaHidden);
    }
    if (this.pageLock && document.body) {
      const html = document.documentElement;
      const body = document.body;
      html.classList.remove('ysai-widget-modal-open');
      body.classList.remove('ysai-widget-modal-open');
      html.style.overflow = this.pageLock.htmlOverflow;
      body.style.overflow = this.pageLock.bodyOverflow;
      body.style.position = this.pageLock.bodyPosition;
      body.style.top = this.pageLock.bodyTop;
      body.style.width = this.pageLock.bodyWidth;
      const scrollY = this.pageLock.scrollY;
      this.pageLock = null;
      window.scrollTo({ top: scrollY, left: window.scrollX || 0, behavior: 'auto' });
    }
  }

  focusableElements() {
    if (!this.panel) return [];
    const selector = [
      'a[href]', 'button:not([disabled])', 'textarea:not([disabled])',
      'input:not([disabled]):not([type="hidden"])', 'select:not([disabled])',
      '[tabindex]:not([tabindex="-1"])',
    ].join(',');
    return Array.from(this.panel.querySelectorAll(selector)).filter((element) => {
      if (element.closest('[hidden]')) return false;
      const style = window.getComputedStyle(element);
      return style.visibility !== 'hidden' && style.display !== 'none' && element.getClientRects().length > 0;
    });
  }

  trapFocus(event) {
    if (event.key !== 'Tab' || !this.modalActive || !this.isPanelOpen()) return;
    const focusable = this.focusableElements();
    if (focusable.length === 0) {
      event.preventDefault();
      this.panel?.focus({ preventScroll: true });
      return;
    }
    const first = focusable[0];
    const last = focusable[focusable.length - 1];
    const active = document.activeElement;
    if (event.shiftKey && (active === first || !this.panel?.contains(active))) {
      event.preventDefault();
      last.focus({ preventScroll: true });
    } else if (!event.shiftKey && active === last) {
      event.preventDefault();
      first.focus({ preventScroll: true });
    }
  }

  async handleOnline() {
    if (this.destroyed) return;
    this.connected = false;
    this.booted = false;
    this.setStatus(config.texts?.loading || 'Loading', false);
    this.updateActionState();
    if (!operationLocks(this).sendLocked) await this.ensureBoot();
  }

  handleOffline() {
    this.connected = false;
    this.setStatus(config.texts?.offline || 'Offline', false);
    this.updateActionState();
  }

  async openPanel() {
    if (!this.panel || !this.openButton || this.destroyed) return;
    const generation = ++this.openGeneration;
    this.panel.hidden = false;
    this.openButton.setAttribute('aria-expanded', 'true');
    this.root.classList.add('is-open');
    this.syncResponsivePanel();
    this.setUnread(0);
    const initialFocus = this.modalActive ? this.closeButton : this.input;
    window.setTimeout(() => {
      if (generation === this.openGeneration && this.isPanelOpen()) initialFocus?.focus({ preventScroll: true });
    }, 0);
    await this.ensureBoot();
    if (generation !== this.openGeneration || !this.isPanelOpen() || this.destroyed) return;
    this.restoreTranscriptPosition();
    window.setTimeout(() => {
      if (generation === this.openGeneration && this.isPanelOpen()) this.input?.focus({ preventScroll: true });
    }, 0);
    this.scheduleCarouselUpdates();
  }

  closePanel({ returnFocus = true } = {}) {
    if (!this.panel || !this.openButton) return;
    this.openGeneration += 1;
    this.closePrivacyMenu();
    this.panel.hidden = true;
    this.openButton.setAttribute('aria-expanded', 'false');
    this.root.classList.remove('is-open');
    this.releaseModal();
    if (returnFocus && this.openButton.isConnected) this.openButton.focus({ preventScroll: true });
  }

  ensureBoot() {
    if (this.booted) return Promise.resolve();
    if (this.bootPromise) return this.bootPromise;
    this.bootPromise = this.boot().finally(() => {
      this.bootPromise = null;
    });
    return this.bootPromise;
  }

  async boot() {
    const lifecycle = this.lifecycleToken();
    if (!this.lifecycleActive(lifecycle)) return;
    this.setStatus(config.texts?.loading || 'Loading', false);
    this.hideError();
    const saved = this.state.credentials();
    const pending = this.state.pending();
    const selected = selectBootCredentials(saved, pending);
    const body = selected
      ? { conversation_id: selected.id, token: selected.token }
      : {};
    try {
      const response = validateBootResponse(await this.api.post('boot', body));
      if (!this.lifecycleActive(lifecycle) || !this.applyBootSnapshot(response)) return;
      this.setRecovering(Boolean(this.state.pending()));
      try {
        await this.recoverPending();
      } finally {
        if (this.lifecycleActive(lifecycle)) this.setRecovering(false);
      }
    } catch (error) {
      if (!this.lifecycleActive(lifecycle)) return;
      this.connected = false;
      this.setStatus(config.texts?.offline || 'Offline', false);
      const unresolved = this.state.pending();
      if (unresolved?.body && await this.recoverPendingWithoutBoot(unresolved, error)) {
        return;
      }
      if (!this.lifecycleActive(lifecycle)) return;
      this.showError(error.message || config.texts?.genericError, () => this.ensureBoot());
    }
  }

  applyBootSnapshot(response) {
    if (this.destroyed || !this.root.isConnected) return false;
    const previous = this.credentials;
    const messages = Array.isArray(response.messages) ? response.messages : [];
    const sameCapability = Boolean(previous)
      && previous.id === response.conversation.id
      && previous.token === response.conversation.token;
    const replyMessageId = Number(this.reply?.message_id || 0);
    const replyProductRef = String(this.reply?.product_ref || '');
    const replyMessage = replyMessageId > 0
      ? messages.find((message) => message.role === 'assistant' && Number(message.id) === replyMessageId)
      : null;
    const replyStillAuthoritative = Boolean(replyMessage)
      && (replyProductRef === ''
        || (Array.isArray(replyMessage.products)
          && replyMessage.products.some((product) => String(product?.ref || '') === replyProductRef)));

    // A reply target is capability-bound server authority. Never retain it
    // across a replacement conversation or when bounded history no longer
    // proves that the exact assistant message/product is public context.
    if (this.reply && (!sameCapability || !replyStillAuthoritative)) {
      this.clearReply();
    }

    this.connected = true;
    this.credentials = response.conversation;
    this.state.saveCredentials(this.credentials);
    this.renderHistory(messages);
    this.renderPendingUserMessage(this.state.pending());
    this.renderCart(response.cart_available === false ? null : response.cart);
    this.booted = true;
    this.setStatus(config.texts?.online || 'Online', true);
    if (response.cart_available === false && response.cart_notice) {
      this.showError(String(response.cart_notice), null);
    }
    return true;
  }

  /**
   * A boot failure must not trap an exact pending turn behind conversation
   * creation quotas or an unrelated cart/history read. The recovery endpoint
   * needs only the original capability and idempotency key, so use it as a
   * read-only fallback. No recovered cart snapshot is rendered without a
   * successfully booted current conversation capability.
   */
  async recoverPendingWithoutBoot(pending, bootError) {
    const lifecycle = this.lifecycleToken();
    if (!this.lifecycleActive(lifecycle)) return false;
    if (!pending?.body) return false;

    this.setRecovering(true);
    this.renderPendingUserMessageFromRecord(pending);
    try {
      const recoveryBody = {
        conversation_id: pending.body.conversation_id,
        token: pending.body.token,
        client_turn_id: pending.body.client_turn_id,
      };
      const recovered = this.requireTurnResponse(
        await this.api.post('turn/recover', recoveryBody),
        pending.body,
        true,
      );
      if (!this.lifecycleActive(lifecycle)) return true;
      if (recovered.status === 'processing') {
        this.renderCart(null);
        this.showError(
          config.texts?.processing || 'الطلب السابق ما زال قيد المعالجة.',
          () => this.ensureBoot(),
        );
        return true;
      }

      this.state.clearPending();
      this.renderRecoveredWithoutBoot(pending, recovered, true);
      this.renderCart(null);
      this.showError(
        config.texts?.previousConversationCompleted
          || 'اكتمل الطلب السابق. افحص السلة قبل متابعة التسوق.',
        () => this.ensureBoot(),
      );
      return true;
    } catch (error) {
      if (!this.lifecycleActive(lifecycle)) return true;
      const classification = classifyTurnError(error, pending.body);
      if (classification === 'not_found') {
        this.rejectMissingPending(pending, error, { withoutBoot: true });
        return true;
      }
      if (classification === 'finalized') {
        this.state.clearPending();
        this.renderRecoveredWithoutBoot(pending, error.payload, error.payload?.request_accepted === true);
        this.renderCart(null);
        this.showError(error?.message || config.texts?.genericError, () => this.ensureBoot());
        return true;
      }
      if (classification === 'unverified') {
        // The server has conclusively bound this denial to the exact capability
        // and turn identity. Keeping that record would make every later boot
        // select the same unusable credentials and trap the widget permanently.
        this.state.clearPending();
        this.markOptimisticUnverified(pending.body?.client_turn_id, error?.message);
        this.renderCart(null);
        this.showError(
          error?.message
            || config.texts?.unverifiedPrevious
            || 'تعذّر التحقق من نتيجة الطلب السابق. افحص السلة قبل إرسال طلب جديد.',
          () => this.ensureBoot(),
        );
        return true;
      }

      // A missing turn, processing state, malformed response, or network error
      // does not authorize us to discard the durable idempotency record. Preserve
      // it and retry boot/recovery with the same identifiers.
      this.state.savePending(pending);
      this.renderCart(null);
      this.showError(
        error?.message || bootError?.message || config.texts?.genericError,
        () => this.ensureBoot(),
      );
      return true;
    } finally {
      if (this.lifecycleActive(lifecycle)) this.setRecovering(false);
    }
  }

  /**
   * A conclusive missing-turn result retires that exact idempotency key. The
   * server seals the absence under the same conversation lock used by claim(),
   * so automatically reusing the old ID would deterministically conflict and
   * could never execute the request. Restore the shopper's draft and require a
   * deliberate new submission, which creates a new cryptographically strong ID.
   */
  rejectMissingPending(pending, error, { withoutBoot = false } = {}) {
    this.state.clearPending();
    this.markOptimisticRejected(
      pending?.body?.client_turn_id,
      error?.message,
      true,
      pending?.body,
    );
    if (withoutBoot) {
      this.renderCart(null);
      this.clearReply();
    }
    const message = pendingRequiresImageReattach(pending)
      ? (config.texts?.imageRecoveryUnavailable
        || 'لم يصل طلب الصورة السابق إلى الخادم. أعد إرفاق الصورة ثم أرسل الطلب من جديد.')
      : (error?.message
        || config.texts?.notSent
        || 'لم يصل الطلب إلى المعالجة. أرسله من جديد إذا كان ما يزال مطلوبًا.');
    this.showError(message, withoutBoot ? () => this.ensureBoot() : null);
  }

  renderRecoveredWithoutBoot(pending, terminal, accepted) {
    const body = pending?.body || {};
    const clientTurnId = String(body.client_turn_id || '');
    if (accepted && clientTurnId !== '' && !this.renderedClientTurnIds.has(clientTurnId)) {
      this.appendMessage('user', String(body.message || '').trim() || 'أرفقت صورة للتسوق.', {
        clientTurnId,
        hasImage: Boolean(body.image) || pending.image_unavailable === true,
        createdAt: String(pending.createdAt || ''),
        historical: true,
      });
      this.renderedClientTurnIds.add(clientTurnId);
    } else if (!accepted) {
      this.markOptimisticRejected(
        clientTurnId,
        String(terminal?.error?.message || config.texts?.notSent || 'لم يتم إرسال الرسالة.'),
        true,
        body,
      );
    }

    const message = terminal?.ok === true
      ? String(terminal.message || '')
      : String(terminal?.error?.message || '');
    const turnId = Number(terminal?.turn_id || 0);
    if (accepted && message !== '' && turnId > 0 && !this.renderedTurnIds.has(turnId)) {
      // The exact text is durable, but product cards, receipts, and cart state may
      // be stale relative to a conversation that could not be booted. Render only
      // the terminal text until a current capability refresh succeeds.
      this.appendMessage('assistant', message, {
        // Text recovered without a booted capability is informational only.
        // A positive message ID would create reply authority for a conversation
        // the browser has not authenticated as its current capability.
        id: 0,
        turnId,
        kind: terminal?.ok === true ? 'answer' : 'safe_failure',
        products: [],
        receipt: null,
      });
      this.renderedTurnIds.add(turnId);
    }
    this.updateEmpty();
  }

  renderHistory(messages) {
    this.messages.querySelectorAll('[data-ysai-message], [data-ysai-day]').forEach((node) => node.remove());
    this.pruneCarousels();
    this.renderedTurnIds.clear();
    this.renderedClientTurnIds.clear();
    this.lastMessageDay = '';
    this.lastMessageRole = '';
    this.lastMessageTimestamp = 0;
    this.setUnread(0);
    this.renderingHistory = true;
    try {
      for (const message of messages) {
        const role = message.role;
        const turnId = Number(message.turn_id || 0);
        const clientTurnId = String(message.client_turn_id || '');
        this.appendMessage(role, String(message.content || ''), {
          id: Number(message.id || 0),
          turnId,
          clientTurnId,
          kind: message.kind,
          products: Array.isArray(message.products) ? message.products : [],
          receipt: message.receipt,
          hasImage: Boolean(message.has_image),
          createdAt: String(message.created_at || ''),
          historical: true,
        });
        if (role === 'assistant' && turnId > 0) this.renderedTurnIds.add(turnId);
        if (role === 'user' && clientTurnId !== '') this.renderedClientTurnIds.add(clientTurnId);
      }
    } finally {
      this.renderingHistory = false;
    }
    this.updateEmpty();
    if (this.isPanelOpen()) this.restoreTranscriptPosition();
  }

  renderPendingUserMessage(pending) {
    const body = pending?.body;
    if (!body || !sameConversation(this.credentials, body)) return;
    this.renderPendingUserMessageFromRecord(pending);
  }

  renderPendingUserMessageFromRecord(pending) {
    const body = pending?.body;
    if (!body) return;
    const clientTurnId = String(body.client_turn_id || '');
    if (clientTurnId === '' || this.renderedClientTurnIds.has(clientTurnId)) return;
    this.appendMessage('user', String(body.message || '').trim() || 'أرفقت صورة للتسوق.', {
      clientTurnId,
      hasImage: Boolean(body.image) || pending.image_unavailable === true,
      createdAt: String(pending.createdAt || ''),
      historical: true,
    });
    this.renderedClientTurnIds.add(clientTurnId);
  }

  async recoverPending() {
    const lifecycle = this.lifecycleToken();
    if (!this.lifecycleActive(lifecycle)) return;
    const pending = this.state.pending();
    if (!pending?.body || !this.credentials) return;

    if (!sameConversation(this.credentials, pending.body)) {
      await this.recoverFromOriginalConversation(pending);
      return;
    }

    try {
      const recoveryBody = {
        conversation_id: pending.body.conversation_id,
        token: pending.body.token,
        client_turn_id: pending.body.client_turn_id,
      };
      const recovered = this.requireTurnResponse(
        await this.api.post('turn/recover', recoveryBody),
        pending.body,
        true,
      );
      if (!this.lifecycleActive(lifecycle)) return;
      if (recovered.status === 'processing') {
        this.showError(config.texts?.processing || 'الطلب السابق ما زال قيد المعالجة.', () => this.retryPending());
        return;
      }
      this.acceptTurnResponse(recovered);
      this.state.clearPending();
    } catch (error) {
      if (!this.lifecycleActive(lifecycle)) return;
      const classification = classifyTurnError(error, pending.body);
      if (classification === 'not_found') {
        this.rejectMissingPending(pending, error);
        return;
      }
      this.handleTurnError(error, pending);
    }
  }

  async recoverFromOriginalConversation(pending) {
    const lifecycle = this.lifecycleToken();
    if (!this.lifecycleActive(lifecycle)) return;
    try {
      const recoveryBody = {
        conversation_id: pending.body.conversation_id,
        token: pending.body.token,
        client_turn_id: pending.body.client_turn_id,
      };
      const recovered = this.requireTurnResponse(
        await this.api.post('turn/recover', recoveryBody),
        pending.body,
        true,
      );
      if (!this.lifecycleActive(lifecycle)) return;
      if (recovered.status === 'processing') {
        // The old turn may still change the shared WooCommerce cart after the
        // new conversation booted. Do not display that potentially stale boot
        // snapshot while the exact older operation remains unresolved.
        this.renderCart(null);
        this.showError(config.texts?.processing || 'الطلب السابق ما زال قيد المعالجة.', () => this.retryPending());
        return;
      }

      // Never switch capabilities, splice an older assistant response into the
      // new transcript, or trust the older turn's stored cart snapshot. Refresh
      // the cart through the exact current conversation capability instead.
      this.state.clearPending();
      this.markOptimisticAccepted(pending.body?.client_turn_id);
      await this.refreshCurrentCart();
      if (!this.lifecycleActive(lifecycle)) return;
      this.showError(
        config.texts?.previousConversationCompleted
          || 'اكتمل الطلب السابق ضمن محادثة أخرى. افحص السلة قبل متابعة التسوق.',
        null,
      );
    } catch (error) {
      if (!this.lifecycleActive(lifecycle)) return;
      const classification = classifyTurnError(error, pending.body);
      if (classification === 'finalized') {
        this.state.clearPending();
        if (error.payload?.request_accepted === true) {
          this.markOptimisticAccepted(pending.body?.client_turn_id);
        } else {
          this.markOptimisticRejected(
            pending.body?.client_turn_id,
            error?.message,
            true,
            pending.body,
          );
        }
        // Even a terminal failure can follow an attempted cart operation. Read
        // the current shared cart again instead of retaining the pre-recovery
        // boot snapshot or using cart state from the older conversation.
        await this.refreshCurrentCart();
        if (!this.lifecycleActive(lifecycle)) return;
        this.showError(error?.message || config.texts?.genericError, null);
        return;
      }
      if (classification === 'not_found') {
        this.rejectMissingPending(pending, error);
        return;
      }
      if (['conflict', 'rejected'].includes(classification)) {
        this.state.clearPending();
        this.markOptimisticRejected(
          pending.body?.client_turn_id,
          error?.message,
          true,
          pending.body,
        );
        await this.refreshCurrentCart();
        if (!this.lifecycleActive(lifecycle)) return;
        this.showError(error?.message || config.texts?.notSent || 'لم يتم إرسال الرسالة.', null);
        return;
      }
      if (classification === 'unverified') {
        this.showUnverifiedPreviousTurn(pending);
        return;
      }
      this.showError(
        error?.message || config.texts?.unverifiedPrevious || 'تعذّر التحقق من نتيجة الطلب السابق.',
        () => this.retryPending(),
      );
    }
  }

  async refreshCurrentCart() {
    const lifecycle = this.lifecycleToken();
    if (!this.lifecycleActive(lifecycle)) return false;
    const current = this.credentials;
    const conversationId = String(current?.id || '');
    const token = String(current?.token || '');
    if (conversationId === '' || token === '') {
      this.renderCart(null);
      return false;
    }

    try {
      const response = validateBootResponse(await this.api.post('boot', {
        conversation_id: conversationId,
        token,
      }));
      if (!this.lifecycleActive(lifecycle)) return false;

      // Boot is the authoritative cart/history read boundary. If the original
      // capability has expired and the server creates a replacement, adopt the
      // validated snapshot atomically instead of discarding the new capability
      // and repeatedly consuming conversation-creation quota on every refresh.
      if (!this.applyBootSnapshot(response)) return false;
      return response.cart_available !== false;
    } catch (error) {
      if (!this.lifecycleActive(lifecycle)) return false;
      this.connected = false;
      this.renderCart(null);
      this.setStatus(config.texts?.offline || 'Offline', false);
      return false;
    }
  }

  showUnverifiedPreviousTurn(pending = null) {
    this.state.clearPending();
    this.markOptimisticUnverified(pending?.body?.client_turn_id);
    this.renderCart(null);
    this.showError(
      config.texts?.unverifiedPrevious
        || 'تعذّر التحقق من نتيجة الطلب السابق. افحص السلة قبل إرسال طلب جديد.',
      null,
    );
  }

  async submit() {
    const lifecycle = this.lifecycleToken();
    if (!this.lifecycleActive(lifecycle)) return;
    if (operationLocks(this).sendLocked) return;
    const existingPending = this.state.pending();
    if (existingPending) {
      this.showError(config.texts?.processing || 'الطلب السابق ما زال قيد المعالجة.', () => this.retryPending());
      this.updateActionState();
      return;
    }

    const initialDraft = this.draftState();
    if (initialDraft.overLimit) {
      this.showError(config.texts?.messageTooLong || 'الرسالة أطول من الحد المسموح.', null);
      this.updateActionState();
      return;
    }
    if (!initialDraft.hasContent) return;
    if (navigator.onLine === false) {
      this.showError(config.texts?.offline || 'غير متصل', null);
      this.updateActionState();
      return;
    }

    await this.ensureBoot();
    if (!this.lifecycleActive(lifecycle)) return;
    const turnAction = newTurnAction({
      locked: operationLocks(this).sendLocked,
      credentials: this.credentials,
      pending: this.state.pending(),
    });
    if (turnAction === 'recover_pending') {
      this.showError(config.texts?.processing || 'الطلب السابق ما زال قيد المعالجة.', () => this.retryPending());
      this.updateActionState();
      return;
    }
    if (turnAction !== 'start' || !this.connected) {
      this.showError(config.texts?.offline || 'غير متصل', () => this.ensureBoot());
      this.updateActionState();
      return;
    }

    const rawMessage = String(this.input?.value || '');
    const messageLimit = Number(config.limits?.messageCharacters || 4000);
    if (unicodeLength(rawMessage) > messageLimit) {
      this.showError(config.texts?.messageTooLong || 'الرسالة أطول من الحد المسموح.', null);
      this.updateActionState();
      return;
    }
    const message = rawMessage.trim();
    if (!message && !this.image) return;

    let clientTurnId;
    try {
      clientTurnId = createTurnId();
    } catch (error) {
      this.showError(
        config.texts?.secureRandomUnavailable
          || 'تعذّر إنشاء معرّف آمن للطلب في هذا المتصفح.',
        null,
      );
      return;
    }

    const body = {
      conversation_id: this.credentials.id,
      token: this.credentials.token,
      client_turn_id: clientTurnId,
      message,
      reply: this.replyPayload(),
      image: this.image ? { mime_type: this.image.mimeType, data: this.image.base64 } : null,
    };
    const pendingStored = this.state.savePending({ body, createdAt: new Date().toISOString() });
    if (!pendingStored) {
      this.state.clearPending();
      this.showError(
        config.texts?.pendingStorageUnavailable
          || 'يتعذّر حفظ معرّف الطلب بأمان في هذا المتصفح، لذلك لم تُرسل الرسالة.',
        null,
      );
      this.updateActionState();
      return;
    }

    const optimisticPreviewUrl = this.previewUrl;
    if (optimisticPreviewUrl) {
      this.objectUrls.add(optimisticPreviewUrl);
      this.previewUrl = '';
    }
    this.appendMessage('user', message || 'أرفقت صورة للتسوق.', {
      turnId: 0,
      clientTurnId: body.client_turn_id,
      hasImage: Boolean(this.image),
      imagePreviewUrl: optimisticPreviewUrl,
      createdAt: new Date().toISOString(),
      delivery: 'pending',
    });
    this.renderedClientTurnIds.add(body.client_turn_id);
    this.input.value = '';
    this.resizeInput();
    this.clearReply();
    this.clearImage();
    this.updateActionState();
    await this.sendBody(body);
  }

  async sendBody(body, { withoutBoot = false } = {}) {
    const lifecycle = this.lifecycleToken();
    if (!this.lifecycleActive(lifecycle)) return false;
    this.setBusy(true);
    this.hideError();
    try {
      const response = this.requireTurnResponse(await this.api.post('chat', body), body);
      if (!this.lifecycleActive(lifecycle)) return false;
      if (withoutBoot) {
        // The exact request is durable, but no current capability snapshot was
        // authenticated. Render text only and retain no cart/reply authority.
        this.connected = false;
        this.renderRecoveredWithoutBoot({ body }, response, true);
        this.renderCart(null);
        this.clearReply();
      } else {
        this.connected = true;
        this.acceptTurnResponse(response);
      }
      this.state.clearPending();
      return true;
    } catch (error) {
      if (!this.lifecycleActive(lifecycle)) return false;
      this.connected = withoutBoot ? false : Number(error?.status || 0) > 0;
      const pending = this.state.pending() || { body };
      this.handleTurnError(error, pending, { withoutBoot });
      if (withoutBoot) {
        this.renderCart(null);
        this.clearReply();
      }
      return false;
    } finally {
      if (this.lifecycleActive(lifecycle)) this.setBusy(false);
    }
  }

  handleTurnError(error, pending, { withoutBoot = false } = {}) {
    pending.lastError = String(error.code || 'network_error');
    delete pending.needsNewTurn;
    const classification = classifyTurnError(error, pending.body);

    // An exact, identity-bound unauthorized result proves that this browser can
    // no longer use the current capability. Keeping booted=true would trap every
    // later send behind the same dead credentials. Clear only after the strict
    // turn envelope has proved the rejection; ambiguous 401/proxy responses keep
    // the pending idempotency record and recover normally.
    if (!withoutBoot
      && String(error?.code || '') === 'conversation_unauthorized'
      && sameConversation(this.credentials, pending.body)
      && ['rejected', 'unverified'].includes(classification)) {
      if (classification === 'rejected') {
        this.markOptimisticRejected(
          pending.body?.client_turn_id,
          error.message || config.texts?.notSent || 'لم يتم إرسال الرسالة.',
          true,
          pending.body,
        );
      } else {
        this.markOptimisticUnverified(pending.body?.client_turn_id, error.message);
      }
      this.resetConversationView(true);
      this.showError(error.message || config.texts?.genericError, () => this.ensureBoot());
      return;
    }

    if (classification === 'finalized') {
      this.state.clearPending();
      if (error.payload?.request_accepted === true) {
        this.markOptimisticAccepted(pending.body?.client_turn_id);
        if (withoutBoot) {
          this.renderRecoveredWithoutBoot(pending, error.payload, true);
        } else {
          this.renderFinalizedFailure(error.payload);
        }
      } else {
        this.markOptimisticRejected(
          pending.body?.client_turn_id,
          error.message || config.texts?.notSent || 'لم يتم إرسال الرسالة.',
          true,
          pending.body,
        );
      }
      if (['turn_abandoned', 'previous_turn_abandoned'].includes(String(error?.code || ''))) {
        this.renderCart(null);
      }
      const canRetryAsNewTurn = error?.retryMode === 'new_turn'
        && !withoutBoot
        && !pendingRequiresImageReattach(pending);
      const message = error.message || config.texts?.genericError;
      const retry = canRetryAsNewTurn ? () => this.retryFinalizedAsNewTurn(pending) : null;
      const retryLabel = config.texts?.retryNewTurn || 'إرسال الطلب من جديد';
      if (retry && error.retryAfterSeconds > 0) {
        this.showDeferredRetry(message, error.retryAfterSeconds, retry, retryLabel);
      } else {
        this.showError(message, retry, retryLabel);
      }
      return;
    }

    if (classification === 'unverified') {
      this.state.clearPending();
      this.markOptimisticUnverified(pending.body?.client_turn_id, error.message);
      this.renderCart(null);
      this.showError(
        error.message || config.texts?.unverifiedPrevious || 'تعذّر التحقق من نتيجة الطلب. افحص السلة قبل إرسال طلب جديد.',
        null,
      );
      return;
    }

    if (turnRecoveryAction(error, pending.body) !== 'recover_same_turn') {
      this.state.clearPending();
      this.markOptimisticRejected(
        pending.body?.client_turn_id,
        error.message || config.texts?.notSent || 'لم يتم إرسال الرسالة.',
        true,
        pending.body,
      );
      this.showError(error.message || config.texts?.genericError, null);
      return;
    }

    this.state.savePending(pending);
    const message = error.message || config.texts?.genericError;
    if (error.retryAfterSeconds > 0) {
      this.showDeferredRetry(
        message,
        error.retryAfterSeconds,
        () => this.retryPending(),
        config.texts?.retry || 'إعادة المحاولة',
      );
    } else {
      this.showError(message, () => this.retryPending());
    }
  }

  async retryPending() {
    const lifecycle = this.lifecycleToken();
    if (!this.lifecycleActive(lifecycle)) return;
    const pending = this.state.pending();
    if (!pending?.body || operationLocks(this).sendLocked) return;
    if (!this.credentials) {
      await this.ensureBoot();
      return;
    }
    this.setRecovering(true);
    try {
      await this.recoverPending();
    } finally {
      if (this.lifecycleActive(lifecycle)) this.setRecovering(false);
    }
  }

  async retryFinalizedAsNewTurn(pending) {
    const lifecycle = this.lifecycleToken();
    if (!this.lifecycleActive(lifecycle) || operationLocks(this).sendLocked) return;
    if (!pending?.body || pendingRequiresImageReattach(pending)) {
      this.showError(
        config.texts?.imageRecoveryUnavailable
          || 'أعد إرفاق الصورة ثم أرسل الطلب من جديد.',
        null,
      );
      return;
    }

    await this.ensureBoot();
    if (!this.lifecycleActive(lifecycle) || !this.connected || !this.credentials) return;
    if (this.state.pending()) {
      this.showError(config.texts?.processing || 'الطلب السابق ما زال قيد المعالجة.', () => this.retryPending());
      return;
    }

    let clientTurnId;
    try {
      clientTurnId = createTurnId();
    } catch (error) {
      this.showError(
        config.texts?.secureRandomUnavailable
          || 'تعذّر إنشاء معرّف آمن للطلب في هذا المتصفح.',
        null,
      );
      return;
    }

    const original = pending.body;
    const sameConversationId = String(original.conversation_id || '') === String(this.credentials.id || '');
    const body = {
      conversation_id: this.credentials.id,
      token: this.credentials.token,
      client_turn_id: clientTurnId,
      message: String(original.message || ''),
      reply: sameConversationId ? (original.reply || null) : null,
      image: original.image || null,
    };
    if (!this.state.savePending({ body, createdAt: new Date().toISOString() })) {
      this.state.clearPending();
      this.showError(
        config.texts?.pendingStorageUnavailable
          || 'يتعذّر حفظ معرّف الطلب بأمان في هذا المتصفح، لذلك لم تُرسل الرسالة.',
        null,
      );
      return;
    }

    this.appendMessage('user', body.message.trim() || 'أرفقت صورة للتسوق.', {
      turnId: 0,
      clientTurnId,
      hasImage: Boolean(body.image),
      createdAt: new Date().toISOString(),
      delivery: 'pending',
    });
    this.renderedClientTurnIds.add(clientTurnId);
    await this.sendBody(body);
  }

  requireTurnResponse(response, requestBody, allowProcessing = false) {
    try {
      return validateTurnResponse(response, requestBody, { allowProcessing });
    } catch (error) {
      throw new ApiError(config.texts?.genericError || 'Invalid server response', {
        status: 0,
        code: 'invalid_response',
        retryable: true,
        payload: null,
      });
    }
  }

  acceptTurnResponse(response) {
    this.markOptimisticAccepted(response.client_turn_id);
    this.hideError();
    if (response.kind === 'cart_receipt' && response.receipt && response.cart) {
      this.nativeCartSynchronizer.converge(response.receipt, response.cart);
    }
    const turnId = Number(response.turn_id || 0);
    if (turnId > 0 && this.renderedTurnIds.has(turnId)) {
      if (response.cart) this.renderCart(response.cart);
      else if (response.kind === 'cart_uncertain') this.renderCart(null);
      return;
    }
    this.appendMessage('assistant', String(response.message || ''), {
      id: Number(response.message_id || 0),
      turnId,
      kind: response.kind,
      products: Array.isArray(response.products) ? response.products : [],
      receipt: response.receipt,
    });
    if (turnId > 0) this.renderedTurnIds.add(turnId);
    if (response.cart) this.renderCart(response.cart);
    else if (response.kind === 'cart_uncertain') this.renderCart(null);
  }

  renderFinalizedFailure(payload) {
    const turnId = Number(payload?.turn_id || 0);
    if (turnId <= 0 || this.renderedTurnIds.has(turnId)) return;
    this.appendMessage('assistant', String(payload?.error?.message || config.texts?.genericError || ''), {
      id: Number(payload?.message_id || 0),
      turnId,
      kind: 'safe_failure',
      products: [],
      receipt: null,
    });
    this.renderedTurnIds.add(turnId);
  }

  markOptimisticAccepted(clientTurnId) {
    const id = String(clientTurnId || '');
    if (id === '' || !this.messages) return;
    this.renderedClientTurnIds.add(id);
    for (const article of this.messages.querySelectorAll('[data-client-turn-id]')) {
      if (article.dataset.clientTurnId !== id) continue;
      delete article.dataset.clientTurnId;
      article.classList.remove('ysai-message--rejected', 'ysai-message--unverified');
      article.dataset.delivery = 'accepted';
      const deliveryLabel = article.querySelector('[data-ysai-delivery-label]');
      if (deliveryLabel) deliveryLabel.textContent = config.texts?.sent || 'تم الإرسال';
      article.removeAttribute('aria-label');
      article.querySelector('[data-ysai-send-status]')?.remove();
    }
  }

  markOptimisticUnverified(clientTurnId, reason = '') {
    const id = String(clientTurnId || '');
    if (id === '' || !this.messages) return;
    this.renderedClientTurnIds.add(id);
    for (const article of this.messages.querySelectorAll('[data-client-turn-id]')) {
      if (article.dataset.clientTurnId !== id) continue;
      delete article.dataset.clientTurnId;
      article.classList.remove('ysai-message--rejected');
      article.classList.add('ysai-message--unverified');
      article.dataset.delivery = 'unverified';
      const deliveryLabel = article.querySelector('[data-ysai-delivery-label]');
      if (deliveryLabel) deliveryLabel.textContent = config.texts?.sendUnverified || 'تعذّر التحقق من حالة الإرسال';
      article.querySelector('[data-ysai-send-status]')?.remove();
      const badge = document.createElement('span');
      badge.className = 'ysai-send-status ysai-send-status--unverified';
      badge.dataset.ysaiSendStatus = 'unverified';
      badge.textContent = config.texts?.sendUnverified || 'تعذّر التحقق من حالة الإرسال';
      article.querySelector('.ysai-bubble')?.append(badge);
      if (reason) article.setAttribute('aria-label', truncateUnicode(String(reason), 300));
    }
  }

  markOptimisticRejected(clientTurnId, reason = '', restoreDraft = false, body = null) {
    const id = String(clientTurnId || '');
    if (id !== '') this.renderedClientTurnIds.add(id);
    if (id !== '' && this.messages) {
      for (const article of this.messages.querySelectorAll('[data-client-turn-id]')) {
        if (article.dataset.clientTurnId !== id) continue;
        delete article.dataset.clientTurnId;
        article.classList.remove('ysai-message--unverified');
        article.classList.add('ysai-message--rejected');
        article.dataset.delivery = 'rejected';
        const deliveryLabel = article.querySelector('[data-ysai-delivery-label]');
        if (deliveryLabel) deliveryLabel.textContent = config.texts?.notSent || 'لم يتم الإرسال';
        article.querySelector('[data-ysai-send-status]')?.remove();
        const badge = document.createElement('span');
        badge.className = 'ysai-send-status';
        badge.dataset.ysaiSendStatus = 'rejected';
        badge.textContent = config.texts?.notSent || 'لم يتم الإرسال';
        article.querySelector('.ysai-bubble')?.append(badge);
        if (reason) article.setAttribute('aria-label', truncateUnicode(String(reason), 300));
      }
    }
    if (restoreDraft && this.input && this.input.value.trim() === '' && typeof body?.message === 'string') {
      this.input.value = truncateUnicode(
        body.message,
        Number(config.limits?.messageCharacters || 4000),
      );
      this.resizeInput();
    }
  }

  messageDate(value) {
    const date = typeof value === 'string' && value !== '' ? new Date(value) : new Date();
    return Number.isFinite(date.getTime()) ? date : new Date();
  }

  dayKey(date) {
    return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`;
  }

  dayLabel(date) {
    const today = new Date();
    const startToday = new Date(today.getFullYear(), today.getMonth(), today.getDate()).getTime();
    const startDate = new Date(date.getFullYear(), date.getMonth(), date.getDate()).getTime();
    const days = Math.round((startToday - startDate) / 86400000);
    if (days === 0) return config.texts?.today || 'اليوم';
    if (days === 1) return config.texts?.yesterday || 'أمس';
    try {
      return new Intl.DateTimeFormat('ar', { day: 'numeric', month: 'long', year: 'numeric' }).format(date);
    } catch (error) {
      return date.toLocaleDateString();
    }
  }

  messageTime(date) {
    try {
      return new Intl.DateTimeFormat('ar', { hour: 'numeric', minute: '2-digit' }).format(date);
    } catch (error) {
      return date.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
    }
  }

  appendDaySeparator(date) {
    if (!this.messages || !config.features?.timestamps) return;
    const key = this.dayKey(date);
    if (key === this.lastMessageDay) return;
    const separator = document.createElement('div');
    separator.className = 'ysai-day-separator';
    separator.dataset.ysaiDay = key;
    separator.setAttribute('role', 'separator');
    separator.setAttribute('aria-label', this.dayLabel(date));
    const label = document.createElement('span');
    label.textContent = this.dayLabel(date);
    separator.append(label);
    this.messages.append(separator);
    this.lastMessageDay = key;
  }

  agentAvatar() {
    const source = this.headerAvatar;
    if (source) {
      const avatar = source.cloneNode(true);
      avatar.className = 'ysai-avatar ysai-avatar--message';
      avatar.removeAttribute('data-ysai-header-avatar');
      avatar.querySelector('i')?.remove();
      avatar.setAttribute('aria-hidden', 'true');
      return avatar;
    }
    const avatar = document.createElement('span');
    avatar.className = 'ysai-avatar ysai-avatar--message';
    avatar.setAttribute('aria-hidden', 'true');
    avatar.textContent = 'ي';
    return avatar;
  }

  createIcon(pathData) {
    const svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
    svg.setAttribute('viewBox', '0 0 24 24');
    svg.setAttribute('aria-hidden', 'true');
    for (const definition of pathData) {
      const element = document.createElementNS('http://www.w3.org/2000/svg', definition.tag || 'path');
      for (const [name, value] of Object.entries(definition.attrs || {})) {
        element.setAttribute(name, value);
      }
      if (definition.d) element.setAttribute('d', definition.d);
      svg.append(element);
    }
    return svg;
  }

  actionButton(label, icon) {
    const button = document.createElement('button');
    button.type = 'button';
    button.setAttribute('aria-label', label);
    button.title = label;
    button.append(icon);
    return button;
  }

  appendMessage(role, text, data = {}) {
    if (!this.messages) return null;
    const date = this.messageDate(data.createdAt);
    const timestamp = date.getTime();
    const priorMessages = this.messages.querySelectorAll('[data-ysai-message]');
    const previous = priorMessages.length > 0 ? priorMessages[priorMessages.length - 1] : null;
    const previousRole = String(previous?.dataset.messageRole || '');
    const previousTimestamp = Number(previous?.dataset.messageTimestamp || 0);
    const sameGroup = Boolean(previous)
      && previousRole === role
      && previousTimestamp > 0
      && timestamp >= previousTimestamp
      && timestamp - previousTimestamp <= 300000
      && this.dayKey(new Date(previousTimestamp)) === this.dayKey(date);
    const wasNearBottom = this.isNearBottom();

    this.appendDaySeparator(date);

    const article = document.createElement('article');
    article.className = `ysai-message ysai-message--${role}`;
    if (sameGroup) article.classList.add('is-grouped');
    if (data.kind === 'cart_receipt' || data.receipt) article.classList.add('ysai-message--receipt');
    article.dataset.ysaiMessage = '1';
    article.dataset.messageRole = role;
    article.dataset.messageTimestamp = String(timestamp);
    if (data.id) article.dataset.messageId = String(data.id);
    if (data.turnId) article.dataset.turnId = String(data.turnId);
    if (data.clientTurnId) article.dataset.clientTurnId = String(data.clientTurnId);
    if (role === 'user') {
      article.dataset.delivery = String(data.delivery || (data.historical ? 'accepted' : 'pending'));
    }

    if (role === 'assistant') article.append(this.agentAvatar());

    const stack = document.createElement('div');
    stack.className = 'ysai-message__stack';
    const bubble = document.createElement('div');
    bubble.className = 'ysai-bubble';
    const content = document.createElement('p');
    content.textContent = text;
    bubble.append(content);

    if (data.imagePreviewUrl) {
      bubble.prepend(this.messageImagePreview(data.imagePreviewUrl, text));
    } else if (data.hasImage) {
      const badge = document.createElement('span');
      badge.className = 'ysai-image-badge';
      badge.textContent = config.texts?.attachedImage || 'صورة مرفقة';
      bubble.append(badge);
    }

    if (data.receipt?.id) {
      const receipt = document.createElement('div');
      receipt.className = 'ysai-receipt';
      const title = document.createElement('strong');
      title.textContent = config.texts?.verifiedReceipt || 'إيصال موثّق من الخادم';
      const id = document.createElement('code');
      id.textContent = String(data.receipt.id);
      receipt.append(title, id);
      bubble.append(receipt);
    }

    if (config.features?.timestamps || role === 'user') {
      const meta = document.createElement('span');
      meta.className = 'ysai-message-meta';
      if (config.features?.timestamps) {
        const time = document.createElement('time');
        time.dateTime = date.toISOString();
        time.textContent = this.messageTime(date);
        meta.append(time);
      }
      if (role === 'user') {
        const delivery = document.createElement('span');
        delivery.className = 'screen-reader-text';
        delivery.dataset.ysaiDeliveryLabel = '1';
        delivery.textContent = data.delivery === 'pending'
          ? (config.texts?.sending || 'جارٍ الإرسال')
          : (config.texts?.sent || 'تم الإرسال');
        meta.append(delivery);
      }
      bubble.append(meta);
    }

    stack.append(bubble);
    if (role === 'assistant' && Array.isArray(data.products) && data.products.length) {
      stack.append(this.productCards(data.products, Number(data.id || 0)));
    }
    const actions = this.messageActions(role, text, data);
    if (actions.childElementCount > 0) stack.append(actions);
    article.append(stack);
    this.messages.append(article);

    this.lastMessageRole = role;
    this.lastMessageTimestamp = timestamp;
    this.updateEmpty();

    if (this.renderingHistory) return article;

    const panelOpen = Boolean(this.panel && !this.panel.hidden);
    if (!data.historical && role === 'assistant' && (!panelOpen || !wasNearBottom)) {
      this.setUnread(this.unreadCount + 1);
      this.announce(config.texts?.newMessages || 'رسائل جديدة');
    }
    if (panelOpen && (wasNearBottom || role === 'user' || data.historical)) {
      if (role === 'assistant' && !data.historical) this.revealMessageStart(article);
      else this.scrollToBottom(false);
    } else {
      this.updateScrollControls();
    }
    return article;
  }

  messageImagePreview(url, text) {
    const figure = document.createElement('figure');
    figure.className = 'ysai-message-image';
    const image = document.createElement('img');
    image.alt = truncateUnicode(String(text || config.texts?.attachedImage || 'صورة مرفقة'), 160);
    image.decoding = 'async';
    const release = () => {
      if (this.objectUrls.has(url)) {
        URL.revokeObjectURL(url);
        this.objectUrls.delete(url);
      }
    };
    image.addEventListener('load', release, { once: true });
    image.addEventListener('error', () => {
      release();
      this.replaceBrokenImage(image, 'message');
    }, { once: true });
    image.src = url;
    figure.append(image);
    return figure;
  }

  installImageFallback(image, kind = 'image') {
    if (!(image instanceof HTMLImageElement) || image.dataset.ysaiFallbackBound === '1') return;
    image.dataset.ysaiFallbackBound = '1';
    image.addEventListener('error', () => this.replaceBrokenImage(image, kind), { once: true });
  }

  replaceBrokenImage(image, kind = 'image') {
    if (!image?.isConnected) return;
    const placeholder = document.createElement('span');
    placeholder.className = `ysai-image-fallback ysai-image-fallback--${kind}`;
    placeholder.setAttribute('role', 'img');
    placeholder.setAttribute('aria-label', config.texts?.imageUnavailable || 'تعذّر عرض الصورة');
    placeholder.append(this.createIcon([
      { tag: 'rect', attrs: { x: '3', y: '4', width: '18', height: '16', rx: '2' } },
      { tag: 'circle', attrs: { cx: '8.5', cy: '9', r: '1.5' } },
      { d: 'm21 15-5-5L5 20' },
      { d: 'm4 4 16 16' },
    ]));
    image.replaceWith(placeholder);
  }

  messageActions(role, text, data) {
    const actions = document.createElement('div');
    actions.className = 'ysai-message-actions';
    if (!config.features?.messageActions) return actions;

    const copyLabel = config.texts?.copy || 'نسخ';
    const copy = this.actionButton(copyLabel, this.createIcon([
      { tag: 'rect', attrs: { x: '8', y: '8', width: '11', height: '11', rx: '2' } },
      { d: 'M16 8V6a2 2 0 0 0-2-2H6a2 2 0 0 0-2 2v8a2 2 0 0 0 2 2h2' },
    ]));
    copy.addEventListener('click', async () => {
      const copied = await this.copyText(text);
      const feedback = copied
        ? (config.texts?.copied || 'تم النسخ')
        : (config.texts?.copyFailed || 'تعذّر النسخ');
      copy.setAttribute('aria-label', feedback);
      copy.title = feedback;
      this.announce(feedback);
      window.setTimeout(() => {
        copy.setAttribute('aria-label', copyLabel);
        copy.title = copyLabel;
      }, 1200);
    });
    actions.append(copy);

    if (role === 'assistant' && Number(data.id || 0) > 0) {
      const replyLabel = config.texts?.reply || 'رد';
      const reply = this.actionButton(replyLabel, this.createIcon([
        { d: 'M9 17 4 12l5-5' },
        { d: 'M4 12h9a7 7 0 0 1 7 7' },
      ]));
      reply.addEventListener('click', () => this.setReply({
        message_id: Number(data.id || 0),
        text: truncateUnicode(String(text), 1000),
        image: this.httpUrl(data.products?.[0]?.image, false) || '',
      }));
      actions.append(reply);
    }
    return actions;
  }

  productCards(products, messageId = 0) {
    this.pruneCarousels();
    const shell = document.createElement('div');
    shell.className = 'ysai-products-shell';
    const list = document.createElement('div');
    list.className = 'ysai-products';
    list.setAttribute('role', 'list');
    list.setAttribute('aria-label', config.texts?.productsSuggested || 'المنتجات المقترحة');

    const cards = [];
    for (const product of products.slice(0, 12)) {
      const card = document.createElement('article');
      card.className = 'ysai-product';
      card.setAttribute('role', 'listitem');

      const imageUrl = this.httpUrl(product.image, false);
      if (imageUrl) {
        const image = document.createElement('img');
        image.alt = String(product.name || '');
        image.loading = 'lazy';
        image.decoding = 'async';
        image.referrerPolicy = 'no-referrer';
        this.installImageFallback(image, 'product');
        image.src = imageUrl;
        card.append(image);
      } else {
        card.append(this.productImagePlaceholder());
      }

      const body = document.createElement('div');
      body.className = 'ysai-product__body';
      const name = document.createElement('h3');
      const productUrl = this.httpUrl(product.url, true);
      if (productUrl) {
        const link = document.createElement('a');
        link.href = productUrl;
        link.textContent = String(product.name || '');
        name.append(link);
      } else {
        name.textContent = String(product.name || '');
      }
      body.append(name);

      const meta = document.createElement('div');
      meta.className = 'ysai-product__meta';
      const price = document.createElement('strong');
      price.textContent = product.price_available === false
        ? String(product.price_text || config.texts?.priceUnavailable || 'السعر غير متاح')
        : String(product.price_text || '');
      const stock = document.createElement('span');
      stock.className = product.in_stock ? 'is-in-stock' : 'is-out-of-stock';
      stock.textContent = product.in_stock
        ? (config.texts?.inStock || 'متوفر')
        : (config.texts?.outOfStock || 'غير متوفر');
      meta.append(price, stock);
      body.append(meta);

      if (config.features?.showDescription && product.short_description) {
        const description = document.createElement('p');
        description.textContent = truncateUnicode(String(product.short_description), 260);
        body.append(description);
      }

      const ask = document.createElement('button');
      ask.type = 'button';
      ask.className = 'ysai-product__ask';
      ask.textContent = config.texts?.askProduct || 'اسأل عن هذا المنتج';
      const productName = truncateUnicode(String(product.name || ''), 160);
      if (productName !== '') {
        const namedTemplate = String(config.texts?.askProductNamed || 'اسأل عن المنتج: %s');
        const namedLabel = namedTemplate.replace('%s', productName);
        ask.setAttribute('aria-label', namedLabel);
        ask.title = namedLabel;
      }
      ask.addEventListener('click', () => {
        if (messageId > 0) {
          this.setReply({
            message_id: messageId,
            product_ref: String(product.ref || ''),
            text: truncateUnicode(String(product.name || ''), 300),
            image: imageUrl || '',
          });
        } else if (this.input) {
          this.input.value = truncateUnicode(String(product.name || ''), 300);
          this.resizeInput();
          this.updateActionState();
        }
        this.input?.focus();
      });
      body.append(ask);
      card.append(body);
      list.append(card);
      cards.push(card);
    }
    shell.classList.toggle('has-single-product', cards.length === 1);
    shell.append(list);

    if (this.root.dataset.productLayout === 'carousel' && cards.length > 1) {
      const nav = document.createElement('div');
      nav.className = 'ysai-products-nav';
      const previous = this.actionButton(
        config.texts?.previousProducts || 'المنتجات السابقة',
        this.createIcon([{ d: 'm9 18 6-6-6-6' }]),
      );
      const indicator = document.createElement('span');
      indicator.className = 'ysai-products-indicator';
      indicator.dir = 'ltr';
      indicator.setAttribute('aria-live', 'polite');
      const next = this.actionButton(
        config.texts?.nextProducts || 'المنتجات التالية',
        this.createIcon([{ d: 'm15 18-6-6 6-6' }]),
      );
      let startIndex = 0;
      let endIndex = 0;
      let frame = 0;

      const update = () => {
        frame = 0;
        if (!list.isConnected || list.getClientRects().length === 0) return;
        const bounds = list.getBoundingClientRect();
        const visible = [];
        cards.forEach((card, index) => {
          const rect = card.getBoundingClientRect();
          const overlap = Math.max(0, Math.min(rect.right, bounds.right) - Math.max(rect.left, bounds.left));
          const ratio = rect.width > 0 ? overlap / rect.width : 0;
          if (ratio >= 0.5) visible.push(index);
        });
        if (visible.length === 0) {
          const center = bounds.left + (bounds.width / 2);
          const closest = cards.reduce((best, card, index) => {
            const rect = card.getBoundingClientRect();
            const distance = Math.abs((rect.left + rect.width / 2) - center);
            return distance < best.distance ? { index, distance } : best;
          }, { index: 0, distance: Number.POSITIVE_INFINITY });
          visible.push(closest.index);
        }
        startIndex = Math.min(...visible);
        endIndex = Math.max(...visible);
        list.dataset.ysaiVisibleStart = String(startIndex);
        list.dataset.ysaiVisibleEnd = String(endIndex);
        previous.disabled = startIndex <= 0;
        next.disabled = endIndex >= cards.length - 1;
        indicator.textContent = startIndex === endIndex
          ? `${startIndex + 1} / ${cards.length}`
          : `${startIndex + 1}–${endIndex + 1} / ${cards.length}`;
      };
      const schedule = () => {
        if (frame) window.cancelAnimationFrame(frame);
        frame = window.requestAnimationFrame(update);
      };
      const move = (direction) => {
        const target = direction < 0
          ? Math.max(0, startIndex - 1)
          : Math.min(cards.length - 1, endIndex + 1);
        cards[target]?.scrollIntoView({ behavior: 'smooth', block: 'nearest', inline: 'nearest' });
        window.setTimeout(schedule, 240);
      };
      previous.addEventListener('click', () => move(-1));
      next.addEventListener('click', () => move(1));
      list.addEventListener('scroll', schedule, { passive: true });
      const resizeObserver = typeof ResizeObserver === 'function' ? new ResizeObserver(schedule) : null;
      resizeObserver?.observe(list);
      cards.forEach((card) => resizeObserver?.observe(card));
      const cleanup = () => {
        if (frame) window.cancelAnimationFrame(frame);
        list.removeEventListener('scroll', schedule);
        resizeObserver?.disconnect();
      };
      this.carouselControllers.set(shell, { schedule, cleanup });
      nav.append(previous);
      if (config.features?.carouselIndicator !== false) nav.append(indicator);
      nav.append(next);
      shell.append(nav);
      window.requestAnimationFrame(schedule);
    }
    return shell;
  }

  productImagePlaceholder() {
    const placeholder = document.createElement('span');
    placeholder.className = 'ysai-product__image-placeholder';
    placeholder.setAttribute('role', 'img');
    placeholder.setAttribute('aria-label', config.texts?.imageUnavailable || 'تعذّر عرض الصورة');
    placeholder.append(this.createIcon([
      { tag: 'rect', attrs: { x: '3', y: '4', width: '18', height: '16', rx: '2' } },
      { tag: 'circle', attrs: { cx: '8.5', cy: '9', r: '1.5' } },
      { d: 'm21 15-5-5L5 20' },
    ]));
    return placeholder;
  }

  pruneCarousels() {
    for (const [shell, controller] of this.carouselControllers.entries()) {
      if (shell.isConnected) continue;
      controller.cleanup();
      this.carouselControllers.delete(shell);
    }
  }

  scheduleCarouselUpdates() {
    this.pruneCarousels();
    for (const controller of this.carouselControllers.values()) controller.schedule();
  }

  renderCart(cart) {
    if (!this.cart || !config.features?.cartSummary) return;
    this.cart.replaceChildren();
    this.cart.classList.remove('is-empty');
    if (!cart || !Array.isArray(cart.items)) {
      this.cart.hidden = true;
      return;
    }

    const heading = document.createElement('div');
    heading.className = 'ysai-cart__heading';
    const title = document.createElement('strong');
    const total = document.createElement('span');
    if (!cart.items.length) {
      this.cart.classList.add('is-empty');
      title.textContent = config.texts?.emptyCart || 'Cart is empty';
      total.textContent = String(cart.total_text || '');
    } else {
      title.textContent = `${config.texts?.cart || 'Cart'} · ${Number(cart.item_count || 0)}`;
      total.textContent = String(cart.total_text || '');
    }
    heading.append(title, total);
    this.cart.append(heading);

    const mutationNotice = String(cart.mutation_notice || cart.notice || '').trim();
    if (mutationNotice) {
      const notice = document.createElement('p');
      notice.className = 'ysai-cart__notice';
      notice.setAttribute('role', 'status');
      notice.textContent = mutationNotice;
      this.cart.append(notice);
    }

    if (cart.items.length) {
      const list = document.createElement('ul');
      const shownItems = cart.items.slice(0, 3);
      for (const item of shownItems) {
        const row = document.createElement('li');
        const name = document.createElement('span');
        name.textContent = `${String(item.name || '')} × ${Number(item.quantity || 0)}`;
        const price = document.createElement('strong');
        price.textContent = String(item.line_total_text || '');
        row.append(name, price);
        list.append(row);
      }
      const declaredLines = Math.max(Number(cart.line_count || 0), cart.items.length);
      const remaining = Math.max(0, declaredLines - shownItems.length);
      if (remaining > 0 || cart.items_truncated === true) {
        const more = document.createElement('li');
        more.className = 'ysai-cart__more';
        more.textContent = remaining > 0
          ? String(config.texts?.moreCartItems || '+%d عناصر أخرى').replace('%d', String(remaining))
          : (config.texts?.moreCartItemsUnknown || 'عناصر أخرى في السلة');
        list.append(more);
      }
      this.cart.append(list);
      const checkoutUrl = this.httpUrl(cart.checkout_url, true);
      if (checkoutUrl) {
        const checkout = document.createElement('a');
        checkout.className = 'ysai-checkout';
        checkout.href = checkoutUrl;
        checkout.textContent = config.texts?.checkout || 'Checkout';
        this.cart.append(checkout);
      }
    }
    this.cart.hidden = false;
  }

  setReply(reply) {
    this.reply = reply;
    if (this.replyPreview && this.replyText) {
      this.replyText.textContent = reply.text || '';
      const image = this.httpUrl(reply.image, false);
      if (this.replyMedia && this.replyImage && image) {
        this.replyImage.onerror = () => {
          if (this.replyMedia) this.replyMedia.hidden = true;
          if (this.replyImage) this.replyImage.removeAttribute('src');
        };
        this.replyImage.alt = truncateUnicode(String(reply.text || ''), 160);
        this.replyImage.src = image;
        this.replyMedia.hidden = false;
      } else {
        if (this.replyImage) this.replyImage.removeAttribute('src');
        if (this.replyMedia) this.replyMedia.hidden = true;
      }
      this.replyPreview.hidden = false;
    }
  }

  replyPayload() {
    if (!this.reply || !Number.isSafeInteger(Number(this.reply.message_id)) || Number(this.reply.message_id) <= 0) {
      return null;
    }
    const payload = { message_id: Number(this.reply.message_id) };
    const productRef = String(this.reply.product_ref || '');
    if (/^p_[A-Za-z0-9_-]{8,80}$/.test(productRef)) payload.product_ref = productRef;
    return payload;
  }

  clearReply() {
    this.reply = null;
    if (this.replyPreview) this.replyPreview.hidden = true;
    if (this.replyText) this.replyText.textContent = '';
    if (this.replyMedia) this.replyMedia.hidden = true;
    if (this.replyImage) {
      this.replyImage.onerror = null;
      this.replyImage.removeAttribute('src');
      this.replyImage.alt = '';
    }
  }

  async selectImage(file) {
    if (!file || this.destroyed) return;
    // A new selection replaces the prior attachment immediately. Invalid
    // replacements must never leave an older file silently queued for upload.
    this.clearImage();
    const generation = this.imageGeneration;
    const allowed = ['image/jpeg', 'image/png', 'image/webp'];
    if (!allowed.includes(file.type)) {
      this.showError(config.texts?.imageInvalid || 'Invalid image', null);
      return;
    }
    if (file.size > Number(config.limits?.imageBytes || 4194304)) {
      this.showError(config.texts?.imageTooLarge || 'Image too large', null);
      return;
    }

    this.imageProcessing = true;
    this.updateActionState();
    try {
      const dimensions = await this.imageDimensions(file);
      if (this.destroyed || generation !== this.imageGeneration) return;
      const maxDimension = Number(config.limits?.imageDimension || 4096);
      const maxPixels = Number(config.limits?.imagePixels || 12000000);
      if (dimensions.width < 1
        || dimensions.height < 1
        || dimensions.width > maxDimension
        || dimensions.height > maxDimension
        || dimensions.width * dimensions.height > maxPixels) {
        throw new Error('Unsupported image dimensions');
      }
      const dataUrl = await this.readFile(file);
      if (this.destroyed || generation !== this.imageGeneration) return;
      const base64 = String(dataUrl).split(',', 2)[1] || '';
      if (!base64) throw new Error('Invalid image');
      this.image = {
        mimeType: file.type,
        base64,
        name: file.name,
        width: dimensions.width,
        height: dimensions.height,
      };
      this.previewUrl = URL.createObjectURL(file);
      if (this.imagePreviewImage) {
        this.imagePreviewImage.onerror = () => {
          this.clearImage();
          this.showError(config.texts?.imageInvalid || 'Invalid image', null);
        };
        this.imagePreviewImage.alt = `${config.texts?.attachedImage || 'صورة مرفقة'}: ${file.name}`;
        this.imagePreviewImage.src = this.previewUrl;
      }
      if (this.imageName) this.imageName.textContent = file.name;
      if (this.imagePreview) this.imagePreview.hidden = false;
      this.hideError();
    } catch (error) {
      if (!this.destroyed && generation === this.imageGeneration) {
        this.clearImage();
        this.showError(config.texts?.imageDimensionsInvalid || config.texts?.imageInvalid || 'Invalid image', null);
      }
    } finally {
      if (generation === this.imageGeneration) {
        this.imageProcessing = false;
        this.updateActionState();
      }
    }
  }

  async imageDimensions(file) {
    if (typeof createImageBitmap === 'function') {
      const bitmap = await createImageBitmap(file);
      try {
        return { width: bitmap.width, height: bitmap.height };
      } finally {
        bitmap.close?.();
      }
    }
    const url = URL.createObjectURL(file);
    try {
      return await new Promise((resolve, reject) => {
        const image = new Image();
        image.onload = () => resolve({ width: image.naturalWidth, height: image.naturalHeight });
        image.onerror = () => reject(new Error('Invalid image'));
        image.src = url;
      });
    } finally {
      URL.revokeObjectURL(url);
    }
  }

  clearImage() {
    this.imageGeneration += 1;
    this.imageProcessing = false;
    this.image = null;
    if (this.previewUrl) URL.revokeObjectURL(this.previewUrl);
    this.previewUrl = '';
    if (this.imageInput) this.imageInput.value = '';
    if (this.imagePreviewImage) {
      this.imagePreviewImage.onerror = null;
      this.imagePreviewImage.removeAttribute('src');
      this.imagePreviewImage.alt = config.texts?.attachedImage || 'صورة مرفقة';
    }
    if (this.imagePreview) this.imagePreview.hidden = true;
    this.updateActionState();
  }

  async exportConversation() {
    const lifecycle = this.lifecycleToken();
    if (!this.lifecycleActive(lifecycle)) return;
    if (operationLocks(this).exportLocked) return;
    await this.ensureBoot();
    if (!this.lifecycleActive(lifecycle)) return;
    if (!this.credentials || operationLocks(this).exportLocked) return;
    const credentials = {
      id: String(this.credentials.id || ''),
      token: String(this.credentials.token || ''),
    };

    this.exporting = true;
    this.updateActionState();
    try {
      const exported = {
        ok: true,
        conversation_id: credentials.id,
        exported_at: '',
        upper_message_id: 0,
        complete: false,
        message_count: 0,
        page_count: 0,
        messages: [],
        shopping_memory: {},
      };
      let state = {
        upperMessageId: 0,
        afterMessageId: 0,
        messageCount: null,
        loadedCount: 0,
        complete: false,
      };
      let pageCount = 0;
      while (!state.complete && pageCount < 25) {
        const page = await this.api.post('conversation/export', {
          conversation_id: credentials.id,
          token: credentials.token,
          after_message_id: state.afterMessageId,
          upper_message_id: state.upperMessageId,
          limit: 200,
        });
        if (!this.lifecycleActive(lifecycle)) return;
        const validated = validateExportPage(state, page, credentials.id);
        if (pageCount === 0) {
          exported.exported_at = String(page.exported_at || new Date().toISOString());
          exported.shopping_memory = validated.shoppingMemory;
        }
        exported.messages.push(...validated.messages);
        state = validated;
        pageCount += 1;
      }
      if (!state.complete) {
        throw new Error(config.texts?.exportIncomplete || 'Conversation export exceeded the safe page limit.');
      }
      if (!this.lifecycleActive(lifecycle)) return;

      exported.upper_message_id = state.upperMessageId;
      exported.complete = true;
      exported.message_count = state.messageCount;
      exported.page_count = pageCount;

      const blob = new Blob([JSON.stringify(exported, null, 2)], { type: 'application/json;charset=utf-8' });
      const url = URL.createObjectURL(blob);
      try {
        const link = document.createElement('a');
        link.href = url;
        link.download = `${config.texts?.exportName || 'conversation'}-${new Date().toISOString().slice(0, 10)}.json`;
        document.body.append(link);
        link.click();
        link.remove();
      } finally {
        window.setTimeout(() => URL.revokeObjectURL(url), 1000);
      }
    } catch (error) {
      if (!this.lifecycleActive(lifecycle)) return;
      if (errorConfirmsConversationUnauthorized(error)) {
        this.resetConversationView(true);
        this.showError(error.message || config.texts?.genericError, () => this.ensureBoot());
      } else {
        this.showError(error.message || config.texts?.genericError, null);
      }
    } finally {
      if (this.lifecycleActive(lifecycle)) {
        this.exporting = false;
        this.updateActionState();
      }
    }
  }

  async deleteConversation() {
    const lifecycle = this.lifecycleToken();
    if (!this.lifecycleActive(lifecycle)) return;
    if (operationLocks(this).deleteLocked) return;
    await this.ensureBoot();
    if (!this.lifecycleActive(lifecycle)) return;
    if (!this.credentials || operationLocks(this).deleteLocked) return;
    if (this.state.pending()) {
      this.showError(
        config.texts?.processing || 'الطلب السابق ما زال قيد المعالجة.',
        () => this.retryPending(),
      );
      return;
    }
    if (!window.confirm(config.texts?.deleteConfirm || 'Delete conversation?')) return;
    const originalCredentials = {
      id: String(this.credentials.id || ''),
      token: String(this.credentials.token || ''),
    };
    this.deleting = true;
    this.updateActionState();
    try {
      try {
        validateDeleteResponse(await this.api.post('conversation/delete', {
          conversation_id: originalCredentials.id,
          token: originalCredentials.token,
        }));
        if (!this.lifecycleActive(lifecycle)) return;
      } catch (error) {
        if (!this.lifecycleActive(lifecycle)) return;
        if (errorConfirmsConversationUnauthorized(error)) {
          // The server conclusively rejected this stale capability before a
          // deletion could be authorized. The shopper asked to remove the local
          // conversation, so clear the browser copy and offer a clean boot.
          this.resetConversationView(true);
          this.showError(error.message || config.texts?.genericError, () => this.ensureBoot());
          return;
        }
        await this.reconcileDeleteOutcome(originalCredentials);
        return;
      }

      // The exact acknowledgement is durable proof of deletion. A subsequent
      // failure to create a fresh conversation must not downgrade that proof or
      // attempt to reuse the deleted capability.
      this.resetConversationView(true);
      try {
        await this.ensureBoot();
      } catch (error) {
        if (!this.lifecycleActive(lifecycle)) return;
        this.showError(error?.message || config.texts?.genericError, () => this.ensureBoot());
      }
    } finally {
      if (this.lifecycleActive(lifecycle)) {
        this.deleting = false;
        this.updateActionState();
      }
    }
  }

  resetConversationView(clearStoredCredentials = false) {
    if (clearStoredCredentials) this.state.clearCredentials();
    this.state.clearPending();
    this.credentials = null;
    this.booted = false;
    this.connected = false;
    this.messages?.querySelectorAll('[data-ysai-message], [data-ysai-day]').forEach((node) => node.remove());
    this.pruneCarousels();
    this.renderedTurnIds.clear();
    this.renderedClientTurnIds.clear();
    this.lastMessageDay = '';
    this.lastMessageRole = '';
    this.lastMessageTimestamp = 0;
    this.setUnread(0);
    this.clearReply();
    this.clearImage();
    this.renderCart(null);
    this.updateEmpty();
    this.setStatus(config.texts?.offline || 'Offline', false);
  }

  async reconcileDeleteOutcome(originalCredentials) {
    const lifecycle = this.lifecycleToken();
    if (!this.lifecycleActive(lifecycle)) return;
    // A lost or malformed acknowledgement is ambiguous: the server may have
    // committed the deletion even though the browser did not receive proof.
    // Stop using the in-memory capability and ask the server whether that exact
    // capability still resumes before allowing any later chat operation.
    this.credentials = null;
    this.booted = false;
    this.connected = false;
    this.renderCart(null);
    this.setStatus(config.texts?.offline || 'Offline', false);

    try {
      const response = validateBootResponse(await this.api.post('boot', {
        conversation_id: originalCredentials.id,
        token: originalCredentials.token,
      }));
      if (!this.lifecycleActive(lifecycle)) return;
      const resumedOriginal = sameConversation(response.conversation, {
        conversation_id: originalCredentials.id,
        token: originalCredentials.token,
      });
      if (!resumedOriginal) {
        this.state.clearPending();
        this.clearReply();
        this.clearImage();
      }
      if (!this.applyBootSnapshot(response)) return;
      if (resumedOriginal) {
        this.showError(
          config.texts?.deleteNotConfirmed
            || 'تعذّر تأكيد حذف المحادثة، وما زالت الجلسة السابقة متاحة.',
          () => this.deleteConversation(),
        );
      } else {
        this.showError(
          config.texts?.deleteOutcomeReplaced
            || 'فُقد تأكيد الحذف وتعذّر استئناف المحادثة السابقة. بدأت جلسة جديدة دون افتراض أن الحذف اكتمل.',
          null,
        );
      }
    } catch (recoveryError) {
      if (!this.lifecycleActive(lifecycle)) return;
      // Preserve the original capability only as a future boot/reconciliation
      // input. It is deliberately removed from active memory so a later submit
      // cannot keep chatting through authority whose deletion state is unknown.
      // The shopper requested deletion, so also erase the stale transcript and
      // cart from the page even though the server outcome remains unresolved.
      this.resetConversationView(false);
      this.state.saveCredentials(originalCredentials);
      this.showError(
        config.texts?.deleteOutcomeUnknown
          || 'فُقد تأكيد الحذف وتعذّر التحقق من حالة المحادثة. أعد الاتصال قبل أي إجراء آخر.',
        () => this.ensureBoot(),
      );
    }
  }

  handlePrivacyMenuKeydown(event) {
    if (!this.privacyMenu || this.privacyMenu.hidden) return;
    const items = Array.from(this.privacyMenu.querySelectorAll('[role="menuitem"]'))
      .filter((item) => !item.disabled);
    if (items.length === 0) return;
    const current = Math.max(0, items.indexOf(document.activeElement));
    let target = null;
    if (event.key === 'ArrowDown') target = items[(current + 1) % items.length];
    if (event.key === 'ArrowUp') target = items[(current - 1 + items.length) % items.length];
    if (event.key === 'Home') target = items[0];
    if (event.key === 'End') target = items[items.length - 1];
    if (event.key === 'Tab') this.closePrivacyMenu(false);
    if (target) {
      event.preventDefault();
      target.focus();
    }
  }

  togglePrivacyMenu() {
    if (!this.privacyMenu || !this.privacyToggle) return;
    const opening = this.privacyMenu.hidden;
    this.privacyMenu.hidden = !opening;
    this.privacyToggle.setAttribute('aria-expanded', opening ? 'true' : 'false');
    if (opening) {
      this.privacyMenu.querySelector('button')?.focus({ preventScroll: true });
    }
  }

  closePrivacyMenu(returnFocus = false) {
    if (!this.privacyMenu || !this.privacyToggle || this.privacyMenu.hidden) return;
    this.privacyMenu.hidden = true;
    this.privacyToggle.setAttribute('aria-expanded', 'false');
    if (returnFocus) this.privacyToggle.focus();
  }

  announce(message) {
    if (!this.announcer) return;
    if (this.announcementTimer) window.clearTimeout(this.announcementTimer);
    const lifecycle = this.lifecycleToken();
    this.announcer.textContent = '';
    this.announcementTimer = window.setTimeout(() => {
      this.announcementTimer = 0;
      if (this.lifecycleActive(lifecycle) && this.announcer) {
        this.announcer.textContent = String(message || '');
      }
    }, 10);
  }

  isNearBottom() {
    if (!this.messages) return true;
    return this.messages.scrollHeight - this.messages.scrollTop - this.messages.clientHeight <= 76;
  }

  setUnread(value) {
    const enabled = config.features?.unreadButton !== false;
    this.unreadCount = enabled ? Math.max(0, Math.min(100, Number(value) || 0)) : 0;
    this.updateUnreadPresentation(enabled);
    this.updateScrollControls();
  }

  updateUnreadPresentation(enabled = config.features?.unreadButton !== false) {
    const label = this.unreadCount >= 100 ? '99+' : String(this.unreadCount);
    if (this.launcherUnread) {
      this.launcherUnread.textContent = label;
      this.launcherUnread.hidden = !enabled || this.unreadCount <= 0;
    }
    if (this.latestCount) {
      this.latestCount.textContent = label;
      this.latestCount.hidden = !enabled || this.unreadCount <= 0;
    }
    if (this.openButton) {
      const baseLabel = this.launcherBaseLabel || 'فتح المساعد';
      const unreadTemplate = String(config.texts?.unreadCount || 'رسائل جديدة: %d');
      const unreadLabel = unreadTemplate.replace('%d', label);
      this.openButton.setAttribute(
        'aria-label',
        enabled && this.unreadCount > 0 ? `${baseLabel}، ${unreadLabel}` : baseLabel,
      );
    }
  }

  updateScrollControls() {
    if (!this.latestButton) return;
    if (config.features?.unreadButton === false) {
      this.latestButton.hidden = true;
      if (this.launcherUnread) this.launcherUnread.hidden = true;
      if (this.latestCount) this.latestCount.hidden = true;
      return;
    }
    const panelOpen = Boolean(this.panel && !this.panel.hidden);
    const nearBottom = this.isNearBottom();
    if (panelOpen && nearBottom && this.unreadCount > 0) {
      this.unreadCount = 0;
      this.updateUnreadPresentation();
    }
    this.latestButton.hidden = !panelOpen || nearBottom;
  }

  updateTyping() {
    if (!this.typing) return;
    const active = Boolean(this.sending || this.recovering);
    this.typing.hidden = !active;
    if (active && this.panel && !this.panel.hidden && this.isNearBottom()) {
      this.scrollToBottom(false);
    }
  }

  setBusy(value) {
    this.sending = Boolean(value);
    this.updateActionState();
    this.updateTyping();
    this.root.classList.toggle('is-busy', this.sending);
    this.setStatus(
      this.sending
        ? (config.texts?.thinking || 'Thinking')
        : (this.connected ? (config.texts?.online || 'Online') : (config.texts?.offline || 'Offline')),
      this.connected,
    );
  }

  setRecovering(value) {
    this.recovering = Boolean(value);
    this.updateActionState();
    this.updateTyping();
    this.root.classList.toggle('is-recovering', this.recovering);
    if (!this.sending) {
      this.setStatus(
        this.recovering
          ? (config.texts?.processing || 'Processing')
          : (this.connected ? (config.texts?.online || 'Online') : (config.texts?.offline || 'Offline')),
        this.connected,
      );
    }
  }

  draftState() {
    const message = String(this.input?.value || '');
    const length = unicodeLength(message);
    const limit = Number(config.limits?.messageCharacters || 4000);
    return {
      length,
      limit,
      overLimit: length > limit,
      hasContent: message.trim() !== '' || Boolean(this.image),
    };
  }

  updateActionState() {
    const locks = operationLocks(this);
    const pending = Boolean(this.state.pending());
    const draft = this.draftState();
    const offline = navigator.onLine === false;
    const interactionLocked = Boolean(this.sending || this.recovering || this.deleting || pending || this.imageProcessing);
    const sendDisabled = Boolean(
      locks.sendLocked
      || pending
      || this.imageProcessing
      || offline
      || draft.overLimit
      || !draft.hasContent,
    );
    if (this.sendButton) {
      this.sendButton.disabled = sendDisabled;
      this.sendButton.setAttribute('aria-disabled', sendDisabled ? 'true' : 'false');
    }
    if (this.input) {
      this.input.disabled = interactionLocked;
      this.input.setAttribute('aria-invalid', draft.overLimit ? 'true' : 'false');
    }
    if (this.imageInput) this.imageInput.disabled = interactionLocked;
    if (this.attachControl) {
      this.attachControl.setAttribute('aria-disabled', interactionLocked ? 'true' : 'false');
      this.attachControl.setAttribute('aria-busy', this.imageProcessing ? 'true' : 'false');
    }
    if (this.form) this.form.setAttribute('aria-busy', this.imageProcessing ? 'true' : 'false');
    if (this.exportButton) this.exportButton.disabled = locks.exportLocked;
    if (this.deleteButton) this.deleteButton.disabled = Boolean(locks.deleteLocked || pending);
    if (this.characterCount) {
      this.characterCount.textContent = `${draft.length} / ${draft.limit}`;
      this.characterCount.classList.toggle('is-over-limit', draft.overLimit);
      this.characterCount.classList.toggle('is-near-limit', !draft.overLimit && draft.length >= Math.max(0, draft.limit - 300));
    }
    this.form?.classList.toggle('is-over-limit', draft.overLimit);
  }

  setStatus(text, online) {
    if (this.statusText) this.statusText.textContent = String(text || '');
    if (this.status) this.status.classList.toggle('is-online', Boolean(online));
    this.root.classList.toggle('is-connected', Boolean(online));
    this.root.classList.toggle('is-offline', !online);
    this.root.querySelectorAll('[data-ysai-presence-dot]').forEach((dot) => {
      dot.setAttribute('aria-hidden', 'true');
    });
    this.updateActionState();
  }

  showDeferredRetry(message, retryAfterSeconds, retry, retryLabel = '') {
    const seconds = Math.max(1, Math.min(86400, Math.trunc(Number(retryAfterSeconds) || 0)));
    const template = String(config.texts?.retryAfter || 'يمكن إعادة المحاولة بعد %d ثانية.');
    const delayedMessage = `${String(message || '')} ${template.replace('%d', String(seconds))}`.trim();
    this.showError(delayedMessage, null);
    const lifecycle = this.lifecycleToken();
    this.retryTimer = window.setTimeout(() => {
      this.retryTimer = 0;
      if (!this.lifecycleActive(lifecycle) || typeof retry !== 'function') return;
      this.showError(message, retry, retryLabel);
    }, seconds * 1000);
  }

  showError(message, retry, retryLabel = '') {
    if (this.retryTimer) {
      window.clearTimeout(this.retryTimer);
      this.retryTimer = 0;
    }
    if (!this.error) return;
    this.error.replaceChildren();
    const text = document.createElement('span');
    text.textContent = String(message || config.texts?.genericError || 'Error');
    this.error.append(text);
    if (typeof retry === 'function') {
      const button = document.createElement('button');
      button.type = 'button';
      button.textContent = retryLabel || config.texts?.retry || 'Retry';
      button.addEventListener('click', retry, { once: true });
      this.error.append(button);
    }
    this.error.hidden = false;
  }

  hideError() {
    if (this.retryTimer) {
      window.clearTimeout(this.retryTimer);
      this.retryTimer = 0;
    }
    if (!this.error) return;
    this.error.hidden = true;
    this.error.replaceChildren();
  }

  updateEmpty() {
    if (!this.empty || !this.messages) return;
    this.empty.hidden = Boolean(this.messages.querySelector('[data-ysai-message]'));
  }

  resizeInput() {
    if (!this.input) return;
    this.input.style.height = 'auto';
    this.input.style.height = `${Math.min(140, Math.max(44, this.input.scrollHeight))}px`;
  }

  restoreTranscriptPosition() {
    if (!this.messages) return;
    const messages = this.messages.querySelectorAll('[data-ysai-message]');
    const latest = messages.length > 0 ? messages[messages.length - 1] : null;
    if (latest?.dataset.messageRole === 'assistant'
      && latest.querySelector('.ysai-products-shell')) {
      this.revealMessageStart(latest);
      return;
    }
    this.scrollToBottom(false);
  }

  revealMessageStart(article) {
    if (!this.messages || !(article instanceof HTMLElement)) return;
    const lifecycle = this.lifecycleToken();
    window.requestAnimationFrame(() => {
      if (!this.lifecycleActive(lifecycle) || !this.messages || !article.isConnected) return;
      const viewportHeight = this.messages.clientHeight;
      const messageHeight = article.getBoundingClientRect().height;
      const containsProducts = Boolean(article.querySelector('.ysai-products-shell'));
      if (!containsProducts && messageHeight < viewportHeight * 0.72) {
        this.scrollToBottom(false);
        return;
      }
      const maximum = Math.max(0, this.messages.scrollHeight - viewportHeight);
      const target = Math.max(0, Math.min(maximum, article.offsetTop - 12));
      if (typeof this.messages.scrollTo === 'function') {
        this.messages.scrollTo({ top: target, behavior: 'auto' });
      } else {
        this.messages.scrollTop = target;
      }
      this.setUnread(0);
    });
  }

  scrollToBottom(smooth = false) {
    if (!this.messages) return;
    const apply = (behavior) => {
      if (!this.messages) return;
      if (typeof this.messages.scrollTo === 'function') {
        this.messages.scrollTo({
          top: this.messages.scrollHeight,
          behavior,
        });
      } else {
        this.messages.scrollTop = this.messages.scrollHeight;
      }
      this.setUnread(0);
    };
    apply(smooth ? 'smooth' : 'auto');
    // A cart strip, product image ratio, or font can change the viewport after
    // the first layout pass. Re-anchor once on the next frame without adding a
    // visible second animation.
    window.requestAnimationFrame(() => apply('auto'));
  }

  readFile(file) {
    return new Promise((resolve, reject) => {
      const reader = new FileReader();
      reader.onload = () => resolve(reader.result);
      reader.onerror = () => reject(reader.error);
      reader.readAsDataURL(file);
    });
  }

  async copyText(text) {
    if (navigator.clipboard?.writeText) {
      try {
        await navigator.clipboard.writeText(text);
        return true;
      } catch (error) {
        // Use the bounded legacy fallback below.
      }
    }
    const area = document.createElement('textarea');
    const previousFocus = document.activeElement;
    area.value = text;
    area.style.position = 'fixed';
    area.style.opacity = '0';
    area.setAttribute('readonly', '');
    (this.panel || document.body).append(area);
    area.select();
    let copied = false;
    try {
      copied = Boolean(document.execCommand?.('copy'));
    } catch (error) {
      copied = false;
    }
    area.remove();
    if (previousFocus instanceof HTMLElement && previousFocus.isConnected) {
      previousFocus.focus({ preventScroll: true });
    }
    return copied;
  }

  httpUrl(value, sameOrigin) {
    return safeHttpUrl(value, window.location.origin, sameOrigin);
  }
}

const widgetInstances = new WeakMap();

function initializeWidgetRoot(root) {
  if (!(root instanceof Element) || !root.matches('[data-ysai-root]')) return;
  const existing = widgetInstances.get(root);
  if (existing && !existing.destroyed) return;
  widgetInstances.set(root, new AssistantWidget(root));
}

function initializeWidgetCandidates(node) {
  if (!(node instanceof Element)) return;
  initializeWidgetRoot(node);
  node.querySelectorAll?.('[data-ysai-root]').forEach((root) => initializeWidgetRoot(root));
}

document.querySelectorAll('[data-ysai-root]').forEach((root) => initializeWidgetRoot(root));

const widgetInsertionObserver = typeof MutationObserver === 'function' && document.documentElement
  ? new MutationObserver((mutations) => {
    for (const mutation of mutations) {
      mutation.addedNodes.forEach((node) => initializeWidgetCandidates(node));
    }
  })
  : null;
widgetInsertionObserver?.observe(document.documentElement, { childList: true, subtree: true });
window.addEventListener('pagehide', () => widgetInsertionObserver?.disconnect(), { once: true });
