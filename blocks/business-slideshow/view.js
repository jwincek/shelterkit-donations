/**
 * Business Member Slideshow — Interactivity view module.
 *
 * All slides are server-rendered into a flex track; this store only moves
 * an index and toggles play state. The track is shifted with a CSS
 * transform, off-screen slides are `inert`, and autoplay is driven by a
 * single interval managed in a watch (keyed per instance so multiple
 * blocks on a page don't clobber each other).
 *
 * @package Starter_Shelter
 * @since   2.2.0
 */

import { store, getContext, getElement } from '@wordpress/interactivity';

// Per-instance autoplay timers, keyed by the block's root element.
const timers = new WeakMap();

const prefersReducedMotion = () =>
    typeof window !== 'undefined' &&
    window.matchMedia &&
    window.matchMedia( '(prefers-reduced-motion: reduce)' ).matches;

const { state } = store( 'shelter-donations/business-slideshow', {
    state: {
        /** Track offset for the current index (value for style.transform). */
        get trackTransform() {
            const ctx = getContext();
            return `translateX(-${ ( ctx.index ?? 0 ) * 100 }%)`;
        },

        get isPlaying() {
            return !! getContext().isPlaying;
        },

        get playPauseLabel() {
            return getContext().isPlaying ? 'Pause' : 'Play';
        },

        get playPauseGlyph() {
            // ❚❚ when playing (click to pause), ▶ when paused (click to play).
            return getContext().isPlaying ? '❚❚' : '▶';
        },

        /** True for every slide that isn't the current one (drives `inert`). */
        get isInactiveSlide() {
            const ctx = getContext();
            return ctx.slide !== ctx.index;
        },

        /** True for the dot matching the current index. */
        get isActiveDot() {
            const ctx = getContext();
            return ctx.dot === ctx.index;
        },
    },

    actions: {
        next() {
            const ctx = getContext();
            if ( ( ctx.count ?? 0 ) < 1 ) return;
            ctx.index = ( ( ctx.index ?? 0 ) + 1 ) % ctx.count;
        },

        prev() {
            const ctx = getContext();
            if ( ( ctx.count ?? 0 ) < 1 ) return;
            ctx.index = ( ( ctx.index ?? 0 ) - 1 + ctx.count ) % ctx.count;
        },

        goToDot() {
            const ctx = getContext();
            ctx.index = ctx.dot ?? 0;
        },

        /** Explicit play/pause — remembered so hover/focus won't override it. */
        togglePlay() {
            const ctx = getContext();
            ctx.userPaused = ctx.isPlaying;
            ctx.isPlaying  = ! ctx.isPlaying;
        },

        /** Transient pause on hover/focus (does not change userPaused). */
        pause() {
            getContext().isPlaying = false;
        },

        /** Resume after hover/focus, unless the user explicitly paused. */
        resume() {
            const ctx = getContext();
            if ( ctx.config?.autoplay && ! ctx.userPaused ) {
                ctx.isPlaying = true;
            }
        },
    },

    callbacks: {
        /**
         * Start/stop the autoplay interval. Re-runs when isPlaying (or the
         * config) changes — not on every index tick, since the index is read
         * only inside the interval callback, not synchronously here.
         */
        autoplay() {
            const ctx     = getContext();
            const { ref } = getElement();

            const existing = timers.get( ref );
            if ( existing ) {
                clearInterval( existing );
                timers.delete( ref );
            }

            if ( ctx.isPlaying && ( ctx.count ?? 0 ) > 1 && ! prefersReducedMotion() ) {
                const id = setInterval( () => {
                    ctx.index = ( ( ctx.index ?? 0 ) + 1 ) % ctx.count;
                }, ctx.config?.intervalMs || 5000 );
                timers.set( ref, id );
            }
        },
    },
} );

export { state };
