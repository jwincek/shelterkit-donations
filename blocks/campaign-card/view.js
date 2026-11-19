/**
 * Campaign Card Block - Interactivity View Module
 *
 * Each card keeps its figures in its own context (`context.campaign`,
 * `context.progressWidth`, `context.progressText`). They used to be one
 * shared `state.campaign`, so with several cards on a page every card showed
 * the last one's progress, total and donors once the page loaded (#64).
 *
 * @package Starter_Shelter
 * @since 1.0.0
 */

import { store, getContext, getElement } from '@wordpress/interactivity';

const percent = ( value ) => `${ value || 0 }%`;

/**
 * Campaign Card Store
 */
const { actions } = store( 'shelter-donations/campaign-card', {
    actions: {
        /**
         * Handle donate button click.
         */
        handleDonate() {
            const { campaign } = getContext();

            // Track the click if analytics is available.
            if ( window.gtag ) {
                window.gtag( 'event', 'donate_click', {
                    campaign_id: campaign?.id,
                    campaign_name: campaign?.name,
                } );
            }

            // Let the default link behavior proceed.
        },

        /**
         * Refresh this card's figures from the server.
         */
        *refreshCampaign() {
            const context = getContext();

            if ( ! context.campaignId ) return;

            try {
                const response = yield fetch(
                    `/wp-json/shelter-donations/v1/campaign/${ context.campaignId }`
                );

                if ( ! response.ok ) {
                    throw new Error( 'Failed to fetch campaign' );
                }

                const data = yield response.json();

                context.campaign = {
                    ...context.campaign,
                    raised_formatted: data.raised_formatted || '$0',
                    progress: data.progress || 0,
                    donor_count: data.donor_count || 0,
                };
                context.progressWidth = percent( context.campaign.progress );
                context.progressText = percent( context.campaign.progress );
            } catch ( error ) {
                // eslint-disable-next-line no-console
                console.error( 'Failed to refresh campaign:', error );
            }
        },
    },

    callbacks: {
        /**
         * Fill this card's progress bar once it scrolls into view.
         *
         * The animation runs from requestAnimationFrame, outside the card's
         * scope, so it keeps the card's context rather than calling
         * getContext() each frame.
         */
        init() {
            const { ref } = getElement();
            const context = getContext();

            const animate = () => {
                if ( context.isAnimated ) return;

                const target = context.campaign?.progress || 0;
                let current = 0;
                const step = () => {
                    current = Math.min( current + 1, target );
                    context.progressWidth = percent( current );
                    context.progressText = percent( current );
                    if ( current < target ) {
                        requestAnimationFrame( step );
                    } else {
                        context.progressWidth = percent( target );
                        context.progressText = percent( target );
                        context.isAnimated = true;
                    }
                };
                requestAnimationFrame( step );
            };

            const observer = new IntersectionObserver(
                ( entries ) => {
                    if ( entries.some( ( entry ) => entry.isIntersecting ) ) {
                        animate();
                        observer.disconnect();
                    }
                },
                { threshold: 0.3 }
            );

            observer.observe( ref );

            return () => observer.disconnect();
        },
    },
} );

export { actions };
