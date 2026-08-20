# REST API

Base path: `/wp-json/yassin-ai/v2/`

All `POST` routes require `Content-Type: application/json`. Browser requests must be same-origin according to the available `Origin`, `Referer`, and Fetch Metadata headers. Bodies are strict JSON objects: unknown top-level fields, duplicate object keys, invalid scalar types, excessive depth, and non-finite numeric overflow are rejected. Examples omit unrelated response fields.

## GET `health`

Returns liveness only. Version, provider configuration, WooCommerce compatibility, schema, encryption, session-persistence, and proxy diagnostics are intentionally available only to authenticated administrators.

```json
{ "ok": true }
```

## POST `boot`

Starts a conversation or resumes a valid active one.

Request:

```json
{
  "conversation_id": "optional UUID",
  "token": "optional capability token"
}
```

Both credentials must be supplied together. An invalid, expired, or unavailable resume capability creates a new conversation without disclosing whether the old identifier existed. New conversation creation is subject to browser and configurable global daily limits.

Response:

```json
{
  "ok": true,
  "conversation": {
    "id": "UUID",
    "token": "capability token",
    "expires_at": "2026-09-28T12:00:00+00:00"
  },
  "messages": [],
  "cart": {
    "items": [],
    "line_count": 0,
    "items_truncated": false,
    "item_count": 0,
    "total": 0,
    "total_text": "$0.00",
    "currency": "USD",
    "cart_url": "https://store.example/cart/",
    "checkout_url": "https://store.example/checkout/",
    "cart_hash": "...",
    "mutations_allowed": true,
    "mutation_notice": ""
  },
  "cart_available": true,
  "cart_notice": ""
}
```

When the cart cannot be read, `cart` is `null`, `cart_available` is false, and `cart_notice` is nonempty. The bundled browser validates credentials, message ordering, image metadata, cart shape, and those availability relationships before replacing local state.

## POST `chat`

Processes one idempotent shopper turn.

```json
{
  "conversation_id": "UUID",
  "token": "capability token",
  "client_turn_id": "random 16-64 character ID",
  "message": "أضف قطعتين من المنتج الأزرق",
  "reply": {
    "message_id": 41,
    "product_ref": "p_opaqueReference"
  },
  "image": {
    "mime_type": "image/png",
    "data": "base64 bytes"
  }
}
```

`message` is limited to 4000 Unicode code points. A valid image can replace an empty message. `reply` and `image` are optional.

The browser never supplies authoritative reply text. The server loads the referenced assistant message from the authenticated active conversation. `product_ref` is accepted only when that exact opaque reference was displayed in the referenced message's public product payload. Reply context helps resolve a reference; it does not authorize a cart mutation.

Before transmission, a client must durably retain the exact conversation capability, client turn ID, and retry-safe request fields. Only one unresolved turn may exist per browser conversation. Image bytes must not be written to durable browser storage.


Terminal answer and follow-up functions may explicitly provide `product_refs: []` to render no product cards. Omitting `product_refs` is different: omission may reuse the latest authoritative product shortlist from the current turn. Invalid or stale explicit references never fall back to unrelated cards.

Product cards in `messages[].products` and successful chat responses distinguish a legitimate zero price from an unavailable price:

```json
{
  "ref": "p_opaqueReference",
  "name": "Example product",
  "price": null,
  "price_available": false,
  "price_kind": "unavailable",
  "price_text": "Contact us"
}
```

`price` is either a finite nonnegative JSON number or `null`. `price_available=false` and `price_kind="unavailable"` mean the server could not establish a numeric catalog price; clients must not display or rank that product as free. A legitimate free product uses `price: 0`, `price_available: true`, and a non-`unavailable` price kind.

The server-side `catalog_discover` tool also returns bounded scan metadata to the model: `results_truncated`, `scan_exhausted`, `scanned_candidates`, and `scan_limit`. This metadata is internal tool context rather than a guaranteed top-level REST response field. It prevents the assistant from presenting a bounded shortlist as an exhaustive catalog search.

Read-only success:

```json
{
  "ok": true,
  "conversation_id": "UUID",
  "client_turn_id": "...",
  "turn_id": 123,
  "message_id": 456,
  "turn_finalized": true,
  "kind": "answer",
  "message": "...",
  "products": [],
  "cart": null,
  "receipt": null
}
```

Cart success:

```json
{
  "ok": true,
  "conversation_id": "UUID",
  "client_turn_id": "...",
  "turn_id": 124,
  "message_id": 457,
  "turn_finalized": true,
  "kind": "cart_receipt",
  "message": "تمت إضافة ...",
  "products": [],
  "cart": { "items": [], "item_count": 2 },
  "receipt": {
    "id": "UUID",
    "message": "تمت إضافة ...",
    "lines": [ { "action": "add", "quantity": 2, "name": "..." } ],
    "cart": { "items": [], "item_count": 2 }
  }
}
```

A success is not public until the assistant message has a durable positive `message_id`. Replaying the exact same request ID and content returns the stored result with `replayed: true`. Reusing the ID with different normalized content returns `turn_id_conflict`.

The accepted user message is persisted with the same `client_turn_id`. Boot and export expose that identifier only on public user messages so a reloaded browser can show the pending shopper message exactly once. Internal/system roles and private tool context are excluded.

For a non-2xx response, the browser may conclude that a request was rejected only when the payload is bound to the exact `conversation_id` and `client_turn_id`, has `turn_finalized: false`, and carries one of the documented `request_disposition` values. Otherwise the outcome is ambiguous and the client must call `turn/recover` for the same turn. It must not manufacture a replacement ID.

The server permits only one processing turn per conversation. An exact existing terminal or checkpointed turn remains replayable even if another turn is active. A different fresh owner returns `conversation_busy`. A stale checkpointed blocker is finalized without repeating provider or cart work. A stale uncheckpointed blocker is durably abandoned, and the current turn is failed as `previous_turn_abandoned`; the current request was not executed and must be explicitly resent only after cart inspection.

## POST `turn/recover`

Checks the original result after an interrupted or ambiguous request.

```json
{
  "conversation_id": "UUID",
  "token": "capability token",
  "client_turn_id": "original turn ID"
}
```

Returns a stored terminal result with `replayed: true`, finalizes an existing checkpoint without repeating work, or returns HTTP 202 while a fresh lease remains active:

```json
{
  "ok": true,
  "status": "processing",
  "client_turn_id": "..."
}
```

When an uncheckpointed turn has exceeded its issued lease, recovery durably returns a finalized `turn_abandoned` failure. The shopper must inspect the cart before creating a new request. A caller must never manufacture a new turn merely because recovery is still processing.

The exact retained turn remains recoverable after the conversation's normal inactivity window. This covers completed replies, accepted failures, rejected pre-acceptance turns, checkpoints, and fresh processing leases. Exact replay does not extend `last_activity_at` and does not authorize boot/history, a new chat turn, export, or deletion. Retention expiry and conversation deletion still remove recovery authority.

If the exact ID has no turn, recovery atomically inserts a finalized pre-acceptance `turn_not_found` record while holding the same conversation lock used by chat claims. If a delayed original claim won first, recovery returns that real turn instead. If the absence seal wins, the synthetic request hash permanently prevents the delayed request from executing under that ID. The browser retires the pending record and requires a deliberate new submission with a new ID; it must never silently resend the sealed request. A text draft may be restored, but an image-bearing request must be reattached because browser recovery storage contains no image bytes.

## POST `conversation/export`

Exports a stable bounded snapshot in pages.

First page:

```json
{
  "conversation_id": "UUID",
  "token": "capability token",
  "after_message_id": 0,
  "upper_message_id": 0,
  "limit": 200
}
```

Response:

```json
{
  "ok": true,
  "conversation_id": "UUID",
  "exported_at": "2026-08-14T12:00:00+00:00",
  "upper_message_id": 205,
  "next_after_message_id": 200,
  "complete": false,
  "message_count": 205,
  "messages": [],
  "shopping_memory": {}
}
```

When `complete` is false, preserve the original `upper_message_id` and send `next_after_message_id` as `after_message_id`. Continue until `complete` is true and the next cursor is null.

`limit` is clamped to 1–200. Both cursor fields must be zero for the first page or nonzero for later pages. The response contains only canonical public `user` and `assistant` messages plus a bounded shopping-memory allowlist. It excludes system/internal roles, capability hashes, hidden product authority, request hashes, raw image bytes, unknown memory fields, and malformed commerce payloads. Clients must deeply validate every message, preserve one stable upper boundary/count/memory value across pages, and reject cursor regressions, duplicates, malformed roles/kinds, or aggregate totals beyond their safe limit.

## POST `conversation/delete`

```json
{
  "conversation_id": "UUID",
  "token": "capability token"
}
```

Success:

```json
{ "ok": true, "deleted": true }
```

Deletion is permanent for the rewrite-owned conversation, messages, turns, and memory. The database lifecycle gate prevents new claims or child writes after deletion begins. A fresh processing lease returns `conversation_busy` rather than racing the worker. The bundled widget also blocks deletion while a local turn is unresolved and clears credentials only after validating the exact success response.

## Phase 1 response and presentation semantics

The public REST route set is unchanged. Catalog continuation and cart clarification are internal, bounded assistant-message payloads and are not new public endpoints. The model may receive only a reduced active continuation reference; the browser does not receive a reusable cart authorization token.

A successful cart response remains `kind: cart_receipt` with a verified receipt and fresh cart projection. The widget may use those two validated objects to refresh native WooCommerce presentation surfaces. Such refreshes are client presentation effects only and never create, repeat, or prove a cart mutation.

## Error format

Current storefront clients send `X-YSAI-Client-Contract: 2` on every POST. That opt-in permits the server to add retry authority without breaking an older exact-shape validator. A stale 2.5.2 client that sends no contract header receives the legacy error object with `retry_mode` and `retry_after_seconds` omitted. The HTTP `Retry-After` header may still be present and is safe for intermediaries and clients that understand it.

```json
{
  "ok": false,
  "error": {
    "code": "provider_unavailable",
    "message": "...",
    "retryable": true,
    "retry_mode": "new_turn",
    "retry_after_seconds": 60
  }
}
```

`retryable` is derived from `retry_mode` and remains for compatibility. The retry mode is authoritative:

- `none` — do not offer an automatic or same-request retry.
- `same_turn` — preserve the exact pending `conversation_id` and `client_turn_id` and use recovery; creating a new turn could duplicate an outcome whose persistence is still uncertain.
- `new_turn` — the original turn is already durably finalized. Only an explicit shopper action may create a fresh cryptographically random `client_turn_id` and submit the message again. The widget never converts this mode into same-turn recovery or silently resends it.

A non-finalized response is normalized to `same_turn`, even if malformed stored data claims `new_turn`. A finalized response can never claim `same_turn`; older durable rows are conservatively normalized according to their terminal code.

`retry_after_seconds`, when present, is an integer from 1 through 86400 and requires a retryable mode. It is the earliest time the relevant action should be offered, not permission to retry sooner. The REST response mirrors it in the HTTP `Retry-After` header. The bundled widget hides the retry action until the delay expires. A delayed finalized failure uses a new turn only after explicit shopper action; a delayed non-finalized failure keeps the exact pending identity and recovers the same turn.

Finalized failed chat turns include the exact `conversation_id`, `client_turn_id`, `turn_id`, `turn_finalized: true`, `request_accepted`, and `kind: "safe_failure"`. An accepted failed turn also has a durable positive `message_id`. Ambiguous or incomplete persistence preserves the original identifiers with `turn_finalized: false` and is recovered using the same turn.

Pre-acceptance chat/recovery errors may include an exact `request_disposition`: `rejected`, `conflict`, `processing`, `not_found`, or `unverified`. This field is trustworthy only when the complete response shape and both request identifiers match. The current missing-turn recovery path returns a durable finalized `turn_not_found` record rather than a transient disposition; the `not_found` disposition remains accepted for compatibility with an earlier server response and still never authorizes silent automatic resend. Errors without exact proof—including proxy error pages, malformed JSON, generic server failures, and timeouts—are ambiguous.

Only messages from explicit public application exceptions are returned verbatim. Unexpected internal validation and runtime messages are replaced with stable localized text; exact technical details are retained only in bounded diagnostic logs. Tool execution also maps uncategorized invalid-argument exceptions to fixed remediation before the result enters Gemini history, preventing domain, database, repository, gateway, or extension details from being repeated by the model.

Important recovery errors:

- `conversation_busy` — another fresh turn owns the conversation; recover it first.
- `turn_processing` — this exact turn is still live.
- `turn_finalization_pending` — the result is durable but the assistant message is not yet durably addressable; retry/recover the same turn.
- `turn_persistence_uncertain` — storage outcome could not be proved; retry/recover the same turn.
- `turn_abandoned` — this exact uncheckpointed turn expired and was durably stopped; inspect the cart before a new turn.
- `previous_turn_abandoned` — another stale turn was abandoned and the current request was deliberately not executed; inspect the cart, then resend explicitly.

Other common codes include:

- `json_required`
- `invalid_json`
- `unknown_request_field`
- `request_too_large`
- `cross_origin_rejected`
- `requirements_unavailable`
- `assistant_disabled`
- `incomplete_conversation_credentials`
- `invalid_conversation_id`
- `invalid_conversation_token`
- `conversation_unauthorized`
- `invalid_turn_id`
- `turn_id_conflict`
- `turn_not_found`
- `empty_message`
- `message_too_long`
- `invalid_image`
- `invalid_reply_context`
- `invalid_export_cursor`
- `export_too_large`
- `rate_limited`
- `provider_not_configured`
- `provider_credentials_rejected`
- `provider_access_denied`
- `provider_location_restricted`
- `provider_quota_exhausted`
- `provider_configuration_error`
- `provider_model_unavailable`
- `provider_request_rejected`
- `provider_request_too_large`
- `provider_unavailable`
- `provider_protocol_error`
- `provider_incomplete`
- `provider_error`
- `invalid_request`
- `invalid_request_field`
- `request_failed`
