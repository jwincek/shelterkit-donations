/**
 * Donor Stats Block - Interactivity View Module
 *
 * Provides animated number counting and optional auto-refresh.
 *
 * Each block keeps its figures in its own context (`context.stats`,
 * `context.formatted`, `context.isAnimated`, `context.isAnimating`), and its
 * own animation frame and refresh timer. They used to be shared state and
 * module variables, so with two Donor Stats blocks on a page both showed the
 * last one's figures, and the second block's timer replaced the first's (#64).
 *
 * @package Starter_Shelter
 * @since 1.0.0
 */

import { store, getContext, getElement, withScope } from '@wordpress/interactivity';
// The shared formatter, so the counter matches the server's first render and
// follows the store's currency (#28). Relative, like the other stores' imports.
import { formatCurrency, getSharedConfig } from '../../assets/js/stores/utils.js';

const KEYS = [ 'total', 'count', 'donors', 'members' ];

/**
 * Format a number with thousands separators.
 *
 * @param {number} value The number.
 * @return {string} Formatted.
 */
function formatNumber( value ) {
    return value.toLocaleString( 'en-US' );
}

/**
 * Write the figures, as they would read at `fraction` of the way up, into a
 * block's context.
 *
 * @param {Object} context  The block's context.
 * @param {number} fraction 0 to 1.
 */
function show( context, fraction ) {
    for ( const key of KEYS ) {
        const value = Math.round( ( context.stats[ key ] || 0 ) * fraction );
        context.formatted[ key ] = 'total' === key ? formatCurrency( value ) : formatNumber( value );
    }
}

/**
 * Donor Stats Store
 */
const { actions } = store( 'shelter-donations/donor-stats', {
    actions: {
        /**
         * Count this block's figures up from 0.
         */
        animateNumbers() {
            const context = getContext();
            if ( context.isAnimated ) return;

            context.isAnimating = true;

            const duration = 2000; // 2 seconds
            const startTime = performance.now();

            // From requestAnimationFrame, outside the block's scope: keep the
            // context rather than calling getContext() each frame.
            const animate = ( currentTime ) => {
                const progress = Math.min( ( currentTime - startTime ) / duration, 1 );
                show( context, 1 - Math.pow( 1 - progress, 3 ) ); // Ease out.

                if ( progress < 1 ) {
                    context.frame = requestAnimationFrame( animate );
                } else {
                    show( context, 1 );
                    context.isAnimated = true;
                    context.isAnimating = false;
                }
            };

            context.frame = requestAnimationFrame( animate );
        },

        /**
         * Refresh stats from the server, for the block's own period.
         *
         * The endpoint returns total_donations and donor_count. A figure it
         * doesn't send keeps its current value instead of dropping to 0.
         */
        *refreshStats() {
            const context = getContext();

            try {
                const url = new URL( `${ getSharedConfig().restUrl || '/wp-json/shelter-donations/v1/' }stats`, window.location.href );
                url.searchParams.set( 'period', context.period || 'all_time' );

                const response = yield fetch( url, { headers: { Accept: 'application/json' } } );

                if ( ! response.ok ) {
                    throw new Error( 'Failed to fetch stats' );
                }

                const data = yield response.json();
                const fields = { total: 'total_donations', count: 'donation_count', donors: 'donor_count', members: 'active_members' };
                for ( const [ key, field ] of Object.entries( fields ) ) {
                    if ( typeof data[ field ] === 'number' ) {
                        context.stats[ key ] = data[ field ];
                    }
                }

                // Update formatted values without animation.
                show( context, 1 );
            } catch ( error ) {
                // eslint-disable-next-line no-console
                console.error( 'Failed to refresh stats:', error );
            }
        },
    },

    callbacks: {
        /**
         * Initialize the block.
         */
        init() {
            const context = getContext();
            let refreshTimer = null;

            // Set up auto-refresh if configured.
            // withScope: a timer runs outside the block's scope, where
            // getContext() throws, and auto-refresh never ran.
            if ( context.refreshInterval > 0 ) {
                refreshTimer = setInterval(
                    withScope( () => actions.refreshStats() ),
                    context.refreshInterval * 1000
                );
            }

            // Clean up on unmount (if the element is removed).
            return () => {
                if ( context.frame ) {
                    cancelAnimationFrame( context.frame );
                }
                if ( refreshTimer ) {
                    clearInterval( refreshTimer );
                }
            };
        },

        /**
         * Watch for visibility changes to trigger animation.
         */
        watchViewport() {
            const context = getContext();

            if ( ! context.animateNumbers || context.isAnimated ) {
                return;
            }

            const { ref } = getElement();
            const start = withScope( () => actions.animateNumbers() );

            // Use IntersectionObserver to trigger animation when visible.
            const observer = new IntersectionObserver(
                ( entries ) => {
                    if ( entries.some( ( entry ) => entry.isIntersecting ) && ! context.isAnimated ) {
                        start();
                        observer.disconnect();
                    }
                },
                { threshold: 0.2 }
            );

            observer.observe( ref );

            // Cleanup function.
            return () => observer.disconnect();
        },
    },
} );

export { actions };
