/**
 * Campaigns Store
 *
 * Campaign Progress renders every figure on the server. With a refresh
 * interval set, this re-reads the campaign on that interval and updates the
 * figures in place. Each block keeps its campaign in its own context, so
 * several blocks on one page never share figures.
 *
 * @package Starter_Shelter
 * @since 2.0.0
 */

import { store, getContext, withScope } from '@wordpress/interactivity';
import { apiRequest } from './utils.js';

const numberFormat = new Intl.NumberFormat( document.documentElement.lang || undefined );

const { actions } = store( 'shelter-donations/campaign', {
    actions: {
        /**
         * Re-read this block's campaign. A failed request leaves the figures
         * already shown.
         */
        *refresh() {
            const ctx = getContext();
            if ( ! ctx.campaignId ) {
                return;
            }

            try {
                // The route is campaign/{id}; it was requested as campaigns/{id} (#42).
                const campaign = yield apiRequest( `campaign/${ ctx.campaignId }` );
                const progress = Number( campaign.progress ) || 0;
                ctx.campaign = {
                    raisedFormatted: campaign.raised_formatted,
                    progress: Math.round( progress ),
                    progressLabel: `${ Math.round( progress ) }%`,
                    progressWidth: `${ progress }%`,
                    donorCount: numberFormat.format( Number( campaign.donor_count ) || 0 ),
                };
            } catch ( error ) {
                // Keep what's on screen; the next tick tries again.
            }
        },
    },

    callbacks: {
        /**
         * Start refreshing (data-wp-init, only when an interval is set).
         *
         * @return {Function|undefined} Cleanup that stops the timer.
         */
        startAutoRefresh() {
            const seconds = Number( getContext().refreshInterval ) || 0;
            if ( seconds <= 0 ) {
                return undefined;
            }
            // withScope: a timer runs outside the block's scope, where
            // getContext() throws (the bug #41 fixed in Donor Statistics).
            const timer = setInterval( withScope( () => actions.refresh() ), seconds * 1000 );
            return () => clearInterval( timer );
        },
    },
} );

export { actions };
