# Security

## Trust boundaries

The shopper, browser storage, AI output, product text, page content, merchant guidance, image text, extension-provided cart data, provider responses, and all tool arguments are untrusted. WooCommerce and WordPress are authoritative only through validated APIs and fresh server state. The model can request a capability; it cannot perform a database or cart mutation directly.

## Secrets

- A database API key is encrypted with Sodium secretbox when available, otherwise AES-256-GCM through OpenSSL.
- The encryption key is derived from the WordPress authentication salt and a plugin-specific context.
- An unencrypted database key is refused after the startup migration attempt.
- `YSAI_GEMINI_API_KEY` can keep the key outside the database.
- The key is sent through `x-goog-api-key`, never in the provider JSON body.
- Health and storefront configuration do not expose the key.
- Diagnostic logging blocks keys, tokens, authorization values, image data, and request bodies.
- Changing WordPress salts invalidates existing encrypted values; re-enter the key afterward.

## Conversation authentication and lifecycle

Each conversation receives a random UUID and a high-entropy URL-safe token. The browser receives the token; the database stores only its keyed hash. Possession of the token grants access to that conversation's history, export, deletion, and turn recovery, so it is a bearer capability.

The normal inactivity window closes boot/history, new turns, export, and deletion. It does not erase the ability to replay one exact retained high-entropy `client_turn_id`, because a network response may be lost after a cart mutation or assistant result is durable. Exact replay is bounded to that turn, never refreshes conversation activity, and remains subject to retention expiry and lifecycle deletion. An absent exact ID is sealed as a durable rejection while holding the claim lock, including after inactivity, so a delayed authenticated request cannot execute after recovery proved non-acceptance.

The default widget stores the capability in browser storage to resume a conversation. Sites with stricter privacy requirements should disable the widget or use suitable browser/storage policy controls. Tokens must not be placed in URLs, analytics, support tickets, or logs.

The database conversation row has an explicit lifecycle state. Authentication and writes require `active`. Deletion first changes lifecycle under a row lock; new child inserts and claims cannot continue once deletion owns the lifecycle boundary. A fresh processing lease blocks deletion. This server-side rule is required even though the bundled browser also serializes deletion with recovery.

## Turn ownership and idempotency

- The client supplies a cryptographically random 16–64 character turn ID.
- The server binds it to a hash of the exact normalized message, reply context, and image metadata/hash.
- Only one processing turn may exist per conversation.
- Exact terminal or checkpointed replay is still available even when another turn is active.
- Every claim has a monotonically increasing generation and an immutable persisted lease duration.
- Heartbeats before provider, tool, intent, and cart-write boundaries prove current ownership.
- A stale worker cannot heartbeat, checkpoint, complete, or fail after another worker reclaims the turn.
- A late checkpoint finalization may only add the exact durable assistant-message ID; it cannot alter checkpointed content.
- A stale uncheckpointed turn is never assumed safe to repeat. It is durably abandoned and the shopper must inspect the cart.

## Request protection

- Storefront writes require `application/json` with identity content encoding.
- Request bodies have a six-million-byte boundary and a bounded JSON depth.
- Duplicate object keys are rejected, including escaped-equivalent names such as `name` and `\u006eame`.
- JSON numbers that decode to non-finite values are rejected.
- Unknown top-level fields and scalar coercion are rejected.
- The public health route returns liveness only. Detailed readiness and deployment posture require administrator capability.
- Only explicitly public application exceptions may expose their message; unexpected internal validation/runtime messages are replaced with stable localized text and kept only in bounded diagnostic logs. Generic invalid-argument failures from domain, repository, gateway, database, or extension code are also converted to fixed remediation before tool results enter model-visible history.
- `Origin`, `Referer`, and `Sec-Fetch-Site` are checked when available; alternate ports, credentials, backslashes, and foreign hosts fail.
- Same-origin checks are defense in depth, not authentication. Conversation capabilities protect existing state.
- Browser identities use `REMOTE_ADDR` by default, with IPv6 grouped by `/64`; attacker-controlled User-Agent and arbitrary cookies are excluded.
- A forwarded address is accepted only when the immediate peer matches an explicitly configured trusted-proxy CIDR and the selected `Forwarded` or `X-Forwarded-For` chain passes bounded right-to-left validation. Untrusted peers, malformed chains, trust-all `/0` networks, and unselected forwarding headers are ignored or rejected fail-closed.
- Browser, conversation, operation, global daily AI-turn, and global daily conversation-creation limits are enforced in the database. Storage failure fails closed.

## AI and prompt-injection controls

- Gemini requests are stateless (`store: false`) and use the generally available stable `v1/interactions` endpoint. No beta migration revision header is sent.
- Request JSON is limited to 8 MiB and response data to 2 MiB through WordPress HTTP response limiting plus a local byte check.
- Provider JSON must be one strict object with no duplicate keys.
- Interaction status, steps, declared function names, object arguments, local argument schemas, opaque call-ID uniqueness, and status/call consistency are validated before tool execution. Provider-issued call IDs are bounded for Unicode/control safety but otherwise copied byte-for-byte.
- Every function schema is converted at the provider boundary to explicit JSON object maps; no-argument functions use `properties:{}`. A shared projector sends only the portable provider subset, while the original stricter schema remains authoritative locally for lengths, patterns, bounds, required fields, and unexpected fields. Tool-call and history budgets are bounded.
- Raw model prose is not accepted as the storefront terminal response. Production chat forces function selection whenever tools are present, and the model must finish through one terminal response function.
- Structured output is validated locally against a constrained JSON Schema subset after provider-side enforcement.
- Prior conversation history, product, page, merchant, and image content is treated as quoted untrusted data inside the current request, not instruction authority or model-prefill output.
- The model never receives a database token hash, API key, raw cart key, or internal product identifier as an actionable public reference.

## Cart authorization and integrity

A cart write requires a fresh logical cart snapshot and a matching signature obtained from a direct durable WooCommerce session read. It also requires exact evidence from the current shopper message and an independent schema-constrained authorization decision bound to the exact plan and cart state.

Opaque product and cart-line references include server-owned fingerprints. The cart adapter renews turn ownership under the cart lock, revalidates current product/variation/stock/quantity state, runs WooCommerce validation hooks, and durably verifies the operation-start journal before execution. The request-local result is checked before the write. The bundled persistence adapter then lets WooCommerce save the complete session but returns no state from that write. The gateway invalidates the possible session cache entry and performs a separate direct read of the canonical `woocommerce_sessions` row, decodes bounded nested session values without instantiating serialized PHP classes, and compares the durable cart signature with the authorized result.

Rollback uses the same separated boundary. Reconstructing the PHP cart object is not proof of rollback; after the rollback write, the restored signature must be observed in a new cache-bypassing durable read. Database/cache disagreement, a lost write response, failed rollback verification, or a missing replay receipt fails closed as uncertain and must not be automatically retried.

Cart lock identity never falls back to an IP address or forwarded header. A mutation requires a logged-in user or a validated WooCommerce customer-session identifier. When that ownership identity cannot be established, catalog and cart inspection remain available but assistant cart mutation fails closed.

## JSON and persistence integrity

Persisted message, memory, turn, receipt, and cart-journal documents have byte, depth, node, item, key, string, Unicode, and finite-number limits. Database reads validate exact types and dates instead of coercing corrupt rows.

Conversation history uses a metadata-first read and accepts only a stable newest suffix under an 8 MiB aggregate source budget. The second read must return the exact IDs and source byte counts observed in the metadata read; drift is rejected.

The installer verifies the complete table, column, index, engine, character-set, and collation contract before publishing a schema version.

## Public transcript and replay boundary

- Repository history and export queries select only `user` and `assistant` roles; the application repeats that filter before serialization.
- User history exposes only durable identifiers, bounded text, optional safe image metadata, and the originating `client_turn_id` used for reload deduplication.
- Assistant history recognizes only the current public response kinds. Unknown kinds become inert text; failures and uncertain outcomes carry no product, receipt, or cart authority.
- Terminal replay responses are reconstructed from exact success/error allowlists. Stored unknown fields and private underscore-prefixed payloads are never recursively echoed.
- Public carts, receipts, products, shopping memory, and export pages are independently canonicalized and bounded before the browser validates them again.
- A browser clears a pending idempotency record only after an exact identity-bound terminal result or conclusive pre-acceptance disposition. Unbound HTTP errors remain recoverable ambiguity. A durable missing-turn tombstone is terminal and retires that ID; the browser restores text only as a draft for a deliberate new turn and never silently resends it.

## Output and navigation

- Browser rendering uses text nodes and element properties instead of injecting model HTML.
- Public product, cart, content, and receipt projections are bounded.
- Product and image links permit only HTTP(S); store policy, cart, and checkout links are same-origin.
- URLs containing credentials, control characters, backslashes, unsafe schemes, or invalid ports are rejected.
- Browser boot and deletion payloads are validated as strictly as chat and export payloads before local credentials or history are changed.

## Images

Only JPEG, PNG, and WebP are accepted. Base64, decoded bytes, MIME, dimensions, and a 12-million-pixel budget are verified. The canonical bytes are sent only in the current provider request. Database and browser recovery records store metadata, not image bytes.

## Phase 1 transient context and presentation security

Catalog continuation and cart clarification are untrusted conveniences. References are opaque and bounded, continuations are one-use and generation-scoped, expired or malformed state fails closed, and terminal tombstones prevent older history from resurrecting cleared state. Active clarification is accepted only when its referenced product authority is still present. Sensitive-looking clarification text is rejected rather than persisted.

`preserve_source` does not accept a remembered quantity. The quantity is taken from the fingerprint-verified current source line only after the per-cart named lock and durable freshness checks are held. The separate current-message intent authorization remains mandatory.

Native cart convergence starts only from a validated server receipt. It uses GET for a same-origin cart page, enforces an HTML media type, fatal UTF-8 decoding, a 768 KiB body limit, a 5,000-node limit, same-origin URL rewriting, blocked executable/custom elements, and removal of executable or dangerous attributes before and after DOM import. It never calls a mutation endpoint.

## Administration

Administrative settings use WordPress capabilities and nonces. Destructive operations require explicit actions. Uninstall deletes data only when the stored destructive flag has the exact accepted value. Unknown settings are ignored and stored values are normalized before runtime use.

Trusted-proxy CIDRs and the selected forwarding header can be configured in administration or with `YSAI_TRUSTED_PROXY_CIDRS` and `YSAI_TRUSTED_PROXY_HEADER` in `wp-config.php`. Constants take precedence. A forwarding header must never be enabled merely because one appears in a request; operators must identify the exact network addresses of the controlled proxy edge.

## Deployment checklist

- Use HTTPS for the storefront and administration.
- Protect `wp-config.php` and WordPress salts.
- Confirm Sodium or OpenSSL is available.
- Restrict database permissions while allowing the verified schema, named locks, and direct read access to WooCommerce's session table.
- Ensure WP-Cron or a real cron runner executes scheduled cleanup.
- Set daily conversation and AI-turn limits appropriate to provider cost and traffic.
- For proxy/CDN deployments, configure only the exact immediate proxy CIDRs, select the one header the edge overwrites, and verify the administration diagnostic from a real storefront request. Leave proxy trust empty when WordPress receives shoppers directly.
- Test every extension that modifies prices, stock, quantities, product types, cart lines, coupons, totals, sessions, or checkout. Confirm the built-in database session handler is active, or install a reviewed `CartSessionPersistence` adapter for the custom handler.
- Run the destructive WooCommerce/database/object-cache integration suite on a disposable local, development, or staging clone and retain its output as release evidence.
- Review Content Security Policy and all third-party image/script origins.
- Keep diagnostic logging off except during bounded incident investigation.
- Rotate the Gemini key after suspected exposure.

## Residual risks

- A stolen conversation capability grants access until deletion or expiry.
- Browser storage is accessible to JavaScript running on the same origin; an XSS vulnerability elsewhere on the site can compromise capabilities.
- Prompt injection cannot be eliminated; the architecture limits its authority through tools and independent validation.
- Third-party WooCommerce extensions may have state not fully represented by the generic cart snapshot. Representative staging tests remain mandatory.
- A process can fail after a commerce write and before every replay marker is durable. The system reports uncertainty and requires cart inspection rather than claiming safe retry.
- The bundled persistence adapter supports only the exact built-in WooCommerce database session handler. Custom handlers keep cart viewing available but disable assistant mutations unless a reviewed adapter proves equivalent cache-bypassing durable read-after-write behavior.
- Incorrect trusted-proxy CIDRs can collapse unrelated shoppers into one rate-limit identity or, if too broad, make an untrusted forwarding chain authoritative. The implementation rejects `/0`, but deployment-specific proxy ranges still require operator review.
- WP-Cron is traffic-dependent unless the site configures a real scheduler; delayed cleanup extends retention until the next successful run.
