import test from 'node:test';
import assert from 'node:assert/strict';

import {
  clampRequestTimeout,
  classifyTurnError,
  createTurnId,
  errorConfirmsConversationUnauthorized,
  errorConfirmsFinalizedTurn,
  errorConfirmsTurnDisposition,
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
} from '../../assets/js/client-utils.js';

const TEST_CONVERSATION_ID = '123e4567-e89b-42d3-a456-426614174000';
const TEST_TURN_ID = '123e4567-e89b-12d3-a456-426614174000';

function productCard(overrides = {}) {
  return {
    ref: 'p_abcdefgh1234',
    name: 'منتج اختباري',
    sku: 'SKU-1',
    type: 'simple',
    price: 10,
    price_available: true,
    price_kind: 'fixed',
    regular_price: 12,
    sale_price: 10,
    price_text: '$10.00',
    currency: 'USD',
    in_stock: true,
    stock_status: 'instock',
    stock_quantity: 5,
    rating: 4.5,
    review_count: 7,
    short_description: 'وصف اختباري',
    image: '',
    url: '',
    purchasable: true,
    requires_options: false,
    categories: ['اختبار'],
    categories_truncated: false,
    attributes: {},
    attributes_truncated: false,
    variation_options: {},
    variation_options_truncated: false,
    ...overrides,
  };
}

function cartFixture(overrides = {}) {
  return {
    items: [],
    line_count: 0,
    items_truncated: false,
    item_count: 0,
    total: 0,
    total_text: '$0.00',
    currency: 'USD',
    cart_url: '',
    checkout_url: '',
    cart_hash: '',
    mutations_allowed: true,
    mutation_notice: '',
    ...overrides,
  };
}

function receiptFixture(cart = cartFixture()) {
  return {
    id: '223e4567-e89b-42d3-a456-426614174000',
    message: 'تم تحديث السلة.',
    lines: [{ action: 'clear' }],
    cart,
  };
}

function historyMessage(id, overrides = {}) {
  return {
    id,
    turn_id: id,
    role: 'user',
    content: `رسالة ${id}`,
    created_at: '2026-08-14T12:00:00+00:00',
    ...overrides,
  };
}

function exportPage(overrides = {}) {
  return {
    ok: true,
    conversation_id: 'conversation',
    exported_at: '2026-08-14T12:00:00+00:00',
    upper_message_id: 0,
    next_after_message_id: null,
    complete: true,
    message_count: 0,
    messages: [],
    shopping_memory: {},
    ...overrides,
  };
}

function turnBody() {
  return {
    conversation_id: TEST_CONVERSATION_ID,
    client_turn_id: TEST_TURN_ID,
  };
}

function dispositionPayload(disposition, code, requestAccepted = undefined) {
  const payload = {
    ok: false,
    conversation_id: TEST_CONVERSATION_ID,
    client_turn_id: TEST_TURN_ID,
    turn_finalized: false,
    request_disposition: disposition,
    error: { code, message: 'تعذّر تنفيذ الطلب.', retryable: false },
  };
  if (requestAccepted !== undefined) payload.request_accepted = requestAccepted;
  return payload;
}

test('clampRequestTimeout aligns browser aborts with bounded server turn leases', () => {
  assert.equal(clampRequestTimeout(1), 30_000);
  assert.equal(clampRequestTimeout(180_000), 180_000);
  assert.equal(clampRequestTimeout(2_000_000), 1_185_000);
  assert.equal(clampRequestTimeout('invalid'), 180_000);
});

test('normalizeRestBase creates exactly one trailing slash', () => {
  assert.equal(normalizeRestBase(' https://shop.example.test/wp-json/yassin-ai/v2/// '), 'https://shop.example.test/wp-json/yassin-ai/v2/');
  assert.equal(normalizeRestBase(''), '');
});

test('readBoundedJsonResponse accepts bounded UTF-8 JSON and rejects oversized or malformed bodies', async () => {
  const valid = await readBoundedJsonResponse(new Response('{"ok":true}', {
    headers: { 'Content-Type': 'application/json' },
  }), 1024);
  assert.deepEqual(valid, { ok: true });

  await assert.rejects(
    readBoundedJsonResponse(new Response('{}', {
      headers: { 'Content-Length': '2048' },
    }), 1024),
    (error) => error?.code === 'response_too_large',
  );

  const stream = new ReadableStream({
    start(controller) {
      controller.enqueue(new Uint8Array(700));
      controller.enqueue(new Uint8Array(700));
      controller.close();
    },
  });
  await assert.rejects(
    readBoundedJsonResponse(new Response(stream), 1024),
    (error) => error?.code === 'response_too_large',
  );

  await assert.rejects(
    readBoundedJsonResponse(new Response('{not-json}'), 1024),
    /not valid JSON/,
  );
  await assert.rejects(
    readBoundedJsonResponse(new Response(new Uint8Array([0xC3, 0x28])), 1024),
    /not valid UTF-8/,
  );
  await assert.rejects(
    readBoundedJsonResponse(new Response('{}', { headers: { 'Content-Length': '+2' } }), 1024),
    /length header is invalid/,
  );
});

test('Unicode message helpers count and truncate code points without splitting surrogate pairs', () => {
  assert.equal(unicodeLength('A😀ب'), 3);
  assert.equal(truncateUnicode('A😀ب', 2), 'A😀');
  assert.equal(truncateUnicode('A😀ب', 0), '');
  assert.equal(truncateUnicode('A😀ب', -1), '');
});

test('createTurnId requires cryptographically secure browser randomness', () => {
  assert.equal(createTurnId({ randomUUID: () => '123e4567-e89b-12d3-a456-426614174000' }), '123e4567-e89b-12d3-a456-426614174000');
  const fallback = createTurnId({ getRandomValues: (bytes) => bytes.fill(171) });
  assert.match(fallback, /^[a-f0-9]{36}$/);
  assert.equal(fallback, 'ab'.repeat(18));
  assert.throws(() => createTurnId(null), /Secure browser randomness/);
  assert.throws(() => createTurnId({}), /Secure browser randomness/);
});

test('selectBootCredentials gives an unfinished exact request precedence over stale local state', () => {
  const saved = {
    id: '123e4567-e89b-42d3-a456-426614174000',
    token: 'a'.repeat(43),
  };
  const pending = {
    body: {
      conversation_id: '223e4567-e89b-42d3-a456-426614174000',
      token: 'b'.repeat(43),
      client_turn_id: 'turn-1',
    },
  };
  assert.deepEqual(selectBootCredentials(saved, pending), {
    id: pending.body.conversation_id,
    token: pending.body.token,
  });
  assert.deepEqual(selectBootCredentials(saved, null), saved);
  assert.equal(selectBootCredentials({ id: 'bad', token: 'bad' }, null), null);
});

test('sameConversation requires both exact capability values', () => {
  const credentials = { id: 'conversation', token: 'secret-token' };
  assert.equal(sameConversation(credentials, { conversation_id: 'conversation', token: 'secret-token' }), true);
  assert.equal(sameConversation(credentials, { conversation_id: 'conversation', token: 'other' }), false);
  assert.equal(sameConversation(null, {}), false);
});

test('validateBootResponse accepts only bounded durable conversation state', () => {
  const valid = {
    ok: true,
    conversation: {
      id: '123e4567-e89b-42d3-a456-426614174000',
      token: 'a'.repeat(43),
      expires_at: '2026-09-28T12:00:00+00:00',
    },
    messages: [{
      id: 1,
      turn_id: 2,
      role: 'user',
      content: 'رسالة محفوظة',
      created_at: '2026-08-14T12:00:00+00:00',
      client_turn_id: TEST_TURN_ID,
      has_image: true,
      image: { mime_type: 'image/webp', bytes: 1024, width: 100, height: 80 },
    }],
    cart: cartFixture(),
    cart_available: true,
    cart_notice: '',
  };

  assert.equal(validateBootResponse(valid), valid);
  for (const invalid of [
    [],
    { ...valid, ok: false },
    { ...valid, conversation: { ...valid.conversation, token: 'short' } },
    { ...valid, conversation: { ...valid.conversation, expires_at: 'not-a-date' } },
    { ...valid, messages: Array.from({ length: 81 }, () => valid.messages[0]) },
    { ...valid, messages: [{ ...valid.messages[0], id: 0 }] },
    { ...valid, messages: [{ ...valid.messages[0], role: 'admin' }] },
    { ...valid, messages: [{ ...valid.messages[0], role: 'system' }] },
    { ...valid, messages: [{ ...valid.messages[0], client_turn_id: 'short' }] },
    { ...valid, unexpected: true },
    { ...valid, conversation: { ...valid.conversation, unexpected: true } },
    { ...valid, messages: [{ ...valid.messages[0], unexpected: true }] },
    { ...valid, messages: [{ ...valid.messages[0], has_image: undefined }] },
    { ...valid, messages: [{ ...valid.messages[0], image: undefined }] },
    { ...valid, messages: [{ ...valid.messages[0], role: 'assistant' }] },
    { ...valid, messages: [{ ...valid.messages[0], role: 'assistant', client_turn_id: undefined, has_image: undefined, image: undefined, kind: 'unknown_kind' }] },
    { ...valid, messages: [{ ...valid.messages[0], image: { ...valid.messages[0].image, bytes: 5_000_000 } }] },
    { ...valid, messages: [{ ...valid.messages[0], image: { ...valid.messages[0].image, width: 4096, height: 4096 } }] },
    { ...valid, messages: [valid.messages[0], { ...valid.messages[0], id: 1 }] },
    { ...valid, messages: [{ ...valid.messages[0], id: 2 }, { ...valid.messages[0], id: 1 }] },
    { ...valid, cart: [] },
    { ...valid, cart: null },
    { ...valid, cart_available: false },
    { ...valid, cart_available: 'yes' },
    { ...valid, cart_notice: 'not empty while available' },
    { ...valid, cart_notice: 'x'.repeat(601) },
    { ...valid, cart: cartFixture({ notice: 'عرض ناقص' }) },
    { ...valid, cart: cartFixture({ presentation_incomplete: true }) },
    { ...valid, cart: cartFixture({
      items: [{
        name: 'منتج', quantity: 2, unit_price: 10, line_total: 20,
        line_total_text: '$20.00', image: '', variation: {}, sku: '', ref: '',
      }],
      line_count: 1,
      item_count: 1,
    }) },
  ]) {
    assert.throws(() => validateBootResponse(invalid));
  }
});

test('validateDeleteResponse requires an explicit deletion acknowledgement', () => {
  const valid = { ok: true, deleted: true };
  assert.equal(validateDeleteResponse(valid), valid);
  for (const invalid of [
    null,
    [],
    {},
    { ok: true },
    { ok: false, deleted: true },
    { ok: true, deleted: 1 },
    { ok: true, deleted: true, unexpected: true },
  ]) {
    assert.throws(() => validateDeleteResponse(invalid));
  }
});

test('errorConfirmsConversationUnauthorized requires the exact non-turn REST envelope', () => {
  const payload = {
    ok: false,
    error: {
      code: 'conversation_unauthorized',
      message: 'انتهت جلسة المحادثة.',
      retryable: false,
    },
  };
  assert.equal(errorConfirmsConversationUnauthorized({
    status: 401,
    code: 'conversation_unauthorized',
    payload,
  }), true);
  assert.equal(errorConfirmsConversationUnauthorized({
    status: 401,
    code: 'conversation_unauthorized',
    payload: { ...payload, unexpected: true },
  }), false);
  assert.equal(errorConfirmsConversationUnauthorized({
    status: 500,
    code: 'conversation_unauthorized',
    payload,
  }), false);
  assert.equal(errorConfirmsConversationUnauthorized({
    status: 401,
    code: 'conversation_unauthorized',
    payload: {
      ...payload,
      error: { ...payload.error, code: 'server_error' },
    },
  }), false);
  assert.equal(errorConfirmsConversationUnauthorized({
    status: 401,
    code: 'conversation_unauthorized',
    payload: null,
  }), false);
});

test('validateTurnResponse accepts only the exact durable final turn or an explicit processing state', () => {
  const request = {
    conversation_id: '123e4567-e89b-42d3-a456-426614174000',
    client_turn_id: '123e4567-e89b-12d3-a456-426614174000',
  };
  const completed = {
    ok: true,
    conversation_id: request.conversation_id,
    client_turn_id: request.client_turn_id,
    turn_id: 17,
    message_id: 23,
    turn_finalized: true,
    kind: 'answer',
    message: 'إجابة محفوظة.',
    products: [],
    cart: null,
    receipt: null,
  };
  assert.equal(validateTurnResponse(completed, request), completed);

  const processing = {
    ok: true,
    status: 'processing',
    conversation_id: request.conversation_id,
    client_turn_id: request.client_turn_id,
    turn_finalized: false,
  };
  assert.equal(validateTurnResponse(processing, request, { allowProcessing: true }), processing);
  assert.throws(() => validateTurnResponse(processing, request));
});

test('validateTurnResponse rejects mismatched identities and incomplete success envelopes', () => {
  const request = {
    conversation_id: '123e4567-e89b-42d3-a456-426614174000',
    client_turn_id: '123e4567-e89b-12d3-a456-426614174000',
  };
  const cart = cartFixture();
  const valid = {
    ok: true,
    conversation_id: request.conversation_id,
    client_turn_id: request.client_turn_id,
    turn_id: 1,
    message_id: 2,
    turn_finalized: true,
    kind: 'cart_receipt',
    message: 'تم الحفظ.',
    products: [],
    cart,
    receipt: receiptFixture(cart),
  };

  for (const invalid of [
    { ...valid, conversation_id: '223e4567-e89b-42d3-a456-426614174000' },
    { ...valid, client_turn_id: 'different_turn_123456789' },
    { ...valid, turn_id: '1' },
    { ...valid, message_id: 0 },
    { ...valid, turn_finalized: false },
    { ...valid, message: '' },
    { ...valid, kind: 'Invalid Kind' },
    { ...valid, products: Array.from({ length: 13 }, () => ({})) },
    { ...valid, products: [null] },
    { ...valid, products: [productCard({ price: null })] },
    { ...valid, cart: [] },
    { ...valid, receipt: [] },
    { ...valid, receipt: { ...valid.receipt, id: 'not-a-uuid' } },
    { ...valid, cart: { ...cart, item_count: Number.NaN } },
    { ...valid, cart: { ...cart, unexpected: true } },
    { ...valid, replayed: 'yes' },
    { ...valid, status: 'completed' },
  ]) {
    assert.throws(() => validateTurnResponse(invalid, request));
  }
});

test('errorConfirmsFinalizedTurn only accepts a failure payload for the exact turn', () => {
  const body = turnBody();
  const finalized = {
    ok: false,
    conversation_id: body.conversation_id,
    client_turn_id: body.client_turn_id,
    turn_id: 7,
    turn_finalized: true,
    request_accepted: true,
    kind: 'safe_failure',
    message_id: 11,
    error: {
      code: 'invalid_request',
      message: 'Rejected.',
      retryable: false,
      retry_mode: 'none',
    },
  };
  assert.equal(errorConfirmsFinalizedTurn({ code: 'invalid_request', payload: finalized }, body), true);
  const legacyError = { ...finalized.error };
  delete legacyError.retry_mode;
  assert.equal(errorConfirmsFinalizedTurn({
    code: 'invalid_request',
    payload: { ...finalized, error: legacyError },
  }, body), true);
  assert.equal(errorConfirmsFinalizedTurn({
    code: 'invalid_request',
    payload: {
      ...finalized,
      error: {
        ...finalized.error,
        retryable: true,
        retry_mode: 'new_turn',
        retry_after_seconds: 60,
      },
    },
  }, body), true);
  assert.equal(errorConfirmsFinalizedTurn({ code: 'invalid_request', payload: { ...finalized, turn_finalized: false } }, body), false);
  assert.equal(errorConfirmsFinalizedTurn({ code: 'invalid_request', payload: { ...finalized, conversation_id: '223e4567-e89b-42d3-a456-426614174000' } }, body), false);
  assert.equal(errorConfirmsFinalizedTurn({ code: 'invalid_request', payload: { ...finalized, client_turn_id: '223e4567-e89b-12d3-a456-426614174000' } }, body), false);
  assert.equal(errorConfirmsFinalizedTurn({ code: 'invalid_request', payload: { ...finalized, turn_id: 0 } }, body), false);
  assert.equal(errorConfirmsFinalizedTurn({ code: 'invalid_request', payload: { ...finalized, ok: true } }, body), false);
  assert.equal(errorConfirmsFinalizedTurn({ code: 'other_error', payload: finalized }, body), false);
  assert.equal(errorConfirmsFinalizedTurn({ code: 'invalid_request', payload: { ...finalized, error: { ...finalized.error, retryable: 'no' } } }, body), false);
  assert.equal(errorConfirmsFinalizedTurn({ code: 'invalid_request', payload: { ...finalized, error: { ...finalized.error, retry_mode: 'later' } } }, body), false);
  assert.equal(errorConfirmsFinalizedTurn({ code: 'invalid_request', payload: { ...finalized, error: { ...finalized.error, retryable: true, retry_mode: 'none' } } }, body), false);
  assert.equal(errorConfirmsFinalizedTurn({ code: 'invalid_request', payload: { ...finalized, error: { ...finalized.error, retryable: false, retry_mode: 'new_turn' } } }, body), false);
  assert.equal(errorConfirmsFinalizedTurn({ code: 'invalid_request', payload: { ...finalized, error: { ...finalized.error, retry_after_seconds: 60 } } }, body), false);
  assert.equal(errorConfirmsFinalizedTurn({ code: 'invalid_request', payload: { ...finalized, error: { ...finalized.error, retryable: true, retry_mode: 'new_turn', retry_after_seconds: 0 } } }, body), false);
  assert.equal(errorConfirmsFinalizedTurn({ code: 'invalid_request', payload: { ...finalized, error: { ...finalized.error, retryable: true, retry_mode: 'new_turn', retry_after_seconds: 86401 } } }, body), false);
  assert.equal(errorConfirmsFinalizedTurn({ code: 'invalid_request', payload: { ...finalized, error: { ...finalized.error, message: '' } } }, body), false);
  assert.equal(errorConfirmsFinalizedTurn({ code: 'invalid_request', payload: { ...finalized, request_accepted: 'yes' } }, body), false);
  const { message_id: _missingMessageId, ...acceptedWithoutMessage } = finalized;
  assert.equal(errorConfirmsFinalizedTurn({ code: 'invalid_request', payload: acceptedWithoutMessage }, body), false);
  assert.equal(errorConfirmsFinalizedTurn({
    code: 'invalid_request',
    payload: { ...finalized, request_accepted: false },
  }, body), false);
  const { message_id: _acceptedMessageId, ...notAccepted } = finalized;
  assert.equal(errorConfirmsFinalizedTurn({
    code: 'invalid_request',
    payload: { ...notAccepted, request_accepted: false },
  }, body), true);
  assert.equal(errorConfirmsFinalizedTurn({ code: 'invalid_request', payload: { ...finalized, kind: 'answer' } }, body), false);
  assert.equal(errorConfirmsFinalizedTurn({ code: 'invalid_request', payload: { ...finalized, unexpected: true } }, body), false);
});

test('classifyTurnError preserves the original turn for ambiguous failures', () => {
  const body = turnBody();
  assert.equal(classifyTurnError({ status: 0, code: 'network_error', retryable: true }, body), 'ambiguous');
  assert.equal(classifyTurnError({ status: 503, code: 'invalid_response', retryable: false }, body), 'ambiguous');
  assert.equal(classifyTurnError({ status: 429, code: 'rate_limited', retryable: true }, body), 'ambiguous');
  assert.equal(classifyTurnError({
    status: 503,
    code: 'turn_persistence_uncertain',
    retryable: true,
    payload: { ok: false, client_turn_id: body.client_turn_id, turn_finalized: false },
  }, body), 'ambiguous');
  const processing = dispositionPayload('processing', 'turn_processing');
  assert.equal(classifyTurnError({ status: 409, code: 'turn_processing', payload: processing }, body), 'processing');
  assert.equal(classifyTurnError({ status: 409, code: 'turn_processing' }, body), 'ambiguous');
  assert.equal(classifyTurnError({ status: 422, code: 'invalid_request', retryable: false }, body), 'ambiguous');
});

test('classifyTurnError distinguishes finalized, conflict, and rejected failures', () => {
  const body = turnBody();
  const finalized = {
    ok: false,
    conversation_id: body.conversation_id,
    client_turn_id: body.client_turn_id,
    turn_id: 4,
    turn_finalized: true,
    request_accepted: true,
    kind: 'safe_failure',
    message_id: 11,
    error: { code: 'invalid_request', message: 'Rejected.', retryable: false },
  };
  assert.equal(classifyTurnError({ status: 422, code: 'invalid_request', payload: finalized }, body), 'finalized');
  assert.equal(classifyTurnError({
    status: 409,
    code: 'turn_id_conflict',
    payload: dispositionPayload('conflict', 'turn_id_conflict', false),
  }, body), 'conflict');
  assert.equal(classifyTurnError({
    status: 422,
    code: 'invalid_request',
    payload: dispositionPayload('rejected', 'invalid_request', false),
  }, body), 'rejected');
  assert.equal(classifyTurnError({
    status: 404,
    code: 'turn_not_found',
    payload: dispositionPayload('not_found', 'turn_not_found', false),
  }, body), 'not_found');
  assert.equal(classifyTurnError({
    status: 401,
    code: 'conversation_unauthorized',
    payload: dispositionPayload('unverified', 'conversation_unauthorized'),
  }, body), 'unverified');
  assert.equal(classifyTurnError({ status: 401, code: 'conversation_unauthorized' }, body), 'ambiguous');
});

test('errorConfirmsTurnDisposition requires exact identity, shape, and acceptance semantics', () => {
  const body = turnBody();
  const rejected = dispositionPayload('rejected', 'invalid_request', false);
  assert.equal(errorConfirmsTurnDisposition({ code: 'invalid_request', payload: rejected }, body, 'rejected'), true);
  assert.equal(errorConfirmsTurnDisposition({ code: 'invalid_request', payload: { ...rejected, request_accepted: true } }, body), false);
  assert.equal(errorConfirmsTurnDisposition({ code: 'other', payload: rejected }, body), false);
  assert.equal(errorConfirmsTurnDisposition({ code: 'invalid_request', payload: { ...rejected, client_turn_id: '223e4567-e89b-12d3-a456-426614174000' } }, body), false);
  assert.equal(errorConfirmsTurnDisposition({ code: 'invalid_request', payload: { ...rejected, unexpected: true } }, body), false);
  const processing = dispositionPayload('processing', 'turn_processing');
  assert.equal(errorConfirmsTurnDisposition({ code: 'turn_processing', payload: processing }, body, 'processing'), true);
  assert.equal(errorConfirmsTurnDisposition({ code: 'turn_processing', payload: { ...processing, request_accepted: false } }, body), false);
});


test('turnRecoveryAction never creates a new idempotency key automatically', () => {
  const body = turnBody();
  assert.equal(turnRecoveryAction({ status: 0, code: 'network_error', retryable: true }, body), 'recover_same_turn');
  assert.equal(turnRecoveryAction({
    status: 409,
    code: 'turn_processing',
    payload: dispositionPayload('processing', 'turn_processing'),
  }, body), 'recover_same_turn');
  assert.equal(turnRecoveryAction({ status: 409, code: 'turn_processing' }, body), 'recover_same_turn');
  assert.equal(turnRecoveryAction({
    status: 409,
    code: 'turn_id_conflict',
    payload: dispositionPayload('conflict', 'turn_id_conflict', false),
  }, body), 'stop');
  assert.equal(turnRecoveryAction({
    status: 422,
    code: 'invalid_request',
    payload: dispositionPayload('rejected', 'invalid_request', false),
  }, body), 'stop');
  assert.equal(turnRecoveryAction({ status: 422, code: 'invalid_request' }, body), 'recover_same_turn');
});

test('an unresolved durable turn must be recovered before a new turn starts', () => {
  const credentials = {
    id: '123e4567-e89b-42d3-a456-426614174000',
    token: 'a'.repeat(43),
  };
  const pending = pendingForDurableStorage({
    body: {
      conversation_id: credentials.id,
      token: credentials.token,
      client_turn_id: 'turn_abcdefghijklmnop',
      message: 'أضف المنتج',
      reply: null,
      image: null,
    },
  });

  assert.equal(newTurnAction({ credentials, pending }), 'recover_pending');
  assert.equal(newTurnAction({ credentials, pending: null }), 'start');
  assert.equal(newTurnAction({ credentials, pending: null, locked: true }), 'blocked');
  assert.equal(newTurnAction({ credentials: null, pending: null }), 'blocked');
});

test('operationLocks prevents destructive or overlapping conversation operations', () => {
  assert.deepEqual(operationLocks(), {
    sendLocked: false,
    exportLocked: false,
    deleteLocked: false,
  });
  for (const state of [
    { sending: true },
    { recovering: true },
    { exporting: true },
    { deleting: true },
  ]) {
    assert.deepEqual(operationLocks(state), {
      sendLocked: true,
      exportLocked: true,
      deleteLocked: true,
    });
  }
});

test('pendingForDurableStorage preserves turn recovery data without persisting image bytes', () => {
  const pending = {
    createdAt: '2026-08-14T10:00:00.000Z',
    lastError: 'network_error',
    body: {
      conversation_id: '123e4567-e89b-42d3-a456-426614174000',
      token: 'a'.repeat(43),
      client_turn_id: '123e4567-e89b-12d3-a456-426614174000',
      message: 'قارن هذه الصورة',
      reply: { message_id: 7, product_ref: `p_${'x'.repeat(12)}` },
      image: { mime_type: 'image/png', data: 'secret-base64-image-bytes' },
    },
  };

  const durable = pendingForDurableStorage(pending);
  assert.deepEqual(durable, {
    storage_version: 1,
    createdAt: pending.createdAt,
    lastError: pending.lastError,
    image_unavailable: true,
    body: {
      conversation_id: pending.body.conversation_id,
      token: pending.body.token,
      client_turn_id: pending.body.client_turn_id,
      message: pending.body.message,
      reply: pending.body.reply,
      image: null,
    },
  });
  assert.equal(JSON.stringify(durable).includes('secret-base64-image-bytes'), false);
  assert.equal(pendingRequiresImageReattach(durable), true);
  assert.equal(pendingRequiresImageReattach(pending), false);

  const astral = pendingForDurableStorage({
    body: {
      conversation_id: pending.body.conversation_id,
      token: pending.body.token,
      client_turn_id: pending.body.client_turn_id,
      message: '😀'.repeat(4000),
      reply: null,
      image: null,
    },
  });
  assert.ok(astral);
  assert.equal(pendingForDurableStorage({
    body: { ...astral.body, message: '😀'.repeat(4001) },
  }), null);
});

test('normalizeDurablePending sanitizes legacy image records and preserves the reattach marker', () => {
  const legacy = {
    body: {
      conversation_id: '123e4567-e89b-42d3-a456-426614174000',
      token: 'a'.repeat(43),
      client_turn_id: '123e4567-e89b-12d3-a456-426614174000',
      message: '',
      reply: null,
      image: { mime_type: 'image/jpeg', data: 'legacy-image-bytes' },
      unexpected: 'discard me',
    },
  };
  const normalized = normalizeDurablePending(legacy);
  assert.equal(normalized.body.image, null);
  assert.equal(normalized.image_unavailable, true);
  assert.equal('unexpected' in normalized.body, false);
  assert.equal(JSON.stringify(normalized).includes('legacy-image-bytes'), false);

  const alreadySanitized = normalizeDurablePending({ ...normalized, image_unavailable: true });
  assert.equal(alreadySanitized.image_unavailable, true);
  assert.equal(normalizeDurablePending({ body: { message: 'tampered' } }), null);
});

test('pendingForDurableStorage rejects malformed recovery identities and reply context', () => {
  const valid = {
    body: {
      conversation_id: '123e4567-e89b-42d3-a456-426614174000',
      token: 'a'.repeat(43),
      client_turn_id: '123e4567-e89b-12d3-a456-426614174000',
      message: 'hello',
      reply: null,
      image: null,
    },
  };
  assert.equal(pendingForDurableStorage(valid)?.image_unavailable, false);
  assert.equal(pendingRequiresImageReattach(pendingForDurableStorage(valid)), false);
  assert.equal(pendingForDurableStorage({ ...valid, body: { ...valid.body, token: 'short' } }), null);
  assert.equal(pendingForDurableStorage({ ...valid, body: { ...valid.body, client_turn_id: 'short' } }), null);
  assert.equal(pendingForDurableStorage({ ...valid, body: { ...valid.body, message: 'x'.repeat(4001) } }), null);
  assert.equal(pendingForDurableStorage({ ...valid, body: { ...valid.body, reply: { message_id: 'bad' } } }), null);
  assert.equal(pendingForDurableStorage({ ...valid, body: { ...valid.body, reply: { message_id: 1, product_ref: 'bad' } } }), null);
});

test('safeHttpUrl rejects executable schemes and enforces same-origin navigation', () => {
  const origin = 'https://shop.example.test';
  assert.equal(safeHttpUrl('/product/1', origin, true), 'https://shop.example.test/product/1');
  assert.equal(safeHttpUrl('https://cdn.example.test/image.webp', origin, false), 'https://cdn.example.test/image.webp');
  assert.equal(safeHttpUrl('https://user:pass@shop.example.test/product/1', origin, false), '');
  assert.equal(safeHttpUrl('https://user@cdn.example.test/image.webp', origin, false), '');
  assert.equal(safeHttpUrl('https://evil.example/product', origin, true), '');
  assert.equal(safeHttpUrl('javascript:alert(1)', origin, false), '');
  assert.equal(safeHttpUrl('data:text/html,hello', origin, false), '');
  assert.equal(safeHttpUrl('/product/1', 'not-an-origin', true), '');
});


test('ResilientJsonStore persists valid objects and removes them from both stores', () => {
  const values = new Map();
  const storage = {
    getItem: (key) => values.get(key) ?? null,
    setItem: (key, value) => values.set(key, value),
    removeItem: (key) => values.delete(key),
  };
  const store = new ResilientJsonStore(storage);
  const value = { id: 'conversation', token: 'opaque' };

  assert.equal(store.write('conversation', value), true);
  assert.deepEqual(store.read('conversation'), value);
  store.remove('conversation');
  assert.equal(store.read('conversation'), null);
  assert.equal(values.has('conversation'), false);
});

test('ResilientJsonStore keeps a page-memory fallback when browser storage is unavailable', () => {
  const storage = {
    getItem: () => { throw new Error('denied'); },
    setItem: () => { throw new Error('denied'); },
    removeItem: () => { throw new Error('denied'); },
  };
  const store = new ResilientJsonStore(storage);
  const value = { client_turn_id: 'turn-1', body: { message: 'hello' } };

  assert.equal(store.write('pending', value), false);
  assert.deepEqual(store.read('pending'), value);
  store.remove('pending');
  assert.equal(store.read('pending'), null);
});

test('ResilientJsonStore rejects malformed JSON and non-object values', () => {
  const values = new Map([
    ['invalid', '{'],
    ['array', '[]'],
    ['scalar', '"value"'],
  ]);
  const store = new ResilientJsonStore({
    getItem: (key) => values.get(key) ?? null,
    setItem: (key, value) => values.set(key, value),
    removeItem: (key) => values.delete(key),
  });

  assert.equal(store.read('invalid'), null);
  assert.equal(store.read('array'), null);
  assert.equal(store.read('scalar'), null);
  assert.equal(store.write('undefined', undefined), false);
  assert.equal(store.write('array-write', []), false);
});


test('isAbortError recognizes browser abort failures without treating arbitrary errors as timeouts', () => {
  assert.equal(isAbortError({ name: 'AbortError' }), true);
  assert.equal(isAbortError({ code: 20 }), true);
  assert.equal(isAbortError(new Error('network')), false);
});

test('validateExportPage enforces a stable boundary, count, deep messages, and memory', () => {
  const first = validateExportPage(
    { upperMessageId: 0, afterMessageId: 0, messageCount: null, loadedCount: 0 },
    exportPage({
      upper_message_id: 3,
      message_count: 3,
      next_after_message_id: 2,
      complete: false,
      messages: [historyMessage(1, { client_turn_id: TEST_TURN_ID }), historyMessage(2)],
      shopping_memory: {
        budget_min: 10,
        budget_max: 100,
        categories: ['هواتف'],
        attributes: { اللون: 'أزرق' },
        notes: 'أفضل المنتجات الخفيفة',
      },
    }),
    'conversation',
  );
  assert.equal(first.upperMessageId, 3);
  assert.equal(first.afterMessageId, 2);
  assert.equal(first.messageCount, 3);
  assert.equal(first.loadedCount, 2);
  assert.equal(first.complete, false);
  assert.equal(first.messages.length, 2);
  assert.equal(first.shoppingMemory.budget_min, 10);

  const second = validateExportPage(first, exportPage({
    upper_message_id: 3,
    message_count: 3,
    next_after_message_id: null,
    complete: true,
    messages: [historyMessage(3)],
    shopping_memory: first.shoppingMemory,
  }), 'conversation');
  assert.equal(second.complete, true);
  assert.equal(second.afterMessageId, 3);
  assert.equal(second.loadedCount, 3);
});

test('validateExportPage accepts a canonical empty export', () => {
  const page = validateExportPage(
    { upperMessageId: 0, afterMessageId: 0, messageCount: null, loadedCount: 0 },
    exportPage(),
    'conversation',
  );
  assert.equal(page.complete, true);
  assert.equal(page.messageCount, 0);
  assert.deepEqual(page.shoppingMemory, {});
});

test('validateExportPage rejects identity, boundary, message, memory, and cursor inconsistencies', () => {
  const state = {
    upperMessageId: 10,
    afterMessageId: 5,
    messageCount: 7,
    loadedCount: 5,
    shoppingMemory: { notes: 'ثابتة' },
  };
  assert.throws(() => validateExportPage(state, exportPage({
    conversation_id: 'other', upper_message_id: 10, message_count: 7, messages: [historyMessage(10)],
    shopping_memory: state.shoppingMemory,
  }), 'conversation'));
  assert.throws(() => validateExportPage(state, exportPage({
    upper_message_id: 11, message_count: 7, messages: [historyMessage(10), historyMessage(11)],
    shopping_memory: state.shoppingMemory,
  }), 'conversation'));
  assert.throws(() => validateExportPage(state, exportPage({
    upper_message_id: 10, message_count: 8, messages: [historyMessage(9), historyMessage(10)],
    shopping_memory: state.shoppingMemory,
  }), 'conversation'));
  assert.throws(() => validateExportPage(state, exportPage({
    upper_message_id: 10, message_count: 7, complete: false, next_after_message_id: 5, messages: [],
    shopping_memory: state.shoppingMemory,
  }), 'conversation'));
  assert.throws(() => validateExportPage(state, exportPage({
    upper_message_id: 10, message_count: 7, messages: [historyMessage(10)],
    shopping_memory: state.shoppingMemory,
  }), 'conversation'));
  assert.throws(() => validateExportPage(
    { upperMessageId: 0, afterMessageId: 0, messageCount: null, loadedCount: 0 },
    exportPage({ message_count: 5001 }),
    'conversation',
  ));
  assert.throws(() => validateExportPage(state, exportPage({
    upper_message_id: 10,
    message_count: 7,
    messages: [historyMessage(10, { role: 'system' })],
    shopping_memory: state.shoppingMemory,
  }), 'conversation'));
  assert.throws(() => validateExportPage(state, exportPage({
    upper_message_id: 10,
    message_count: 7,
    messages: [historyMessage(10)],
    shopping_memory: { notes: 'تغيّرت' },
  }), 'conversation'));
  assert.throws(() => validateExportPage(
    { upperMessageId: 0, afterMessageId: 0, messageCount: null, loadedCount: 0 },
    exportPage({ shopping_memory: { budget_min: 100, budget_max: 10 } }),
    'conversation',
  ));
  assert.throws(() => validateExportPage(
    { upperMessageId: 0, afterMessageId: 0, messageCount: null, loadedCount: 0 },
    { ...exportPage(), unexpected: true },
    'conversation',
  ));
});
