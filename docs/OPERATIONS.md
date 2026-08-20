# Operations

## Readiness

The administration page reports plugin, PHP, WordPress, WooCommerce, provider, encryption, schema, cleanup, cart-session, and trusted-proxy readiness. Run the Gemini connection test after saving or rotating the key. The test checks strict structured output and then sends the exact production function-tool bundle, forcing one terminal function call; a structured-output-only success is not treated as chat readiness.

Before enabling production traffic, verify:

- the exact packaged ZIP is installed;
- schema version 2.5.4 is published only after the unchanged 2.4 table contract, including `lifecycle_state` and `lease_seconds`, is present and verified;
- WordPress can schedule `ysai_daily_cleanup`; when registration fails, confirm REST/admin/widget remain available, the warning persists, and ordinary storefront requests do not attempt remediation. With working option storage, privileged administration, WP-Cron, and WP-CLI honor the one-hour backoff. When that throttle cannot be durably stored, diagnostic logging is suppressed rather than repeated on every eligible request;
- InnoDB and MySQL named locks are available;
- the exact built-in WooCommerce database session handler is active, or a reviewed `CartSessionPersistence` adapter is installed for the custom handler;
- HTTPS and same-origin REST requests work through the production proxy/CDN;
- the trusted-proxy diagnostic is healthy for the actual deployment path, or proxy trust is intentionally empty for a direct deployment;
- one low-risk cart mutation returns a receipt, is visible from a separate request, and survives an interrupted response;
- a controlled database-write failure cannot be hidden by a successful object-cache update, and rollback is accepted only after the database row returns the original cart signature.
- the opt-in real WooCommerce/database/object-cache fault suite has run successfully on a disposable environment matching production's session and cache topology.


## Cart-session persistence readiness

The default adapter supports only the exact built-in `WC_Session_Handler` backed by `<prefix>woocommerce_sessions`. It uses WooCommerce to assemble and save the complete session, returns no state from the write, invalidates the possible cache entry, then performs a separate direct read of the canonical row. The administration warning is a release gate for assistant cart mutations, not a cosmetic compatibility notice.

Stores with a custom session handler must provide an implementation of `YassinStore\AiAssistant\Infrastructure\WooCommerce\CartSessionPersistence` through the `ysai_cart_session_persistence` filter. The adapter must:

- expose a write operation that returns no state and never claims persistence proof;
- expose a separate canonical read that returns selected logical cart, coupon, and journal values without trusting request-local or shared cache state;
- prove commits, rollbacks, operation-start journals, and completed receipts only through that separate read;
- invalidate conflicting cache entries after uncertain writes;
- fail closed on malformed, expired, missing-required, or unverifiable state;
- report readiness accurately before mutations are enabled.

Do not wrap a custom handler's ordinary `get_session_data()` method and call that durable verification unless its storage contract independently guarantees a cache-bypassing canonical read.

## Trusted proxy identity

Leave `trusted_proxy_cidrs` empty when WordPress receives shoppers directly. In that mode, forwarding headers are ignored and anonymous rate limiting uses the canonical `REMOTE_ADDR` network.

When WordPress is behind a controlled CDN, load balancer, ingress, or reverse proxy:

1. Identify the exact source CIDRs from which that proxy connects to WordPress.
2. Configure those CIDRs in **WooCommerce → AI Assistant** or define `YSAI_TRUSTED_PROXY_CIDRS` in `wp-config.php` as a comma, whitespace, or newline-separated list.
3. Select the one header the edge overwrites: `x-forwarded-for` or `forwarded`. `YSAI_TRUSTED_PROXY_HEADER` can set the same value in `wp-config.php`.
4. Verify the administration diagnostic from traffic that traverses the real edge.
5. Confirm a direct request from an untrusted peer cannot change its bucket by supplying forwarding headers.

The resolver accepts a forwarded chain only when the immediate `REMOTE_ADDR` is trusted, parses at most 16 hops and 4096 bytes, walks from the trusted edge inward, canonicalizes IPv4/IPv6, and rejects malformed, ambiguous, or trust-all `/0` configurations. Constants take precedence over database settings.

Forwarded identity is used only for anonymous rate limiting. `CartLock` never uses an IP address. A cart mutation requires a logged-in user or a validated WooCommerce customer-session identifier; when that cannot be established, the assistant remains read-only for cart changes.

## Configuration guidance

- Keep provider timeout and tool rounds as low as the catalog workload permits. `TurnTimingPolicy` derives the browser timeout and persisted lease from these settings; changes affect newly claimed turns only.
- Set `daily_conversation_limit` to bound anonymous conversation creation separately from AI-turn usage.
- Set `daily_ai_turn_limit` to a provider-cost ceiling. Browser and per-conversation five-minute limits remain independent.
- Keep output tokens and displayed product cards bounded.
- Retention should match the store's published privacy policy.
- A nonzero inactivity timeout ends a browser conversation independently of the longer retention date.
- Enable diagnostic logging only for a bounded investigation window.
- Keep policy and account links on the WordPress home origin.

## Phase 1 merchant and storefront operations

Catalog synonym groups are maintained in **WooCommerce → AI Assistant**. Use one group per line and separate equivalent terms with `|`, `=`, an English comma, or an Arabic comma. A group needs at least two distinct normalized terms. Invalid input is rejected while the last valid value remains active.

After a verified cart change, the assistant cart projection is authoritative. Classic mini-cart/cart/checkout and Blocks refreshes are best-effort presentation convergence with bounded retries. A third-party theme or extension that replaces those surfaces must be qualified on staging; failure to refresh a custom surface does not invalidate the server receipt and must not cause the operation to be repeated.

Continuation and clarification expire after 30 minutes. A shopper may need to restate the request after expiry, a new search traversal, a terminal turn, or catalog authority loss. Operators must never recover or edit these opaque values manually.

## Turn states and operator meaning

- `turn_processing`: the exact request still has a fresh lease. Recover it; do not create a replacement ID.
- `conversation_busy`: another fresh request owns the conversation. Recover that request before sending or deleting.
- `turn_finalization_pending`: the response is durable but its assistant message is not yet durably addressable. Retry/recover the exact turn.
- `turn_persistence_uncertain`: storage outcome could not be proved. Preserve the exact turn identity and recover.
- `turn_abandoned`: an uncheckpointed request exceeded its issued lease and is no longer running. Inspect the cart before a new request.
- `previous_turn_abandoned`: the server abandoned a different stale turn and deliberately did not execute the current request. Inspect the cart, then explicitly resend.
- `turn_not_found`: recovery durably proved and sealed that the exact ID was never accepted. The original ID is retired. Restore text only as a draft and submit deliberately under a new ID; reattach any image.

Do not bypass these states by deleting browser storage or manufacturing a new client turn ID. That can turn an ambiguous commerce outcome into a duplicate shopper action.

## Browser recovery storage

The widget writes the exact pending identity to `sessionStorage` before transmission. If durable storage is unavailable or full, the request is not sent. Image bytes remain in page memory and are excluded from the stored record.

The widget permits one unresolved turn per conversation. Boot, send, recovery, export, and deletion are serialized. Boot responses are accepted only when credentials, history ordering, image metadata, and cart availability are internally consistent. A 2xx deletion response does not erase local credentials unless it has the exact success contract.

Browser timeout is shorter than the issued server lease by a fixed recovery margin. A timeout therefore means “recover this turn,” not “send another one.” The same rule applies to malformed JSON, proxy-generated error pages, and ordinary HTTP errors that do not contain an exact identity-bound disposition.

Missing-turn recovery is separately rate-limited because it acquires the conversation claim lock and may create a durable rejection tombstone. Repeated absence probes must not be used as a polling mechanism.

Every browser JSON response is read through an aggregate byte limit before parsing. A pre-claim chat authorization failure is not enough to discard a pending turn; retry first probes the exact turn through `turn/recover`. If that endpoint returns an identity-bound `unverified` result, the widget clears the dead capability and starts a clean conversation only after an explicit retry. Exact retained terminal results can still replay after inactivity, but that replay never extends activity or reopens ordinary conversation operations.

If a deletion acknowledgement is lost, the widget stops using the capability and boots the exact original credentials to reconcile whether deletion committed. While that probe is unavailable, the requested-to-delete transcript and cart are hidden and the old capability is retained only as future reconciliation input. Do not advise the shopper to continue chatting until reconciliation succeeds or a fresh capability is established.

The pending user bubble is keyed by `client_turn_id`. On reload it is restored from the durable pending record only when public server history does not already contain that user turn. Accepted failures remain pending until their assistant failure message has a durable ID. Operators should not advise shoppers to clear storage while any turn is unresolved.

## Widget appearance operations

Use **WooCommerce → AI Assistant → Widget appearance** to review the exact saved configuration through desktop, phone, and compact previews. Save once after changing a preset or custom color; the preset selector updates only values still inherited from the previous preset and preserves deliberate merchant overrides.

After deploying or changing appearance settings, clear page caches, persistent object cache where applicable, CDN caches, PHP opcode cache, and browser cache. The storefront PHP template, CSS, JavaScript module, and imported client utility must all carry the same release version. A mixed-cache deployment can produce missing controls or a broken optimistic-message lifecycle even when the server is healthy.

Verify on a real storefront page:

1. launcher position, shape, label, avatar, presence, and unread badge;
2. open/close focus behavior and full-screen phone safe areas;
3. welcome state, quick prompts, typing feedback, message grouping, timestamps, copy/reply actions, and latest-message navigation;
4. product carousel/grid/list behavior, unavailable prices, image ratio, descriptions, card controls, and cart strip;
5. minimize during an in-flight response, unread delivery, reopen, retry/recovery, export, and deletion;
6. 200% zoom, keyboard-only navigation, reduced motion, forced colors/high contrast, and mixed Arabic/Latin content.

If the administration preview differs from the storefront, first confirm caches and release versions before changing CSS. Do not bypass the normalized appearance policy with arbitrary raw CSS stored in options.

## Cleanup

`ysai_daily_cleanup` removes expired conversations and expired rate-limit buckets in bounded batches. Conversation cleanup is lifecycle-aware:

1. lock the conversation row;
2. refuse deletion while a fresh processing lease exists;
3. finalize or durably abandon stale work according to its checkpoint state;
4. change lifecycle away from `active`;
5. delete messages, turns, and the conversation transactionally.

If WP-Cron is disabled, configure a real cron runner for `wp-cron.php`. Monitor the age and count of expired rows; cleanup delay extends storage beyond the configured period until a successful run.

A failure to register the daily event no longer disables the complete plugin. The runtime keeps REST routes, administration, and the widget available, logs a bounded diagnostic, shows an administrator warning, and retries registration on later boots. Treat that warning as a degraded privacy-retention state and repair WP-Cron or option-table writes promptly; it is not evidence that cleanup actually ran.

Administration provides explicit cleanup and delete-all actions. Delete-all also fails closed if a fresh turn exists. Do not remove rows manually while the plugin is live.

## Monitoring

Track at least:

- provider credential, transport, protocol, and request-size failures;
- stable Gemini v1 live-contract failures and unexpected provider schema drift;
- production packaging gate failures, missing live acceptance evidence, or candidate archives presented as releases;
- rate-limit rejection counts by scope;
- `conversation_busy`, `turn_abandoned`, `previous_turn_abandoned`, `turn_finalization_pending`, and `turn_persistence_uncertain` rates;
- failed turn heartbeats or lease-loss events;
- cart lock acquisition failures and named-lock release diagnostics;
- trusted-proxy diagnostics, unexpected forwarding headers, and large groups of shoppers sharing one anonymous rate-limit bucket;
- direct durable cart-session read/write errors, database/cache mismatches, and unsupported session-adapter errors;
- counts and age of unresolved `started` operations, verified `rolled_back` operations, and replay-journal uncertainty;
- cleanup failures and oldest expired conversation age;
- schema verification or migration failures;
- unusual growth in conversation, message, turn, rate-limit, or WooCommerce session data.

Logs intentionally omit capability tokens, API keys, raw image bytes, request bodies, and merchant/customer content. Use turn IDs, error codes, and hashed operation identifiers for correlation.

## Incident handling

### Every chat message fails at the provider boundary

1. Run **Test Gemini connection** and record whether structured output or the production tool-contract phase failed.
2. Confirm the configured key can use the selected model and that the model name is exact.
3. Check bounded diagnostics for one of: credentials rejected, project access denied, geographic restriction, quota exhausted, model unavailable, request contract rejected, request too large, temporary unavailability, protocol error, or incomplete interaction. Raw provider messages are not logged.
4. Verify the installed plugin is 2.4.9 or later. Earlier candidates could encode zero-argument schemas incorrectly, forward non-portable constraints, permit direct prose despite a terminal-function-only application contract, or reject an explicit empty product-card list.
5. Run `bash scripts/verify-gemini-v1-fixtures.sh`; then run the credentialed `bash scripts/verify-gemini-v1.sh` in an acceptance environment. The local full chat regression verifies the portable production tool bundle, function-only selection, explicit empty card lists, strict local argument validation, durable protocol-failure presentation, and a two-turn stateless continuation.

### Provider outage

The provider adapter retries transient network failures, 408/409/425/429, and 5xx responses at most three times inside one configured deadline. A provider `Retry-After` is never shortened: the complete interval is honored only when it, a usable next transport attempt, and a finalization reserve fit the remaining deadline; otherwise no early retry is sent. Local backoff remains bounded and jittered. After exhaustion, the accepted turn is durably finalized as a safe failure with `retry_mode: new_turn`; a bounded `retry_after_seconds` is mirrored in the HTTP `Retry-After` header when available, and the widget reveals explicit fresh submission only after that delay. Persistence uncertainty remains `same_turn` and must be recovered under the original ID.

Disable the assistant or leave it enabled for bounded failures. Catalog and cart writes require provider completion; do not substitute unverified model output.

### Suspected key exposure

Revoke and rotate the Gemini key, update `YSAI_GEMINI_API_KEY` or the encrypted setting, run readiness, and review provider usage. Do not log the replacement key.

### Suspected capability exposure

Delete the affected conversation or allow it to expire. There is no token-recovery mechanism because only a hash is stored.

### Cart inconsistency

Stop chat-driven mutations, preserve the shopper's operation/turn identifiers, inspect the canonical WooCommerce session row and cart journal directly rather than relying on object cache, and reproduce on staging with the same extensions. Do not delete a started journal marker merely to make retry possible.

### Database or lock failure

Keep the assistant read-only or disabled. Verify InnoDB, schema permissions, charset/collation, named-lock support, direct read access to the WooCommerce session table, the active session-handler class, and the persistence adapter. Never relax direct durability, freshness, lifecycle, lease, or receipt checks to restore availability.

### Stuck or abandoned turns

Recover the exact turn first. A fresh lease must not be manually expired. For a durable `turn_abandoned`, inspect the cart and journal before a new request. If rows are corrupt or lifecycle is stranded, take a database backup and repair through a reviewed maintenance procedure rather than direct ad hoc deletion.

## Capacity

The runtime enforces bounded messages, images, provider bodies, model steps, function calls, product references, cart lines, cart journals, persisted JSON, export size, and history source bytes. These are safety limits, not capacity targets.

Large catalogs use WooCommerce-native product-data-store search and paginated product queries. Each discovery operation scans at most 240 candidates in pages of 24 and reports whether the source was exhausted or results were truncated. Test the production pricing, stock, visibility, multilingual, membership, and currency extensions: live product objects are authoritative, while unavailable numeric prices remain `null` rather than becoming zero.

A conversation history read returns at most 80 newest records and at most an 8 MiB stable source suffix; exports remain separately capped at 5000 messages and 4 MiB source content.
