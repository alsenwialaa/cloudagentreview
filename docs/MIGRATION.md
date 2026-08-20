# Migration and rollout

## Principle

The 2.x codebase is a replacement implementation, not a technical migration of the old one. It preserves required product behavior while starting with a fresh architecture, schema, API namespace, browser storage contract, and commerce authorization model.

## Data behavior

- New tables use `ysai_v2_` names.
- Old conversation, message, analytics, job, cache, or provider tables are not inspected.
- Old conversations and assistant memory are not imported.
- Old tables are not dropped during activation, administration, uninstall, or packaging.
- New widget credentials use `ysai.v2.*` browser keys and do not reuse older capabilities.
- Existing `ysai_options` values are read only for known current-release keys. Unknown keys are ignored.
- A plaintext `gemini_api_key` in that option is encrypted during startup when possible. If secure encryption is unavailable or migration fails, runtime use is refused until the key is re-saved securely or provided as `YSAI_GEMINI_API_KEY`.

## Upgrade to 2.5.4
The direct-update Phase 1 shopping completion changes no database table and requires no legacy migration. Existing 2.5.4 installations should replace the plugin files as one atomic deployment, clear any full-page/CDN cache so the content-hashed native cart module is served, re-save merchant synonym settings when used, and run the complete local and staging qualification gates. Continuation and clarification context is self-validating; malformed or older payloads fail closed.


Release 2.5.4 adds no plugin tables or columns. `ysai_schema_version` advances to 2.5.4 so activation and startup reverify the complete existing persistence contract before enabling the corrected operational boundary.

The REST namespace, browser/server error contract, conversation capability, idempotent turn identity, recovery protocol, cart authorization, stored message payloads, browser storage keys, and appearance options remain compatible with 2.5.3. Cleanup scheduling is no longer attempted by ordinary storefront requests: activation, privileged non-AJAX administration, WP-Cron, and WP-CLI are the only automatic remediation contexts. When the option-backed logging throttle cannot be durably written, the plugin keeps the administration warning but suppresses the diagnostic entry rather than risking a request-by-request log storm. Gemini errors now scan a bounded set of top-level and nested structured reasons and prefer the first specific canonical reason over earlier unknown or generic status-like details.

Deploy the complete 2.5.4 directory. Do not mix its PHP, schema marker, Gemini client, installer, tests, static audits, or metadata with 2.5.3 files. Clear object, opcode, CDN, service-worker, and browser caches before traffic reaches the updated server. Then test a missing cleanup event on a storefront request, a failed cleanup-option write in a privileged administration request, schedule recovery through WP-Cron or administration, a provider error with an unknown first detail and a later canonical reason, normal chat, cart rollback replay, export, deletion, and the full storefront/admin browser matrix before production approval.

## Upgrade to 2.5.3

Release 2.5.3 adds no plugin tables or columns. `ysai_schema_version` advances to 2.5.3 so activation and startup reverify the complete existing persistence contract before enabling the corrected runtime boundary.

The REST namespace, conversation capability, idempotent turn identity, recovery protocol, cart authorization, stored message payloads, browser storage keys, and appearance options remain compatible with 2.5.2. Error responses gain optional `retry_mode` and `retry_after_seconds` fields only for browsers that send `X-YSAI-Client-Contract: 2`; a stale 2.5.2 browser receives the legacy exact error object. Provider retries now honor the full `Retry-After` interval only when it fits the shared deadline, canonical provider codes outrank incidental message text, and failed cleanup scheduling is retried and logged on a bounded backoff instead of every request.

Deploy the complete 2.5.3 directory. Do not mix its PHP, template, JavaScript module, client utilities, stylesheet, storefront configuration, or tests with 2.5.2 files. Clear page, object, CDN, opcode, service-worker, and browser caches before traffic reaches the updated server. Then test one delayed rate-limit response, one transient provider failure, exact same-turn recovery, deliberate fresh-turn resend after a finalized failure, cleanup scheduling degradation/recovery, export, deletion, and the full storefront/admin browser matrix before production approval.

## Upgrade to 2.5.2

Release 2.5.2 adds no plugin tables or columns. `ysai_schema_version` advances to 2.5.2 so activation and startup reverify the complete existing persistence contract before the updated widget lifecycle is enabled.

The REST namespace, conversation capability, idempotent turn identity, recovery protocol, cart authorization, stored message payloads, browser storage keys, and appearance options remain compatible with 2.5.1. The browser changes fence removed widget instances, expose minimized unread delivery to assistive technology, name product actions uniquely, use Unicode-safe presentation truncation, and follow the actual mobile visual viewport.

Deploy the complete 2.5.2 directory. Do not mix its template, JavaScript module, client utilities, stylesheet, storefront configuration, browser harness, or runtime tests with 2.5.1 files. Clear page, object, CDN, opcode, and browser caches, then test dynamic widget removal/reinsertion, minimized unread delivery, multiple product cards, 200% zoom, mobile keyboard geometry, exact turn recovery, export, deletion, and the full desktop/phone appearance matrix before production approval.

## Upgrade to 2.5.1

Release 2.5.1 adds no plugin tables or columns. `ysai_schema_version` advances to 2.5.1 so activation and startup reverify the complete existing persistence contract before the completed widget lifecycle is enabled.

The REST namespace, conversation capability, idempotent turn identity, recovery protocol, cart authorization, message payloads, and appearance option keys remain compatible with 2.5.0. The browser module changes modality, focus, composer, attachment, carousel, media-fallback, and teardown behavior only; it does not transform conversations, carts, images, or legacy tables.

Deploy the complete 2.5.1 directory. Do not mix its template, JavaScript module, client utilities, stylesheet, storefront configuration, browser harness, or runtime tests with 2.5.0 files. Clear page, object, CDN, opcode, and browser caches, then test desktop, phone, short-landscape, online/offline, image-only messages, invalid images, minimized unread delivery, product navigation, cart summary, exact recovery, export, deletion, keyboard focus, reduced motion, and forced colors before production approval.

## Upgrade to 2.5.0

Release 2.5.0 adds no plugin tables or columns. `ysai_schema_version` advances to 2.5.0 so activation and startup reverify the complete existing persistence contract before the redesigned widget and expanded appearance settings are enabled.

The browser storage key, REST namespace, conversation capability, turn identity, recovery protocol, cart authorization, and stored message formats remain compatible with 2.4.9. The release adds normalized appearance options under the existing `ysai_options` record. Missing values receive secure accessible defaults; unknown option keys remain ignored. No previous conversation, cart, image, or legacy-table data is transformed.

Deploy the complete 2.5.0 directory. Do not mix its template, widget module, client utilities, stylesheet, administration page, settings policy, or browser tests with 2.4.x files. Clear WordPress page/object caches, CDN caches, PHP opcode cache, and browser caches so the module, template, and stylesheet have the same release version. Review the live desktop, phone, and compact previews, save appearance settings, then test opening, closing, typing, quick replies, product cards, unread delivery while minimized, exact retry/recovery, export, and deletion before production approval.

## Upgrade to 2.4.9

Release 2.4.9 adds no plugin tables or columns. `ysai_schema_version` advances to 2.4.9 so activation and startup reverify the complete existing persistence contract before the corrected provider boundary is enabled.

Production chat now requires a Gemini function call whenever the tool bundle is present. The wire schema is a portable projection of the plugin's stricter local schema; constraints omitted from the provider request remain enforced locally before any tool or structured result is trusted. Terminal answers may explicitly send an empty product-reference list to render no cards, and a null provider convenience `output_text` is reconstructed from valid REST model-output steps.

Deploy the complete 2.4.9 directory. Do not mix its Gemini client, schema projector, tool registry, chat service, tests, release scripts, or documentation with 2.4.8 files. Re-save provider settings, run both readiness phases, clear PHP/opcode/object/CDN/browser caches, and exercise a greeting, policy response, product search, cart view, terminal answer with no cards, second turn, and exact retry/recovery before production approval. No conversation, cart, or legacy-table data is transformed.

## Upgrade to 2.4.5

Release 2.4.5 adds no plugin tables or columns. `ysai_schema_version` advances to 2.4.5 so activation and startup reverify the complete existing persistence contract before the updated chat flow is enabled.

The browser/server turn protocol is stricter. Pending requests are cleared only by exact durable terminal responses or exact identity-bound pre-acceptance dispositions; arbitrary HTTP errors now recover the same turn. Recovery of an absent ID creates a durable pre-acceptance tombstone under the claim lock so a delayed request cannot execute after non-acceptance was reported. Accepted user messages and activity extension are atomically fenced by the live turn generation, shopping-memory writes use the same ownership check, and accepted failures remain pending until their assistant message is durable.

Public history/export now excludes internal roles at the repository and application boundaries. User history can include the originating `client_turn_id` for reload deduplication. Historical assistant commerce payloads, terminal replays, carts, receipts, export messages, and shopping memory are canonicalized through strict allowlists. Custom frontends must validate the documented exact envelopes and must not infer “not sent” from HTTP status alone.

Deploy the complete 2.4.5 directory. Do not mix its REST controller, chat service, repositories, widget/client utilities, tests, documentation, or metadata with 2.4.4 files. No conversation, cart, or legacy-table data transformation is performed.

## Upgrade to 2.4.4

Release 2.4.4 adds no plugin tables or columns. `ysai_schema_version` advances to 2.4.4 so activation and startup reverify the complete existing schema before the runtime is enabled.

The application-to-model boundary now maps uncategorized invalid-argument exceptions to one fixed bounded remediation message. Internal domain, repository, database, gateway, and extension details no longer enter Gemini tool history where the model could repeat them to a shopper.

Production-labelled packaging is now fail-closed. The default `release` profile requires the exact source tree to pass the representative WooCommerce/database/object-cache suite, credentialed Gemini stable-v1 contracts, and real Chromium gate. Environments unable to execute those gates may create only an explicit `candidate` archive, which is not production-approved.

Deploy the complete 2.4.4 directory. Do not mix its tool registry, tests, release scripts, documentation, or metadata with 2.4.3 files. No conversation or cart data transformation is performed.

## Upgrade to 2.4.3

Release 2.4.3 adds no plugin tables or columns. `ysai_schema_version` advances to 2.4.3 so activation and startup reverify the complete existing schema before the new runtime is enabled.

The runtime changes the catalog and provider boundaries: product retrieval now uses bounded WooCommerce-native search/pagination with live object filtering; unavailable prices are nullable rather than coerced to zero; the public health route exposes liveness only; uncategorized internal validation messages are no longer public; and Gemini requests use stable `v1/interactions` without a beta migration header.

Deploy the complete 2.4.3 directory. Do not mix its catalog contract, product-card fields, REST controller, Gemini client, tests, or release scripts with 2.4.2 files. Themes or custom consumers that inspect product cards must accept `price: null` when `price_available` is false.

Before production, run the local verifier, the representative WooCommerce/database/object-cache harness, the stable Gemini v1 live contract, and the browser/accessibility acceptance matrix. A skipped environment-dependent gate is not a pass.

## Upgrade to 2.4.2

Release 2.4.2 does not add or remove plugin tables or columns. `ysai_schema_version` advances to 2.4.2 so activation and startup reverify the full existing schema before the new runtime is enabled.

The cart-session persistence contract now separates writing from proof. `CartSessionPersistence::persist()` returns no session state. The gateway invalidates any potentially divergent cache entry and calls `read()` as a distinct canonical-store operation before it accepts a mutation, rollback, operation-start journal, or completed replay receipt as durable. Existing custom adapters must be updated to the new interface; a handler without a compatible reviewed adapter remains read-only for assistant cart changes.

Release 2.4.2 also adds trusted-proxy settings. Direct deployments should leave `trusted_proxy_cidrs` empty. Proxy/CDN deployments must enter only the exact immediate proxy CIDRs and select the single forwarding header the edge overwrites. The optional `YSAI_TRUSTED_PROXY_CIDRS` and `YSAI_TRUSTED_PROXY_HEADER` constants take precedence. Trust-all `/0` networks are rejected. Forwarded identity affects anonymous rate limiting only; cart locks require a logged-in user or validated WooCommerce customer session.

No conversation or cart data is transformed. Before production, run the new WP-CLI fault suite on a disposable representative staging environment, including the real database and object-cache topology:

```bash
YSAI_REQUIRE_WOOCOMMERCE_INTEGRATION=1 \
YSAI_WP_PATH=/path/to/wordpress \
bash scripts/verify.sh
```

Deploy the complete directory. Do not mix a 2.4.1 persistence adapter, cart gateway, settings class, or plugin wiring with 2.4.2 files.

## Upgrade to 2.4.1

Release 2.4.1 replaces the previous WooCommerce cart-session verification boundary. It does not add or remove plugin tables or columns. `ysai_schema_version` advances to 2.4.1 so activation/startup reruns the complete existing schema verification before the new commerce boundary is enabled.

The bundled adapter now supports only the exact built-in `WC_Session_Handler` backed by the canonical `<prefix>woocommerce_sessions` table. It preserves WooCommerce's full session write, then reads the matching database row directly, bypasses object cache, decodes the nested serialized cart/coupon/journal values, and verifies commits and rollbacks by signature. A custom session handler is read-only for assistant cart changes until the store supplies a reviewed `CartSessionPersistence` adapter through `ysai_cart_session_persistence`.

No conversation or cart data is transformed during this upgrade. Existing completed and started cart journal entries remain in the WooCommerce customer session. Before production, staging must exercise a normal mutation, a separate-request read, database-write failure with object cache enabled, lost write response, failed rollback write, replay, and the store's exact session-handler configuration.

Deploy the complete directory. Do not retain a 2.4.0 `WooCartGateway`, administration class, or widget asset beside 2.4.1 persistence classes.

## Upgrade to 2.4.0

Release 2.4.0 advances `ysai_schema_version` and adds two persistence fields to the existing `ysai_v2_` schema:

- `ysai_v2_conversations.lifecycle_state`, defaulting to `active`;
- `ysai_v2_turns.lease_seconds`, defaulting to `1200` for rows created by older releases.

The lifecycle field serializes new-turn claims, message writes, deletion, and retention cleanup around the same active conversation record. The lease field preserves the duration issued when a turn was claimed, so later recovery and stale detection do not change merely because an administrator edits timeout settings.

Activation or startup runs `dbDelta`, then verifies the complete table contract before publishing schema version 2.4.0. Verification includes engine, character set, table and column collation, column type and signedness, nullability, defaults, auto-increment behavior, and every required full-width visible BTREE index in exact column order. A partial or drifted migration disables the assistant instead of running against an unknown schema.

The upgrade does not rename tables and does not import legacy data. Existing terminal or checkpointed turn rows remain replayable. Existing processing rows receive the conservative 1200-second lease default; staging should exercise stale-turn recovery before production rollout.

Browser pending-turn records remain defensive. Stored records are reduced to the bounded current shape, unknown fields are removed, and image bytes are deleted. Deploy the complete plugin directory so `widget.js` and `client-utils.js` share version 2.4.0.

## Behavioral changes that affect operations

- Only one processing turn is permitted per conversation at the database boundary.
- A completed, failed, or checkpointed turn with the same `client_turn_id` remains replayable even while another turn is active.
- One exact retained turn also remains replayable after the ordinary inactivity window without extending activity; ordinary chat, history, export, and deletion remain closed.
- A stale checkpointed blocker is finalized without repeating provider or cart work.
- A stale uncheckpointed blocker is durably marked `turn_abandoned`. The new request is not executed; the shopper must inspect the cart and explicitly resend under a new turn.
- Browser timeout and server lease values now come from one timing policy. The browser stops before lease expiry to leave a recovery margin.
- Generic chat authorization failures remain ambiguous until exact recovery; replacement conversation capabilities clear old reply/cart authority, and lost deletion acknowledgements must be reconciled before later chat work.
- Conversation deletion returns `conversation_busy` while a fresh processing lease exists.
- New-conversation creation is subject to a configurable global daily limit.

## Recommended rollout

1. Create a full database and file backup.
2. Clone production to staging.
3. Inventory any legacy tables and keep their names in the change record. Do not delete them during the first rollout.
4. Deactivate the previous assistant plugin.
5. Replace its complete plugin directory or install the live-gate-approved 2.5.4 ZIP. Do not mix release files.
6. Activate 2.5.4 and confirm the existing table contract is reverified, `ysai_schema_version` is 2.5.4, and cart-session readiness reports the built-in database handler or a reviewed custom adapter.
7. Re-enter and test the Gemini key when needed.
8. Configure the widget, policy links, retention, session timeout, turn limits, daily conversation limit, daily AI-turn budget, and trusted-proxy settings where applicable.
9. Run the staging acceptance matrix in `TESTING.md`, including every cart-affecting extension, interrupted-turn case, the opt-in real WooCommerce fault suite, direct durable-session reads, database/cache disagreement, rollback faults, and proxy spoof-resistance checks.
10. Deploy the same verified ZIP to production during a monitored change window.
11. Confirm health, widget boot, read-only chat, one low-risk cart change, interrupted-turn recovery, deletion-busy behavior, checkout handoff, scheduled cleanup, and logs.
12. Keep legacy tables during a defined observation period. Archive or remove them later through a separate reviewed database change—not through this plugin.

## Direct file updates

No version-control metadata or workflow is required by this package. When updating files directly:

- replace the complete plugin directory rather than mixing old and new source files;
- preserve a filesystem backup outside the active plugin directory;
- verify the archive SHA-256 before extraction;
- never edit generated ZIP contents in place;
- run `scripts/verify.sh` against the exact directory during development;
- require the default live `release` packaging profile for a production-labelled ZIP, or explicitly label a local-only build as `candidate`;
- extract and verify the produced ZIP again before deployment;
- repeat staging acceptance after any direct code change.

## Rollback

A code rollback means restoring the previous complete plugin directory and its compatible database backup or schema state. Because the new release does not modify legacy tables, those tables remain available. New `ysai_v2_` conversations created after rollout are not understood by the old implementation.

Do not roll back by copying individual old classes into the new tree. If database restoration is unavailable, leave the isolated `ysai_v2_` tables in place and archive them separately. A previous plugin release may not understand 2.4.0 lifecycle or lease fields; a 2.4.0 runtime does not provide the 2.4.1 durable cart-session boundary; and a 2.4.1 custom persistence adapter does not satisfy the 2.4.2 separated write/read interface. Use a tested database backup and complete plugin-directory restore for a supported rollback.
