
export function clampRequestTimeout(value, fallback = 180000) {
  const requested = Number(value);
  const safeFallback = Number.isFinite(Number(fallback)) ? Number(fallback) : 180000;
  return Number.isFinite(requested)
    ? Math.max(30_000, Math.min(1_185_000, Math.floor(requested)))
    : Math.max(30_000, Math.min(1_185_000, Math.floor(safeFallback)));
}

export function normalizeRestBase(value) {
  const base = String(value || '').trim();
  return base === '' ? '' : `${base.replace(/\/*$/, '')}/`;
}

function responseReadError(message, code = 'invalid_response') {
  const error = new Error(message);
  error.code = code;
  return error;
}

/**
 * Read a JSON response without allowing a proxy, service worker, or broken
 * endpoint to allocate an unbounded browser buffer before schema validation.
 */
export async function readBoundedJsonResponse(response, maximumBytes = 16 * 1024 * 1024) {
  const limit = Number(maximumBytes);
  if (!response || typeof response !== 'object'
    || !Number.isSafeInteger(limit) || limit < 1 || limit > 64 * 1024 * 1024) {
    throw responseReadError('The response reader configuration is invalid.');
  }

  const lengthHeader = response.headers?.get?.('content-length');
  if (lengthHeader !== null && lengthHeader !== undefined) {
    const normalized = String(lengthHeader).trim();
    if (!/^(?:0|[1-9][0-9]*)$/.test(normalized)) {
      throw responseReadError('The response length header is invalid.');
    }
    const declared = Number(normalized);
    if (!Number.isSafeInteger(declared) || declared > limit) {
      throw responseReadError('The server response exceeds the safe browser limit.', 'response_too_large');
    }
  }

  let bytes;
  if (response.body && typeof response.body.getReader === 'function') {
    const reader = response.body.getReader();
    const chunks = [];
    let total = 0;
    try {
      while (true) {
        const result = await reader.read();
        if (result.done) break;
        if (!(result.value instanceof Uint8Array)) {
          throw responseReadError('The response stream returned invalid bytes.');
        }
        total += result.value.byteLength;
        if (!Number.isSafeInteger(total) || total > limit) {
          try { await reader.cancel(); } catch (error) { /* Best-effort cancellation. */ }
          throw responseReadError('The server response exceeds the safe browser limit.', 'response_too_large');
        }
        chunks.push(result.value);
      }
    } finally {
      try { reader.releaseLock(); } catch (error) { /* Reader may already be released. */ }
    }
    bytes = new Uint8Array(total);
    let offset = 0;
    for (const chunk of chunks) {
      bytes.set(chunk, offset);
      offset += chunk.byteLength;
    }
  } else if (typeof response.arrayBuffer === 'function') {
    const buffer = await response.arrayBuffer();
    if (!(buffer instanceof ArrayBuffer) || buffer.byteLength > limit) {
      throw responseReadError('The server response exceeds the safe browser limit.', 'response_too_large');
    }
    bytes = new Uint8Array(buffer);
  } else {
    throw responseReadError('The server response body is unavailable.');
  }

  if (bytes.byteLength === 0) {
    throw responseReadError('The server returned an empty response.');
  }

  let text;
  try {
    text = new TextDecoder('utf-8', { fatal: true }).decode(bytes);
  } catch (error) {
    throw responseReadError('The server response is not valid UTF-8.');
  }
  if (text.charCodeAt(0) === 0xFEFF) text = text.slice(1);
  try {
    return JSON.parse(text);
  } catch (error) {
    throw responseReadError('The server response is not valid JSON.');
  }
}

export function unicodeLength(value) {
  return Array.from(String(value ?? '')).length;
}

export function truncateUnicode(value, maximum) {
  const limit = Number(maximum);
  if (!Number.isSafeInteger(limit) || limit < 0) return '';
  return Array.from(String(value ?? '')).slice(0, limit).join('');
}

export function createTurnId(cryptoObject = globalThis.crypto) {
  if (cryptoObject && typeof cryptoObject.randomUUID === 'function') {
    return cryptoObject.randomUUID();
  }

  if (!cryptoObject || typeof cryptoObject.getRandomValues !== 'function') {
    throw new Error('Secure browser randomness is unavailable.');
  }

  const bytes = new Uint8Array(18);
  cryptoObject.getRandomValues(bytes);
  return Array.from(bytes, (value) => value.toString(16).padStart(2, '0')).join('');
}

export function validConversationCredentials(value) {
  const id = String(value?.id || '');
  const token = String(value?.token || '');
  return /^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i.test(id)
    && /^[A-Za-z0-9_-]{40,100}$/.test(token);
}

export function selectBootCredentials(saved, pending) {
  const body = pending?.body && typeof pending.body === 'object' ? pending.body : pending;
  const pendingCredentials = {
    id: String(body?.conversation_id || ''),
    token: String(body?.token || ''),
  };
  if (validConversationCredentials(pendingCredentials)) return pendingCredentials;
  return validConversationCredentials(saved) ? { id: String(saved.id), token: String(saved.token) } : null;
}

export function sameConversation(credentials, requestBody) {
  const currentId = String(credentials?.id || '');
  const currentToken = String(credentials?.token || '');
  const requestId = String(requestBody?.conversation_id || '');
  const requestToken = String(requestBody?.token || '');
  return currentId !== ''
    && currentToken !== ''
    && currentId === requestId
    && currentToken === requestToken;
}

function isRecord(value) {
  return Boolean(value) && typeof value === 'object' && !Array.isArray(value);
}

function hasOnlyKeys(value, allowed) {
  if (!isRecord(value)) return false;
  const accepted = new Set(allowed);
  return Object.keys(value).every((key) => accepted.has(key));
}

function hasExactKeys(value, required, optional = []) {
  if (!hasOnlyKeys(value, [...required, ...optional])) return false;
  return required.every((key) => Object.prototype.hasOwnProperty.call(value, key));
}

function positiveSafeInteger(value) {
  return Number.isSafeInteger(value) && value > 0;
}

function nonNegativeSafeInteger(value, maximum = Number.MAX_SAFE_INTEGER) {
  return Number.isSafeInteger(value) && value >= 0 && value <= maximum;
}

function validFiniteNumber(value, minimum = 0, maximum = 1_000_000_000_000) {
  return typeof value === 'number'
    && Number.isFinite(value)
    && value >= minimum
    && value <= maximum;
}

function validString(value, maximum, minimum = 0) {
  return typeof value === 'string'
    && unicodeLength(value) >= minimum
    && unicodeLength(value) <= maximum;
}

function validUuidV4(value) {
  return typeof value === 'string'
    && /^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i.test(value);
}

function validTurnId(value) {
  return typeof value === 'string' && /^[A-Za-z0-9_-]{16,64}$/.test(value);
}

function validIsoTimestamp(value) {
  return typeof value === 'string'
    && value.length >= 20
    && value.length <= 40
    && Number.isFinite(Date.parse(value));
}

function validBoundedStringList(value, maximumItems, maximumLength) {
  return Array.isArray(value)
    && value.length <= maximumItems
    && value.every((item) => validString(item, maximumLength, 1));
}

function validStringListMap(value, maximumEntries = 24, maximumOptions = 40) {
  if (!isRecord(value) || Object.keys(value).length > maximumEntries) return false;
  return Object.entries(value).every(([key, options]) => (
    validString(key, 160, 1)
    && validBoundedStringList(options, maximumOptions, 200)
  ));
}

function validStringMap(value, maximumEntries = 12, maximumKey = 160, maximumValue = 200) {
  if (!isRecord(value) || Object.keys(value).length > maximumEntries) return false;
  return Object.entries(value).every(([key, item]) => (
    validString(key, maximumKey, 1) && validString(item, maximumValue, 1)
  ));
}

function validImageMetadata(value) {
  return hasExactKeys(value, ['mime_type', 'bytes', 'width', 'height'])
    && ['image/jpeg', 'image/png', 'image/webp'].includes(value.mime_type)
    && positiveSafeInteger(value.bytes)
    && value.bytes <= 4_194_304
    && positiveSafeInteger(value.width)
    && value.width <= 4096
    && positiveSafeInteger(value.height)
    && value.height <= 4096
    && value.width * value.height <= 12_000_000;
}

function validProductCard(value) {
  const required = [
    'ref', 'name', 'sku', 'type', 'price', 'price_available', 'price_kind',
    'regular_price', 'sale_price', 'price_text', 'currency', 'in_stock',
    'stock_status', 'stock_quantity', 'rating', 'review_count',
    'short_description', 'image', 'url', 'purchasable', 'requires_options',
    'categories', 'categories_truncated', 'attributes', 'attributes_truncated',
    'variation_options', 'variation_options_truncated',
  ];
  if (!hasExactKeys(value, required)
    || !/^p_[A-Za-z0-9_-]{8,80}$/.test(value.ref)
    || !validString(value.name, 300, 1)
    || !validString(value.sku, 120)
    || !validString(value.type, 40, 1)
    || !/^[a-z][a-z0-9_-]*$/.test(value.type)
    || typeof value.price_available !== 'boolean'
    || !['fixed', 'from', 'unavailable'].includes(value.price_kind)
    || (value.price !== null && !validFiniteNumber(value.price))
    || (value.regular_price !== null && !validFiniteNumber(value.regular_price))
    || (value.sale_price !== null && !validFiniteNumber(value.sale_price))
    || !validString(value.price_text, 200)
    || !validString(value.currency, 12)
    || typeof value.in_stock !== 'boolean'
    || !validString(value.stock_status, 40)
    || (value.stock_quantity !== null
      && (!Number.isSafeInteger(value.stock_quantity)
        || value.stock_quantity < -2_000_000_000
        || value.stock_quantity > 2_000_000_000))
    || !validFiniteNumber(value.rating, 0, 5)
    || !nonNegativeSafeInteger(value.review_count, 2_000_000_000)
    || !validString(value.short_description, 500)
    || !validString(value.image, 2048)
    || !validString(value.url, 2048)
    || typeof value.purchasable !== 'boolean'
    || typeof value.requires_options !== 'boolean'
    || !validBoundedStringList(value.categories, 8, 160)
    || typeof value.categories_truncated !== 'boolean'
    || !validStringListMap(value.attributes)
    || typeof value.attributes_truncated !== 'boolean'
    || !validStringListMap(value.variation_options)
    || typeof value.variation_options_truncated !== 'boolean') {
    return false;
  }

  if (value.price_kind === 'unavailable') {
    return value.price === null && value.price_available === false;
  }
  return value.price_available === true && validFiniteNumber(value.price);
}

function validCartItem(value) {
  return hasExactKeys(value, [
    'name', 'quantity', 'unit_price', 'line_total', 'line_total_text',
    'image', 'variation', 'sku', 'ref',
  ])
    && validString(value.name, 500, 1)
    && positiveSafeInteger(value.quantity)
    && value.quantity <= 2_000_000_000
    && validFiniteNumber(value.unit_price)
    && validFiniteNumber(value.line_total)
    && validString(value.line_total_text, 200)
    && validString(value.image, 2048)
    && validStringMap(value.variation)
    && validString(value.sku, 120)
    && (value.ref === '' || /^l_[A-Za-z0-9_-]{8,80}$/.test(value.ref));
}

function validCart(value) {
  const required = [
    'items', 'line_count', 'items_truncated', 'item_count', 'total',
    'total_text', 'currency', 'cart_url', 'checkout_url', 'cart_hash',
    'mutations_allowed', 'mutation_notice',
  ];
  const optional = ['presentation_incomplete', 'notice'];
  if (!hasExactKeys(value, required, optional)
    || !Array.isArray(value.items)
    || value.items.length > 100
    || value.items.some((item) => !validCartItem(item))
    || !nonNegativeSafeInteger(value.line_count, 100_000)
    || value.line_count < value.items.length
    || typeof value.items_truncated !== 'boolean'
    || value.items_truncated !== (value.line_count > value.items.length)
    || !nonNegativeSafeInteger(value.item_count, 2_000_000_000)
    || !validFiniteNumber(value.total)
    || !validString(value.total_text, 200)
    || !validString(value.currency, 12)
    || !validString(value.cart_url, 2048)
    || !validString(value.checkout_url, 2048)
    || !validString(value.cart_hash, 256)
    || typeof value.mutations_allowed !== 'boolean'
    || !validString(value.mutation_notice, 600)
    || (value.presentation_incomplete !== undefined && value.presentation_incomplete !== true)
    || (value.notice !== undefined && !validString(value.notice, 600, 1))) {
    return false;
  }
  const displayedItemCount = value.items.reduce((total, item) => total + item.quantity, 0);
  if (!Number.isSafeInteger(displayedItemCount) || value.item_count < displayedItemCount) {
    return false;
  }
  const hasPresentationNotice = Object.prototype.hasOwnProperty.call(value, 'notice');
  if ((value.presentation_incomplete === true) !== hasPresentationNotice) {
    return false;
  }
  return true;
}

function boundedJsonValue(value, state = { nodes: 0 }, depth = 0) {
  state.nodes += 1;
  if (state.nodes > 1000 || depth > 8) return false;
  if (value === null || typeof value === 'boolean') return true;
  if (typeof value === 'number') return Number.isFinite(value) && Math.abs(value) <= 1_000_000_000_000;
  if (typeof value === 'string') return validString(value, 3000);
  if (Array.isArray(value)) {
    return value.length <= 100 && value.every((item) => boundedJsonValue(item, state, depth + 1));
  }
  if (!isRecord(value) || Object.keys(value).length > 100) return false;
  return Object.entries(value).every(([key, item]) => (
    validString(key, 160, 1) && boundedJsonValue(item, state, depth + 1)
  ));
}

function validReceipt(value) {
  return hasExactKeys(value, ['id', 'message', 'lines', 'cart'])
    && validUuidV4(value.id)
    && validString(value.message, 3000, 1)
    && Array.isArray(value.lines)
    && value.lines.length <= 12
    && value.lines.every((line) => isRecord(line) && boundedJsonValue(line))
    && validCart(value.cart);
}

function validErrorObject(value) {
  const retryMode = value?.retry_mode;
  const retryAfterSeconds = value?.retry_after_seconds;
  return hasExactKeys(value, ['code', 'message', 'retryable'], [
    'retry_mode', 'retry_after_seconds',
  ])
    && validString(value.code, 64, 1)
    && /^[a-z][a-z0-9_]{0,63}$/.test(value.code)
    && validString(value.message, 600, 1)
    && typeof value.retryable === 'boolean'
    && (retryMode === undefined
      || (['none', 'same_turn', 'new_turn'].includes(retryMode)
        && value.retryable === (retryMode !== 'none')))
    && (retryAfterSeconds === undefined
      || (Number.isSafeInteger(retryAfterSeconds)
        && retryAfterSeconds >= 1
        && retryAfterSeconds <= 86400
        && value.retryable === true));
}

function validHistoryMessage(value) {
  if (!hasExactKeys(value, ['id', 'turn_id', 'role', 'content', 'created_at'], [
    'kind', 'products', 'cart', 'receipt', 'has_image', 'image', 'client_turn_id',
  ])
    || !positiveSafeInteger(value.id)
    || !positiveSafeInteger(value.turn_id)
    || !['user', 'assistant'].includes(value.role)
    || !validString(value.content, 8192, 1)
    || !validIsoTimestamp(value.created_at)
    || (value.client_turn_id !== undefined && !validTurnId(value.client_turn_id))
    || (value.kind !== undefined
      && !['answer', 'follow_up', 'safe_failure', 'cart_receipt', 'cart_uncertain'].includes(value.kind))
    || (value.products !== undefined
      && (!Array.isArray(value.products)
        || value.products.length > 12
        || value.products.some((product) => !validProductCard(product))))
    || (value.cart !== undefined && value.cart !== null && !validCart(value.cart))
    || (value.receipt !== undefined && value.receipt !== null && !validReceipt(value.receipt))) {
    return false;
  }

  if (value.role === 'user') {
    if (value.kind !== undefined
      || value.products !== undefined
      || value.cart !== undefined
      || value.receipt !== undefined) {
      return false;
    }
  } else if (value.client_turn_id !== undefined
    || value.has_image !== undefined
    || value.image !== undefined
    || value.kind === undefined) {
    return false;
  }

  if ((value.kind === 'answer' || value.kind === 'follow_up')
    && (value.cart !== undefined || value.receipt !== undefined)) {
    return false;
  }
  const failedKind = value.kind === 'safe_failure' || value.kind === 'cart_uncertain';
  if (failedKind
    && ((value.products !== undefined && value.products.length !== 0)
      || (value.cart !== undefined && value.cart !== null)
      || (value.receipt !== undefined && value.receipt !== null))) {
    return false;
  }
  if (value.kind === 'cart_receipt') {
    if (!validReceipt(value.receipt)
      || !validCart(value.cart)
      || (value.products !== undefined && value.products.length !== 0)
      || JSON.stringify(value.cart) !== JSON.stringify(value.receipt.cart)) {
      return false;
    }
  } else if (value.receipt !== undefined && value.receipt !== null) {
    return false;
  }

  const hasImage = value.has_image === true;
  if ((value.has_image !== undefined && value.has_image !== true)
    || hasImage !== (value.image !== undefined)
    || (hasImage && value.role !== 'user')
    || (hasImage && !validImageMetadata(value.image))) {
    return false;
  }
  return true;
}

function validShoppingMemory(value) {
  if (!hasOnlyKeys(value, ['budget_min', 'budget_max', 'categories', 'attributes', 'notes'])) {
    return false;
  }
  if (value.budget_min !== undefined && !validFiniteNumber(value.budget_min, 0, 1_000_000_000)) {
    return false;
  }
  if (value.budget_max !== undefined && !validFiniteNumber(value.budget_max, 0, 1_000_000_000)) {
    return false;
  }
  if (value.budget_min !== undefined
    && value.budget_max !== undefined
    && value.budget_min > value.budget_max) {
    return false;
  }
  if (value.categories !== undefined && !validBoundedStringList(value.categories, 12, 80)) {
    return false;
  }
  if (value.attributes !== undefined && !validStringMap(value.attributes, 20, 60, 120)) {
    return false;
  }
  if (value.notes !== undefined && !validString(value.notes, 500, 1)) {
    return false;
  }
  return true;
}

/** Validate boot state before credentials or history are persisted in the browser. */
export function validateBootResponse(value) {
  if (!hasExactKeys(value, ['ok', 'conversation', 'messages', 'cart', 'cart_available', 'cart_notice'])
    || value.ok !== true
    || !hasExactKeys(value.conversation, ['id', 'token', 'expires_at'])
    || !validConversationCredentials(value.conversation)
    || !validIsoTimestamp(value.conversation.expires_at)
    || !Array.isArray(value.messages)
    || value.messages.length > 80
    || value.messages.some((message) => !validHistoryMessage(message))
    || value.messages.some((message, index) => index > 0 && message.id <= value.messages[index - 1].id)
    || typeof value.cart_available !== 'boolean'
    || (value.cart_available ? !validCart(value.cart) : value.cart !== null)
    || !validString(value.cart_notice, 600)
    || (value.cart_available ? value.cart_notice !== '' : unicodeLength(value.cart_notice) < 1)) {
    throw new Error('The boot response is malformed.');
  }
  return value;
}

/** Require an explicit durable deletion acknowledgement before erasing local state. */
export function validateDeleteResponse(value) {
  if (!hasExactKeys(value, ['ok', 'deleted']) || value.ok !== true || value.deleted !== true) {
    throw new Error('The deletion response is malformed.');
  }
  return value;
}

/**
 * A non-turn operation may discard a cached conversation capability only when
 * the same-origin REST endpoint returned the exact public unauthorized envelope.
 * HTTP status or an error code by itself is not enough: an intermediary or
 * malformed response must leave local authority untouched until boot can
 * reconcile it.
 */
export function errorConfirmsConversationUnauthorized(error) {
  const payload = error?.payload;
  return Number(error?.status || 0) === 401
    && String(error?.code || '') === 'conversation_unauthorized'
    && hasExactKeys(payload, ['ok', 'error'])
    && payload.ok === false
    && validErrorObject(payload.error)
    && payload.error.code === 'conversation_unauthorized';
}

/**
 * Validate the identity and durability contract of a chat/recovery response
 * before the browser clears its persisted pending turn. A syntactically valid
 * same-origin JSON response is not enough: it must describe the exact request
 * and prove that a successful assistant message is durably addressable.
 */
export function validateTurnResponse(value, requestBody, { allowProcessing = false } = {}) {
  if (!isRecord(value) || !isRecord(requestBody)
    || !validUuidV4(requestBody.conversation_id)
    || !validTurnId(requestBody.client_turn_id)
    || value.conversation_id !== requestBody.conversation_id
    || value.client_turn_id !== requestBody.client_turn_id) {
    throw new Error('The turn response identity does not match the pending request.');
  }

  if (value.status === 'processing') {
    if (!allowProcessing
      || !hasExactKeys(value, [
        'ok', 'status', 'conversation_id', 'client_turn_id', 'turn_finalized',
      ])
      || value.ok !== true
      || value.turn_finalized !== false) {
      throw new Error('The processing response is inconsistent.');
    }
    return value;
  }

  if (!hasExactKeys(value, [
    'ok', 'conversation_id', 'client_turn_id', 'turn_id', 'message_id',
    'turn_finalized', 'kind', 'message', 'products', 'cart', 'receipt',
  ], ['replayed'])
    || value.ok !== true
    || value.turn_finalized !== true
    || !positiveSafeInteger(value.turn_id)
    || !positiveSafeInteger(value.message_id)
    || !validString(value.message, 3000, 1)
    || !['answer', 'follow_up', 'safe_failure', 'cart_receipt', 'cart_uncertain'].includes(value.kind)
    || !Array.isArray(value.products)
    || value.products.length > 12
    || value.products.some((product) => !validProductCard(product))
    || (value.cart !== null && !validCart(value.cart))
    || (value.receipt !== null && !validReceipt(value.receipt))
    || (value.replayed !== undefined && typeof value.replayed !== 'boolean')) {
    throw new Error('The finalized turn response is malformed or not durable.');
  }

  if ((value.kind === 'safe_failure' || value.kind === 'cart_uncertain')
    && (value.products.length !== 0 || value.cart !== null)) {
    throw new Error('A failed turn cannot assert product recommendations or a fresh cart state.');
  }
  if (value.kind === 'cart_receipt') {
    if (!validReceipt(value.receipt)
      || !validCart(value.cart)
      || value.products.length !== 0
      || JSON.stringify(value.cart) !== JSON.stringify(value.receipt.cart)) {
      throw new Error('The cart receipt response is inconsistent.');
    }
  } else if (value.receipt !== null) {
    throw new Error('Only a cart receipt response may contain a receipt.');
  }

  return value;
}

export function errorConfirmsFinalizedTurn(error, requestBody) {
  const payload = error?.payload;
  const payloadError = payload?.error;
  if (!isRecord(requestBody)
    || !validUuidV4(requestBody.conversation_id)
    || !validTurnId(requestBody.client_turn_id)
    || !hasExactKeys(payload, [
      'ok', 'conversation_id', 'client_turn_id', 'turn_id', 'turn_finalized',
      'request_accepted', 'kind', 'error',
    ], ['message_id', 'replayed'])
    || payload.conversation_id !== requestBody.conversation_id
    || payload.client_turn_id !== requestBody.client_turn_id
    || payload.ok !== false
    || payload.turn_finalized !== true
    || !positiveSafeInteger(payload.turn_id)
    || typeof payload.request_accepted !== 'boolean'
    || payload.kind !== 'safe_failure'
    || !validErrorObject(payloadError)
    || String(error?.code || '') !== payloadError.code
    || (payload.request_accepted === true && !positiveSafeInteger(payload.message_id))
    || (payload.request_accepted === false && payload.message_id !== undefined)
    || (payload.replayed !== undefined && typeof payload.replayed !== 'boolean')) {
    return false;
  }
  return true;
}

export function errorConfirmsTurnDisposition(error, requestBody, expectedDisposition = null) {
  const payload = error?.payload;
  const disposition = payload?.request_disposition;
  if (!isRecord(requestBody)
    || !validUuidV4(requestBody.conversation_id)
    || !validTurnId(requestBody.client_turn_id)
    || !['rejected', 'conflict', 'processing', 'not_found', 'unverified'].includes(disposition)
    || (expectedDisposition !== null && disposition !== expectedDisposition)
    || !hasExactKeys(payload, [
      'ok', 'error', 'conversation_id', 'client_turn_id', 'turn_finalized',
      'request_disposition',
    ], ['request_accepted'])
    || payload.ok !== false
    || payload.conversation_id !== requestBody.conversation_id
    || payload.client_turn_id !== requestBody.client_turn_id
    || payload.turn_finalized !== false
    || !validErrorObject(payload.error)
    || String(error?.code || '') !== payload.error.code) {
    return false;
  }
  const conclusiveRejection = ['rejected', 'conflict', 'not_found'].includes(disposition);
  return conclusiveRejection
    ? payload.request_accepted === false
    : payload.request_accepted === undefined;
}

export function classifyTurnError(error, requestBody) {
  if (errorConfirmsFinalizedTurn(error, requestBody)) return 'finalized';
  if (errorConfirmsTurnDisposition(error, requestBody, 'processing')) return 'processing';
  if (errorConfirmsTurnDisposition(error, requestBody, 'conflict')) return 'conflict';
  if (errorConfirmsTurnDisposition(error, requestBody, 'rejected')) return 'rejected';
  if (errorConfirmsTurnDisposition(error, requestBody, 'not_found')) return 'not_found';
  if (errorConfirmsTurnDisposition(error, requestBody, 'unverified')) return 'unverified';
  return 'ambiguous';
}

export function turnRecoveryAction(error, requestBody) {
  const classification = classifyTurnError(error, requestBody);
  return classification === 'ambiguous' || classification === 'processing'
    ? 'recover_same_turn'
    : 'stop';
}

export function operationLocks({ sending = false, recovering = false, exporting = false, deleting = false } = {}) {
  const locked = Boolean(sending || recovering || exporting || deleting);
  return { sendLocked: locked, exportLocked: locked, deleteLocked: locked };
}


export function pendingForDurableStorage(value) {
  if (!value || typeof value !== 'object' || Array.isArray(value)) return null;
  const sourceBody = value.body;
  if (!sourceBody || typeof sourceBody !== 'object' || Array.isArray(sourceBody)) return null;

  const credentials = {
    id: String(sourceBody.conversation_id || ''),
    token: String(sourceBody.token || ''),
  };
  const clientTurnId = String(sourceBody.client_turn_id || '');
  if (!validConversationCredentials(credentials)
    || !/^[A-Za-z0-9_-]{16,64}$/.test(clientTurnId)
    || typeof sourceBody.message !== 'string'
    || unicodeLength(sourceBody.message) > 4000) {
    return null;
  }

  let reply = null;
  if (sourceBody.reply !== null && sourceBody.reply !== undefined) {
    if (!sourceBody.reply || typeof sourceBody.reply !== 'object' || Array.isArray(sourceBody.reply)) return null;
    const messageId = Number(sourceBody.reply.message_id);
    if (!Number.isSafeInteger(messageId) || messageId <= 0) return null;
    reply = { message_id: messageId };
    if (sourceBody.reply.product_ref !== undefined) {
      const productRef = String(sourceBody.reply.product_ref || '');
      if (!/^p_[A-Za-z0-9_-]{8,80}$/.test(productRef)) return null;
      reply.product_ref = productRef;
    }
  }

  const image = sourceBody.image;
  const hadImage = image !== null && image !== undefined;
  if (hadImage && (!image
    || typeof image !== 'object'
    || Array.isArray(image)
    || !['image/jpeg', 'image/png', 'image/webp'].includes(image.mime_type)
    || typeof image.data !== 'string'
    || image.data === '')) {
    return null;
  }

  const durable = {
    storage_version: 1,
    body: {
      conversation_id: credentials.id,
      token: credentials.token,
      client_turn_id: clientTurnId,
      message: sourceBody.message,
      reply,
      image: null,
    },
    image_unavailable: hadImage,
  };

  if (typeof value.createdAt === 'string' && value.createdAt.length <= 64) {
    durable.createdAt = value.createdAt;
  }
  if (typeof value.lastError === 'string' && value.lastError.length <= 64) {
    durable.lastError = value.lastError;
  }
  return durable;
}

export function normalizeDurablePending(value) {
  const normalized = pendingForDurableStorage(value);
  if (!normalized) return null;
  normalized.image_unavailable = normalized.image_unavailable || value?.image_unavailable === true;
  return normalized;
}

export function pendingRequiresImageReattach(value) {
  return Boolean(
    value?.image_unavailable === true
    && (!value?.body?.image
      || typeof value.body.image !== 'object'
      || typeof value.body.image.data !== 'string'
      || value.body.image.data === ''),
  );
}


export function newTurnAction({ locked = false, credentials = null, pending = null } = {}) {
  if (locked || !validConversationCredentials(credentials)) return 'blocked';
  return normalizeDurablePending(pending) ? 'recover_pending' : 'start';
}

export function safeHttpUrl(value, origin, sameOrigin = false) {
  try {
    const trustedOrigin = new URL(String(origin || '')).origin;
    const url = new URL(String(value || ''), trustedOrigin);
    if (!['http:', 'https:'].includes(url.protocol)) return '';
    if (url.username !== '' || url.password !== '') return '';
    if (sameOrigin && url.origin !== trustedOrigin) return '';
    return url.href;
  } catch (error) {
    return '';
  }
}

export class ResilientJsonStore {
  constructor(storage = null) {
    this.storage = storage;
    this.memory = new Map();
  }

  read(key) {
    const storageKey = String(key || '');
    if (storageKey === '') return null;
    try {
      const raw = this.storage?.getItem(storageKey);
      const parsed = this.parse(raw);
      if (parsed !== null) {
        this.memory.set(storageKey, raw);
        return parsed;
      }
    } catch (error) {
      // Fall through to the page-memory copy.
    }
    return this.parse(this.memory.get(storageKey) || null);
  }

  write(key, value) {
    const storageKey = String(key || '');
    if (storageKey === '') return false;
    let encoded;
    try {
      encoded = JSON.stringify(value);
      if (typeof encoded !== 'string') return false;
      const parsed = this.parse(encoded);
      if (parsed === null) return false;
    } catch (error) {
      return false;
    }

    this.memory.set(storageKey, encoded);
    try {
      this.storage?.setItem(storageKey, encoded);
      return this.storage?.getItem(storageKey) === encoded;
    } catch (error) {
      return false;
    }
  }

  remove(key) {
    const storageKey = String(key || '');
    this.memory.delete(storageKey);
    try {
      this.storage?.removeItem(storageKey);
    } catch (error) {
      // The in-memory copy is already gone.
    }
  }

  parse(raw) {
    if (typeof raw !== 'string' || raw === '') return null;
    try {
      const value = JSON.parse(raw);
      return value && typeof value === 'object' && !Array.isArray(value) ? value : null;
    } catch (error) {
      return null;
    }
  }
}


export function isAbortError(error) {
  return Boolean(error) && (error.name === 'AbortError' || error.code === 20);
}

export function validateExportPage(state, page, conversationId) {
  if (!hasExactKeys(page, [
    'ok', 'conversation_id', 'exported_at', 'upper_message_id',
    'next_after_message_id', 'complete', 'message_count', 'messages',
    'shopping_memory',
  ])
    || page.ok !== true
    || String(page.conversation_id || '') !== String(conversationId || '')
    || !validIsoTimestamp(page.exported_at)
    || typeof page.complete !== 'boolean'
    || !validShoppingMemory(page.shopping_memory)) {
    throw new Error('Invalid export response.');
  }

  const previousUpper = Number(state?.upperMessageId || 0);
  const previousAfter = Number(state?.afterMessageId || 0);
  const previousCount = state?.messageCount === null || state?.messageCount === undefined
    ? null
    : Number(state.messageCount);
  const previousLoaded = Number(state?.loadedCount || 0);
  const previousMemory = state?.shoppingMemory;
  const upper = Number(page.upper_message_id);
  const messageCount = Number(page.message_count);
  if (!Number.isSafeInteger(upper) || upper < 0 || (previousUpper > 0 && upper !== previousUpper)) {
    throw new Error('Conversation export boundary changed.');
  }
  if (!Number.isSafeInteger(messageCount)
    || messageCount < 0
    || messageCount > 5000
    || (previousCount !== null && messageCount !== previousCount)) {
    throw new Error('Conversation export count changed.');
  }
  if (previousMemory !== undefined
    && JSON.stringify(previousMemory) !== JSON.stringify(page.shopping_memory)) {
    throw new Error('Conversation export memory changed.');
  }

  const messages = Array.isArray(page.messages) ? page.messages : null;
  if (messages === null
    || messages.length > 200
    || messages.some((message) => !validHistoryMessage(message))) {
    throw new Error('Invalid export message page.');
  }
  let last = previousAfter;
  for (const message of messages) {
    const id = Number(message.id);
    if (!Number.isSafeInteger(id) || id <= last || id > upper) {
      throw new Error('Conversation export message order is invalid.');
    }
    last = id;
  }

  const loadedCount = previousLoaded + messages.length;
  if (!Number.isSafeInteger(loadedCount) || loadedCount > messageCount) {
    throw new Error('Conversation export contains more messages than declared.');
  }

  const complete = page.complete === true;
  const rawNext = page.next_after_message_id;
  let next = null;
  if (!complete) {
    next = Number(rawNext);
    if (messages.length === 0
      || loadedCount >= messageCount
      || !Number.isSafeInteger(next)
      || next !== last
      || next <= previousAfter
      || next > upper) {
      throw new Error('Conversation export cursor is invalid.');
    }
  } else {
    if (rawNext !== null) {
      throw new Error('Completed conversation export included a continuation cursor.');
    }
    if (loadedCount !== messageCount) {
      throw new Error('Completed conversation export count is inconsistent.');
    }
  }

  if ((messageCount === 0 && (upper !== 0 || messages.length !== 0 || !complete))
    || (messageCount > 0 && upper <= 0)) {
    throw new Error('Conversation export boundary is inconsistent with its count.');
  }

  return {
    upperMessageId: upper,
    afterMessageId: complete ? last : next,
    messageCount,
    loadedCount,
    complete,
    messages,
    shoppingMemory: page.shopping_memory,
  };
}
