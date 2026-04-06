/**
 * Candles Store — Light a candle for a memorial.
 *
 * Manages per-user candle state and optimistic toggle UI.
 * Uses a separate namespace from the memorials store so it
 * doesn't interfere with the wall's navigation logic.
 *
 * @package Starter_Shelter
 * @since 2.1.0
 */

import { store, getContext } from '@wordpress/interactivity';

const NAMESPACE = 'starter-shelter/candles';

const { state } = store( NAMESPACE, {
	state: {
		/** @type {number[]} Memorial IDs the current user has lit. */
		candles: [],

		/** Derived: is the current memorial lit? */
		get isLit() {
			const ctx = getContext();
			const id = ctx.memorialId || ctx.item?.id;
			return id ? state.candles.includes( id ) : false;
		},

		/** Derived: candle count for the current memorial. */
		get candleCount() {
			const ctx = getContext();
			return ctx.candleCount ?? 0;
		},

		/** Derived: display label. */
		get candleLabel() {
			const count = state.candleCount;
			if ( count === 0 ) return 'Light a candle';
			if ( count === 1 ) return '1 candle';
			return count + ' candles';
		},

		/** Derived: aria label for the toggle button. */
		get candleAriaLabel() {
			const ctx = getContext();
			const name = ctx.item?.honoree_name || ctx.honoreeName || '';
			return state.isLit
				? 'Remove candle for ' + name
				: 'Light a candle for ' + name;
		},
	},

	actions: {
		/**
		 * Toggle candle on/off for a memorial.
		 * Optimistic update: UI changes immediately, server confirms.
		 */
		*toggleCandle( event ) {
			// Prevent the click from navigating the memorial link.
			event.preventDefault();
			event.stopPropagation();

			const ctx = getContext();
			const memorialId = ctx.memorialId || ctx.item?.id;
			if ( ! memorialId ) return;

			const wasLit = state.candles.includes( memorialId );

			// Optimistic update.
			if ( wasLit ) {
				state.candles = state.candles.filter( function( id ) { return id !== memorialId; } );
				ctx.candleCount = Math.max( 0, ( ctx.candleCount || 0 ) - 1 );
			} else {
				state.candles = [ ...state.candles, memorialId ];
				ctx.candleCount = ( ctx.candleCount || 0 ) + 1;
			}

			// Server call.
			try {
				const response = yield fetch( ctx.candleApiUrl || '/wp-json/starter-shelter/v1/candles/toggle', {
					method: 'POST',
					headers: { 'Content-Type': 'application/json' },
					body: JSON.stringify( { memorial_id: memorialId } ),
					credentials: 'same-origin',
				} );

				const result = yield response.json();

				// Confirm from server.
				if ( result.count !== undefined ) {
					ctx.candleCount = result.count;
				}
				if ( result.candles ) {
					state.candles = result.candles;
				}
			} catch ( error ) {
				// Rollback on failure.
				if ( wasLit ) {
					state.candles = [ ...state.candles, memorialId ];
					ctx.candleCount = ( ctx.candleCount || 0 ) + 1;
				} else {
					state.candles = state.candles.filter( function( id ) { return id !== memorialId; } );
					ctx.candleCount = Math.max( 0, ( ctx.candleCount || 0 ) - 1 );
				}
			}
		},
	},
} );
