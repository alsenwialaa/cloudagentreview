# Architecture

## Design goals

The system is organized around product rules and trust boundaries, not WordPress hooks, the legacy implementation, or provider payloads. Domain and application code do not depend on global WordPress or WooCommerce APIs. Infrastructure adapters are replaceable, presentation code has no independent authority to mutate commerce state, and every state transition has an explicit durable boundary.

## Components

### Domain

`src/Domain` contains bounded value rules:

- UUID and URL-safe encoding utilities;
- conversation credentials;
- cart actions, commands, plans, quantity rules, and receipts.

These classes validate invariants before infrastructure is reached.

### Application

`src/Application` defines ports and workflows:

- `ChatService` authenticates conversations, validates input, applies limits, claims idempotent turns, resolves blockers, persists messages, and controls recovery.
- `TurnTimingPolicy` derives the provider budget, browser timeout, and persisted lease from one contract.
- `RepositoryTurnGuard` renews and verifies turn ownership before long-running or authority-bearing work.
- `AgentLoop` drives a bounded function-calling loop and accepts only one terminal response call.
- `ToolRegistry` exposes store capabilities, enforces fresh-cart, exact-evidence, independent-intent, and one-mutation-per-turn rules, and replaces uncategorized invalid-argument details with fixed remediation before tool results become model-visible.
- `PromptFactory` serializes prior public transcript as bounded untrusted data inside the current user input and attaches image bytes only to that current input; persisted assistant replies are never synthesized as model-prefill output.
- `IntentVerifier` requests a separate schema-constrained authorization decision.
- Contracts isolate AI, catalog, cart, content, clock, persistence, settings, rate limiting, lifecycle, and turn ownership.

### Infrastructure

`src/Infrastructure` implements the ports:

- Gemini Interactions stable `v1` API over WordPress HTTP, with bounded request/response bodies, strict response-envelope checks, duplicate-key JSON rejection, function-only production chat, portable wire-schema projection, strict local validation of every original schema and selected function argument, and exact stateless replay of raw provider steps;
- fresh WordPress database repositories and schema;
- lifecycle-aware conversation persistence and immutable per-turn leases;
- WooCommerce-native catalog retrieval through the product data store and paginated product queries, with bounded candidate scans and live product-object filtering;
- WooCommerce content, cart, stock, variation, checkout, and durable session-persistence adapters;
- Sodium or OpenSSL authenticated encryption;
- trusted-proxy-aware HMAC request identity and token hashing;
- atomic database rate limits and named cart locks.

### Presentation

`src/Presentation`, `templates`, and `assets` provide:

- JSON-only REST endpoints;
- WooCommerce administration;
- accessible RTL storefront dialog;
- browser capability persistence, durable pending-turn identity, image-bytes-in-memory-only recovery, strict boot/delete response validation, safe DOM rendering, and same-origin navigation.

## Conversation lifecycle

The conversation row is the lifecycle authority. `lifecycle_state` is `active` during normal use and is changed transactionally before deletion. New turn claims, message inserts, memory updates, activity updates, export reads, and turn lookups require an active conversation.

Deletion and retention cleanup lock the conversation row. A fresh processing lease causes deletion to fail with `conversation_busy`. An expired uncheckpointed processing turn may be durably abandoned; checkpointed work must be finalized before deletion. Once lifecycle leaves `active`, no new child insert can use that conversation as authority.

Only one processing turn is permitted per conversation. That rule applies when creating a genuinely new turn; it does not prevent exact replay of an existing completed, failed, or checkpointed turn.

## Chat request flow

1. The browser boots or resumes one capability-authenticated conversation. The server returns only public `user` and `assistant` history projections plus a separately read current cart snapshot.
2. Before transmission, the browser creates a cryptographically secure `client_turn_id` and writes a bounded pending record to `sessionStorage`. Image bytes remain only in page memory.
3. The pending shopper bubble is keyed by `client_turn_id`. After reload it is rendered from the pending record only when durable history does not already contain that exact user turn.
4. The browser allows one unresolved turn. Send, recovery, export, and deletion are serialized; a timeout or malformed/unbound response preserves the original pending record.
5. The REST boundary accepts only strict same-origin JSON. A pre-acceptance error may clear a pending turn only when the server returns the exact conversation ID, client turn ID, and an explicit identity-bound disposition. An intermediary-generated or malformed HTTP error is never treated as proof that the request did not run.
6. `ChatService` authenticates the capability, validates message/reply/image data, applies rate limits, and claims the idempotent turn using the normalized request hash, generation fence, and immutable lease duration.
7. Existing terminal turns replay from a strict public allowlist. A checkpointed turn finalizes without repeating provider or cart work. A different live processing turn blocks the request.
8. A stale checkpointed blocker is finalized. A stale uncheckpointed blocker becomes `turn_abandoned`; the new request becomes `previous_turn_abandoned` and is deliberately not executed because an earlier cart outcome may be unknowable.
9. Acceptance of the shopper message and extension of conversation activity occur atomically while the exact turn generation still owns a fresh lease. A stale worker cannot append a ghost message or extend retention.
10. Reply text and displayed-product authority are loaded from the referenced durable assistant message. Browser-supplied reply prose is not authoritative. Image metadata may be stored; image bytes are not.
11. `PromptFactory` reconstructs bounded public history and product context. History is limited by row count and an 8 MiB metadata-first stable suffix, then embedded as untrusted JSON data in one current `user_input`; internal/system rows never enter public history, export, or prompt reconstruction, and historical assistant text is never sent as synthetic model output.
12. Before transport, `FunctionToolValidator` validates the exact closed object schema for every production function. `GeminiSchemaProjector` converts schema maps to explicit JSON objects and removes local-only constraints from the wire representation while the original schema remains authoritative locally. Zero-argument functions therefore emit `properties:{}` rather than PHP's ambiguous empty-array representation.
13. `AgentLoop` renews ownership before provider and tool boundaries. Production chat sets function-only selection whenever tools exist; a direct-prose completion is a protocol failure, not a public answer. Gemini responses are locally validated for JSON, status, steps, declared function names, opaque unique call IDs, argument object shape, the original strict argument schemas, and status/call consistency. Raw model-generated steps are preserved in JSON-native form and replayed byte-for-byte with matching `function_result` identifiers during stateless continuation.
14. Shopping-memory writes are atomically bound to the same live turn generation. Cart writes additionally require exact current-message evidence, independent intent authorization, a fresh cart view, one mutation attempt, and durable session verification.
15. The loop ends with exactly one terminal response or a verified cart receipt. Raw model prose is not a public result. An explicit empty terminal `product_refs` list means no cards; only omission may reuse the latest authoritative shortlist. A malformed receipt or uncertain cart outcome cannot assert products or a fresh cart snapshot.
16. The internal result is checkpointed before assistant-message delivery. Public success requires a terminal turn and a durable positive assistant `message_id`. An accepted failed turn likewise remains pending until its assistant failure message is durable.
17. Recovery completes checkpoint/finalization work using the original identity. One exact retained turn remains replayable after the normal inactivity window, including an already presented terminal result, because the browser may have lost the response after provider or cart work became durable. This exact replay does not touch conversation activity and does not reopen boot/history, new chat, export, or deletion.
18. If the exact identity is absent, recovery takes the same conversation row lock as `claim()`, inserts a finalized pre-acceptance tombstone with a synthetic request hash, and returns it. If a delayed real claim won first, recovery returns the real turn. If the tombstone won first, the later claim conflicts and cannot execute after a not-accepted result was delivered.
19. Stored terminal responses, historical messages, cart projections, receipts, exports, and shopping memory are rebuilt through bounded public allowlists; unknown assistant kinds become inert text. Only after that exact contract validates does the browser clear the pending record.
20. A pre-claim chat authorization error remains ambiguous because an intermediary may have produced it or another copy may already own the turn. The browser preserves the pending identity until `turn/recover` returns an exact `unverified` disposition. Exact unauthorized export or deletion responses retire stale local authority immediately because those non-turn operations have no idempotent commerce work to recover.

## Lease and timing model

Each turn stores the lease duration issued at claim time. Heartbeat, stale detection, reclaim, and recovery use that stored value. Changing administration timeout or tool-round settings therefore affects only newly claimed turns.

`TurnTimingPolicy` calculates both browser timeout and server lease from the same bounded provider budget. The browser timeout is always at least fifteen seconds shorter than the lease. This does not cancel server work, but it prevents the browser from treating a request as live after the initial durable lease can expire and leaves time for recovery traffic.

A claim generation fences workers. A worker that loses ownership cannot heartbeat, checkpoint, complete, or fail the turn. Late finalization after lease expiry is allowed only for an already durable checkpoint and only by adding its exact assistant-message ID without changing any checkpointed response field.

## Catalog retrieval and price semantics

Text search obtains relevance-ordered IDs from WooCommerce's product data store. Browse and alternative discovery use paginated `wc_get_products()` calls. The gateway never uses `_price`, `_stock_status`, or a raw WordPress metadata query as a correctness filter; every candidate is rechecked through the live product object so extension-filtered visibility, price, sale, and stock state remain authoritative.

Each operation scans at most 240 candidates in pages of 24. Results report whether the source was exhausted or the bounded scan/response limit truncated the available set. Category IDs and names are resolved to WooCommerce category slugs before querying.

A catalog price is either a finite nonnegative number or unavailable. Unavailable prices are projected as `null` with `price_available=false` and are excluded from numeric value scoring. Numeric zero remains a valid available price and is never inferred from parsing failure.

## Phase 1 catalog context and storefront convergence

`CatalogRecallPlanner` composes a small deterministic query plan from normalized shopper text, bounded transliteration variants, and `CatalogSynonymMap`. `WooCatalogGateway` remains the authority for live products; the planner only broadens recall. `CatalogBestMatchRanker` ranks known projections deterministically from grounded query/memory signals and stable product identity.

`ToolContext` owns one active catalog continuation generation and one typed cart clarification. Continuations are opaque, one-use, expiring, tombstoned after consumption, bounded to twelve persisted entries, and limited to a combined 240 seen/excluded product IDs. Clarification is also expiring and tombstone-safe; product authority is restored before clarification and an active referenced product is promoted into the bounded persistence window. Both contexts are persisted in internal assistant payloads and projected to the model only in reduced form. Neither grants cart authority.

`NativeCartSynchronizer` receives only a locally validated verified receipt and fresh cart projection. It deduplicates by receipt/cart revision, schedules three bounded presentation passes, triggers classic WooCommerce and Blocks refresh mechanisms, and may replace one same-origin classic cart container after byte-limited UTF-8 HTML parsing and two-stage sanitization. It has no POST or assistant mutation path.

## Cart commit protocol

Cart mutations have a stricter path than read-only tools:

1. The current agent turn must call `cart_view`.
2. `cart_apply` must be the only function call in its model step.
3. Its evidence must be an exact substring of the current shopper message.
4. A separate structured intent check must authorize the exact proposed plan.
5. Only one mutation attempt is allowed in a turn.
6. The cart view must match a signature derived from a direct durable-session read; otherwise the assistant remains read-only.
7. The adapter acquires a per-cart MySQL named lock, renews turn ownership, and re-reads the canonical WooCommerce session row inside the lock without using the WordPress object cache.
8. Opaque references are resolved and fingerprints are checked against current product/cart state.
9. Product visibility, exact variation, purchase state, stock, min/max quantity, sold-individually rules, and WooCommerce validation filters are checked.
10. Ownership is renewed again and a durable operation-start record is written and verified before any command runs.
11. The cart and coupons are snapshotted; commands execute; totals and the request-local result are verified before the session write.
12. WooCommerce assembles and writes its complete session payload through `CartSessionPersistence::persist()`. The write returns no state and is never treated as proof. The possible session cache entry is invalidated, then `CartSessionPersistence::read()` performs a separate canonical read and requires the durable cart signature to match the authorized result.
13. On failure, the snapshot is reconstructed in memory and written again. Rollback succeeds only when another separate cache-bypassing durable read matches the original signature; cache-only or request-local agreement is insufficient.
14. On success, a durable completed-operation receipt is merged with directly read journal state and verified through the same persistence boundary before the server creates a fresh cart projection.
15. Replays return only a completed receipt bound to the same operation and plan fingerprint. An incomplete journal entry is treated as uncertain and is never silently re-executed.
16. The application checkpoints the turn before final message persistence, making retries delivery operations rather than new commerce operations.


### WooCommerce session persistence boundary

`WooCartGateway` does not call `get_session_data()` or trust the object cache as durable evidence. It depends on `CartSessionPersistence`, whose write and proof operations are deliberately separate: `persist()` asks the active handler to write its complete request-local state and returns no state, while `read()` returns selected logical values only after a cache-bypassing canonical read.

The bundled `WooDatabaseCartSessionPersistence` supports the exact built-in `WC_Session_Handler`. It lets WooCommerce assemble and save the complete session, checks the database error state, invalidates the corresponding cache entry, reads the matching row from `<prefix>woocommerce_sessions` directly, validates expiry and bounded serialization, and returns decoded cart, coupon, and replay-journal values without instantiating serialized PHP classes. The same separated write/read sequence is mandatory for commits, rollback verification, operation-start journals, and completed replay receipts.

A custom WooCommerce session handler is not assumed compatible because method names alone do not prove durable read-after-write behavior. A store may inject an explicit adapter through `ysai_cart_session_persistence`; without one, cart inspection remains available but conversational mutations are disabled.

### Network and cart ownership identity

Anonymous rate-limit identity is derived from a canonical client network. The secure default uses `REMOTE_ADDR` directly. A `Forwarded` or `X-Forwarded-For` address becomes authoritative only when the immediate peer belongs to an explicitly configured trusted-proxy CIDR. `TrustedProxyResolver` bounds header and chain size, canonicalizes IPv4/IPv6, walks the chain from the trusted edge inward, rejects malformed or ambiguous nodes, and ignores forwarding input from untrusted peers. Trust-all `/0` networks are rejected.

Network identity is not cart ownership. `CartLock` accepts only a logged-in WordPress user or a validated WooCommerce customer-session identifier. If a guest session cannot be established before headers are sent, conversational cart mutations fail closed while catalog and cart-reading features remain available.

## Persistence

The new schema owns four InnoDB tables:

- `ysai_v2_conversations`: token hashes, lifecycle state, bounded shopping memory, activity, and expiry;
- `ysai_v2_messages`: one user and one assistant record per turn, plus bounded JSON payloads;
- `ysai_v2_turns`: request hashes, processing/completed/failed states, claim generations, immutable lease seconds, response checkpoints, and error codes;
- `ysai_v2_rate_limits`: atomic fixed-window counters.

There are no foreign keys because WordPress installations commonly use table-management and migration tools that make cross-table constraints brittle. Referential cleanup is explicit, transactional, and lifecycle-gated.

Schema upgrades are published only after the installer verifies every required table; the configured WordPress character set and table/column collation; column type, signedness, nullability, default, and auto-increment contract; every required unique and non-unique index with exact full-width column order; usable visible BTREE status; and the InnoDB engine. A partial or drifted migration disables the assistant.

## Failure semantics

- Validation failures are bounded public errors and do not run the model or cart operation unnecessarily.
- Provider transport or protocol failures are redacted before they reach the shopper. Transient transport, 408/409/425/429, and 5xx responses receive at most three attempts inside one configured provider deadline; retries happen before a provider step is accepted by the agent loop and therefore cannot repeat a WooCommerce tool effect.
- A result that cannot be durably checkpointed or finalized returns `retry_mode: same_turn` with the original turn ID. A durably finalized transient provider failure returns `retry_mode: new_turn` and requires explicit shopper submission under a fresh ID. Optional `retry_after_seconds` is mirrored in the HTTP `Retry-After` header, and the browser withholds the corresponding action until that complete delay expires.
- Provider retries share one wall-clock deadline. A server-supplied `Retry-After` is treated as the earliest permissible retry time and is never shortened; another attempt is made only when the full delay, a usable transport window, and a finalization reserve all fit. Canonical structured reason/status codes outrank incidental prose during error classification.
- Current browsers opt into error contract 2 with `X-YSAI-Client-Contract: 2`. During a rolling deployment, clients without that header receive the legacy exact error object so cached 2.5.2 JavaScript cannot misclassify a new server response.
- A successful result without a durable assistant message remains pending rather than being reported as complete.
- Lease loss resolves the newer durable turn state; a stale worker cannot overwrite it.
- A stale uncheckpointed turn becomes a durable failure and requires cart inspection before a new request.
- Rate-limit storage fails closed.
- A cart result is not declared successful until a direct durable-session read matches the authorized post-state.
- A failed cart write becomes terminal `rolled_back` only after a separate direct durable read proves the complete authorized pre-state was restored. That terminal marker is evictable and exact replay cannot execute the mutation again; genuinely ambiguous outcomes remain `started` and fail closed.
- A committed cart change is not reclassified as a safe retry when its replay receipt cannot be durably recorded; the shopper is told to inspect the cart.
- Named-lock release failures are logged but never replace the already-completed protected operation result.
- Cleanup-scheduler registration failure is degraded rather than fatal: schema verification still fails closed, while REST, administration, and the widget remain registered and expose an operator warning. Failed scheduling and its diagnostic log are retried at most once per bounded backoff window rather than on every WordPress bootstrap.
- A checkpointed response remains recoverable if assistant-message or final-status persistence fails.

## Widget presentation architecture

The storefront UI remains a presentation adapter. `Settings` owns bounded persisted options, `WidgetAppearance` normalizes presets and colors and derives contrast-safe CSS tokens, `StorefrontWidget` projects only public configuration, `templates/widget.php` provides semantic RTL markup, and `assets/js/widget.js` renders messages while delegating durable turn identity and response validation to `client-utils.js`.

The administration appearance editor uses the same normalized preset and token policy but renders an isolated live preview. It cannot inject arbitrary CSS or alter conversation, product-reference, cart, capability, or retry authority. The widget redesign therefore changes visual presentation without weakening application-layer lifecycle and commerce invariants.

Static cross-layer checks plus real Chromium storefront and administration contracts guard that separation.

