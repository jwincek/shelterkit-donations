/**
 * ShelterKit Donations - Shared Utilities
 *
 * Common functions used across all stores.
 * Uses ES modules for WordPress 6.9 Script Modules.
 *
 * @package Starter_Shelter
 * @since 2.0.0
 */

import { getConfig } from '@wordpress/interactivity';

/**
 * Get shared configuration.
 * @returns {Object} Configuration object.
 */
export function getSharedConfig() {
    return getConfig( 'shelterkit-donations' ) || {};
}

/**
 * Format an amount in the store's currency, exactly as the server's
 * Helpers\format_currency() does: WooCommerce's symbol, price format,
 * decimals and separators, from the shared config (#28).
 *
 * @param {number} amount - The amount to format.
 * @returns {string} Formatted currency string, e.g. "$1,234.50" or "1.234,50 €".
 */
export function formatCurrency( amount ) {
    const config = getSharedConfig();
    const {
        symbol = '$',
        position = 'before',
        decimals = 2,
        decimalSep = '.',
        thousandSep = ',',
        format = position === 'before' ? '%1$s%2$s' : '%2$s%1$s',
    } = config?.currency || {};

    const value = parseFloat( amount ) || 0;
    const [ whole, fraction ] = Math.abs( value ).toFixed( decimals ).split( '.' );
    const number = whole.replace( /\B(?=(\d{3})+(?!\d))/g, thousandSep ) + ( fraction ? decimalSep + fraction : '' );

    return ( value < 0 ? '-' : '' ) + format.replace( '%1$s', symbol ).replace( '%2$s', number );
}

/**
 * Debounce function execution.
 *
 * @param {Function} fn    - Function to debounce.
 * @param {number}   delay - Delay in milliseconds.
 * @returns {Function} Debounced function.
 */
export function debounce( fn, delay = 300 ) {
    let timeoutId;
    return function( ...args ) {
        clearTimeout( timeoutId );
        timeoutId = setTimeout( () => fn.apply( this, args ), delay );
    };
}

/**
 * Make an authenticated REST API request.
 *
 * @param {string} endpoint - API endpoint path.
 * @param {Object} options  - Fetch options.
 * @returns {Promise<Object>} Response data.
 */
export async function apiRequest( endpoint, options = {} ) {
    const config = getSharedConfig();
    const url = `${ config.restUrl }${ endpoint }`;
    
    const response = await fetch( url, {
        method: 'GET',
        ...options,
        headers: {
            'Content-Type': 'application/json',
            'X-WP-Nonce': config.nonce,
            ...( options.headers || {} ),
        },
    } );

    if ( ! response.ok ) {
        const error = await response.json().catch( () => ( {} ) );
        throw new Error( error.message || `Request failed: ${ response.status }` );
    }

    return response.json();
}

/**
 * POST request helper.
 *
 * @param {string} endpoint - API endpoint.
 * @param {Object} data     - Request body data.
 * @returns {Promise<Object>} Response data.
 */
export async function apiPost( endpoint, data = {} ) {
    return apiRequest( endpoint, {
        method: 'POST',
        body: JSON.stringify( data ),
    } );
}

/**
 * Submit form data via AJAX.
 *
 * @param {string}   action   - AJAX action name.
 * @param {FormData} formData - Form data to submit.
 * @returns {Promise<Object>} Response data.
 */
export async function ajaxSubmit( action, formData ) {
    const config = getSharedConfig();
    
    if ( ! ( formData instanceof FormData ) ) {
        formData = new FormData();
    }
    
    formData.append( 'action', action );
    
    const response = await fetch( config.ajaxUrl, {
        method: 'POST',
        body: formData,
        credentials: 'same-origin',
    } );

    return response.json();
}

/**
 * Get i18n string from config.
 *
 * @param {string} key     - Translation key.
 * @param {string} fallback - Fallback text.
 * @returns {string} Translated string.
 */
export function __( key, fallback = '' ) {
    const config = getSharedConfig();
    return config?.i18n?.[ key ] || fallback || key;
}

/**
 * Validate email format.
 *
 * @param {string} email - Email to validate.
 * @returns {boolean} True if valid.
 */
export function isValidEmail( email ) {
    return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test( email );
}

/**
 * Sanitize text input.
 *
 * @param {string} text - Text to sanitize.
 * @returns {string} Sanitized text.
 */
export function sanitizeText( text ) {
    return String( text || '' ).trim().substring( 0, 1000 );
}

/**
 * Parse amount from string or number.
 *
 * @param {string|number} value - Value to parse.
 * @returns {number} Parsed amount.
 */
export function parseAmount( value ) {
    const num = parseFloat( value );
    return isNaN( num ) ? 0 : Math.max( 0, num );
}
