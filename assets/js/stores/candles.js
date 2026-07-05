/**
 * Candles namespace placeholder.
 *
 * The candle interactivity (getters and toggle action) lives inside the
 * memorials store now (see memorials.js) so it can read `context.item`
 * from the same namespace `data-wp-each` binds into. Splitting the
 * candle code into its own namespace caused two problems:
 *
 *   1. `data-wp-interactive="shelter-donations/candles"` on the inner div
 *      created a fresh context scope that did not inherit the per-item
 *      proxy from the parent's `data-wp-each`, so `ctx.item` was always
 *      undefined.
 *   2. Registering candle code under the memorials namespace from a
 *      separate file resulted in non-deterministic merge ordering with
 *      memorials.js, intermittently overwriting state/getters.
 *
 * The `shelter-donations/candles` namespace is still used to hold the
 * current user's lit-list. It is seeded server-side via
 * wp_interactivity_state() in register-stores.php and the runtime
 * hydrates it without needing a JS `store()` call here. The memorials
 * store reads/writes that state via `store('shelter-donations/candles')`.
 *
 * This file is intentionally a no-op and is no longer enqueued by the
 * memorial-wall block. It is kept so the registered script module
 * `shelter-donations/candles` continues to resolve if anything else
 * references it.
 *
 * @package Starter_Shelter
 * @since 2.1.0
 */
