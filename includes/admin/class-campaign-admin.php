<?php
/**
 * Campaign Admin - Term meta + WP term-edit UI for sd_campaign.
 *
 * Closes audit §1 P0-1: no writer existed for `_sd_goal` or `_sd_end_date`.
 * Registers the two as REST-exposed term meta and adds matching fields to
 * the standard WP term add/edit forms (at edit-tags.php?taxonomy=sd_campaign).
 *
 * @package Starter_Shelter
 * @subpackage Admin
 * @since 1.1.3
 */

declare( strict_types = 1 );

namespace Starter_Shelter\Admin;

/**
 * Adds writable goal / end-date term meta to sd_campaign.
 *
 * REST-exposure of the meta lets a future block-editor inspector
 * (mirroring assets/js/memorial-panel.js) read/write these via
 * useEntityRecords without any additional REST plumbing.
 *
 * @since 1.1.3
 */
class Campaign_Admin {

    /**
     * Target taxonomy slug.
     */
    private const TAXONOMY = 'sd_campaign';

    /**
     * Recognized campaign types. `donation_drive` is the historical
     * default — campaigns count $ raised from sd_donation. `membership_drive`
     * counts new memberships (optionally restricted by tier_filter)
     * against a numeric goal. (Audit-campaigns §4 also names a 'mixed'
     * type; deferred until there's a concrete use case to inform its
     * progress-display design.)
     */
    private const TYPES = [
        'donation_drive'   => 'Donation Drive',
        'membership_drive' => 'Membership Drive',
    ];

    /**
     * Initialize hooks. Safe to call from any priority — the work happens
     * on `init` (for register_term_meta) and on taxonomy-specific
     * add/edit/save actions that only fire from the term-edit admin
     * page and the REST routes.
     */
    public static function init(): void {
        add_action( 'init', [ self::class, 'register_term_meta' ] );

        // Term-edit form rendering + save handlers. WP core verifies the
        // form's _wp_http_referer nonce before invoking created_/edited_,
        // so a separate nonce check here would be redundant.
        $tax = self::TAXONOMY;
        add_action( "{$tax}_add_form_fields",  [ self::class, 'render_add_fields' ] );
        add_action( "{$tax}_edit_form_fields", [ self::class, 'render_edit_fields' ] );
        add_action( "created_{$tax}",          [ self::class, 'save_fields' ] );
        add_action( "edited_{$tax}",           [ self::class, 'save_fields' ] );

        // The sd_donation CPT lives under the 'starter-shelter' menu, but
        // taxonomies attached to a CPT with a custom show_in_menu parent
        // don't auto-follow there — so add an explicit submenu link.
        add_action( 'admin_menu', [ self::class, 'register_submenu' ] );
    }

    /**
     * Add a "Campaigns" item to the Shelter Donations menu that opens
     * the standard WP taxonomy edit page for sd_campaign.
     */
    public static function register_submenu(): void {
        add_submenu_page(
            Menu::MENU_SLUG,
            __( 'Campaigns', 'starter-shelter' ),
            __( 'Campaigns', 'starter-shelter' ),
            'manage_categories',
            'edit-tags.php?taxonomy=' . self::TAXONOMY . '&post_type=sd_donation'
        );
    }

    /**
     * Register `_sd_goal` (number) and `_sd_end_date` (string) as
     * REST-exposed term meta on sd_campaign.
     */
    public static function register_term_meta(): void {
        register_term_meta( self::TAXONOMY, '_sd_goal', [
            'type'              => 'number',
            'description'       => 'Campaign goal: dollars (donation_drive) or member count (membership_drive).',
            'single'            => true,
            'show_in_rest'      => true,
            'sanitize_callback' => static fn( $value ) => max( 0.0, (float) $value ),
            'auth_callback'     => static fn() => current_user_can( 'manage_options' ),
        ] );

        register_term_meta( self::TAXONOMY, '_sd_end_date', [
            'type'              => 'string',
            'description'       => 'Campaign end date (Y-m-d, blank = ongoing).',
            'single'            => true,
            'show_in_rest'      => true,
            'sanitize_callback' => [ self::class, 'sanitize_end_date' ],
            'auth_callback'     => static fn() => current_user_can( 'manage_options' ),
        ] );

        register_term_meta( self::TAXONOMY, '_sd_campaign_type', [
            'type'              => 'string',
            'description'       => 'Campaign type: donation_drive (default) or membership_drive.',
            'single'            => true,
            'show_in_rest'      => true,
            'sanitize_callback' => [ self::class, 'sanitize_campaign_type' ],
            'auth_callback'     => static fn() => current_user_can( 'manage_options' ),
        ] );

        register_term_meta( self::TAXONOMY, '_sd_membership_tier_filter', [
            'type'              => 'string',
            'description'       => 'Optional tier slug that restricts which memberships count toward a membership_drive goal.',
            'single'            => true,
            'show_in_rest'      => true,
            'sanitize_callback' => 'sanitize_key',
            'auth_callback'     => static fn() => current_user_can( 'manage_options' ),
        ] );
    }

    /**
     * Coerce campaign_type to a recognized enum value; falls back to
     * 'donation_drive' for unknown / empty / legacy values.
     *
     * @param mixed $value Raw submitted value.
     */
    public static function sanitize_campaign_type( $value ): string {
        $v = is_string( $value ) ? sanitize_key( $value ) : '';
        return array_key_exists( $v, self::TYPES ) ? $v : 'donation_drive';
    }

    /**
     * Coerce/validate an end-date value as a strict Y-m-d string.
     *
     * Returns the original string if it parses cleanly and round-trips
     * (so '2026-13-01' is rejected); empty string otherwise.
     *
     * @param mixed $value Raw submitted value.
     * @return string Validated Y-m-d, or empty string.
     */
    public static function sanitize_end_date( $value ): string {
        if ( ! is_string( $value ) ) {
            return '';
        }
        $v = sanitize_text_field( $value );
        if ( '' === $v ) {
            return '';
        }
        $d = \DateTime::createFromFormat( 'Y-m-d', $v );
        return ( $d && $d->format( 'Y-m-d' ) === $v ) ? $v : '';
    }

    /**
     * Render the goal + end-date inputs on the "Add New Campaign" form.
     */
    public static function render_add_fields(): void {
        ?>
        <div class="form-field">
            <label for="sd_campaign_type"><?php esc_html_e( 'Campaign Type', 'starter-shelter' ); ?></label>
            <select name="sd_campaign_type" id="sd_campaign_type">
                <?php foreach ( self::TYPES as $value => $label ) : ?>
                <option value="<?php echo esc_attr( $value ); ?>" <?php selected( $value, 'donation_drive' ); ?>>
                    <?php echo esc_html( $label ); ?>
                </option>
                <?php endforeach; ?>
            </select>
            <p><?php esc_html_e( 'Donation Drive: goal is dollars raised. Membership Drive: goal is count of new members.', 'starter-shelter' ); ?></p>
        </div>
        <div class="form-field">
            <label for="sd_goal"><?php esc_html_e( 'Goal', 'starter-shelter' ); ?></label>
            <input type="number" name="sd_goal" id="sd_goal" value="" min="0" step="0.01" />
            <p><?php esc_html_e( 'Donation Drive: target in dollars. Membership Drive: number of new memberships.', 'starter-shelter' ); ?></p>
        </div>
        <div class="form-field">
            <label for="sd_membership_tier_filter"><?php esc_html_e( 'Tier Filter (Membership Drive only)', 'starter-shelter' ); ?></label>
            <input type="text" name="sd_membership_tier_filter" id="sd_membership_tier_filter" value="" />
            <p><?php esc_html_e( 'Optional tier slug to restrict counting (e.g. "guardian"). Leave blank to count all memberships.', 'starter-shelter' ); ?></p>
        </div>
        <div class="form-field">
            <label for="sd_end_date"><?php esc_html_e( 'End Date', 'starter-shelter' ); ?></label>
            <input type="date" name="sd_end_date" id="sd_end_date" value="" />
            <p><?php esc_html_e( 'Campaign ends on this date. Leave blank for ongoing campaigns.', 'starter-shelter' ); ?></p>
        </div>
        <?php
    }

    /**
     * Render the goal + end-date inputs on the "Edit Campaign" form.
     *
     * @param \WP_Term $term The taxonomy term being edited.
     */
    public static function render_edit_fields( \WP_Term $term ): void {
        $goal          = get_term_meta( $term->term_id, '_sd_goal', true );
        $end_date      = (string) get_term_meta( $term->term_id, '_sd_end_date', true );
        $current_type  = self::sanitize_campaign_type( get_term_meta( $term->term_id, '_sd_campaign_type', true ) );
        $tier_filter   = (string) get_term_meta( $term->term_id, '_sd_membership_tier_filter', true );
        ?>
        <tr class="form-field">
            <th scope="row"><label for="sd_campaign_type"><?php esc_html_e( 'Campaign Type', 'starter-shelter' ); ?></label></th>
            <td>
                <select name="sd_campaign_type" id="sd_campaign_type">
                    <?php foreach ( self::TYPES as $value => $label ) : ?>
                    <option value="<?php echo esc_attr( $value ); ?>" <?php selected( $value, $current_type ); ?>>
                        <?php echo esc_html( $label ); ?>
                    </option>
                    <?php endforeach; ?>
                </select>
                <p class="description"><?php esc_html_e( 'Donation Drive: goal is dollars raised. Membership Drive: goal is count of new members.', 'starter-shelter' ); ?></p>
            </td>
        </tr>
        <tr class="form-field">
            <th scope="row"><label for="sd_goal"><?php esc_html_e( 'Goal', 'starter-shelter' ); ?></label></th>
            <td>
                <input type="number" name="sd_goal" id="sd_goal" value="<?php echo esc_attr( '' === $goal ? '' : (string) (float) $goal ); ?>" min="0" step="0.01" />
                <p class="description"><?php esc_html_e( 'Donation Drive: target in dollars. Membership Drive: number of new memberships.', 'starter-shelter' ); ?></p>
            </td>
        </tr>
        <tr class="form-field">
            <th scope="row"><label for="sd_membership_tier_filter"><?php esc_html_e( 'Tier Filter', 'starter-shelter' ); ?></label></th>
            <td>
                <input type="text" name="sd_membership_tier_filter" id="sd_membership_tier_filter" value="<?php echo esc_attr( $tier_filter ); ?>" />
                <p class="description"><?php esc_html_e( 'Membership Drive only. Optional tier slug to restrict counting (e.g. "guardian"). Leave blank to count all memberships.', 'starter-shelter' ); ?></p>
            </td>
        </tr>
        <tr class="form-field">
            <th scope="row"><label for="sd_end_date"><?php esc_html_e( 'End Date', 'starter-shelter' ); ?></label></th>
            <td>
                <input type="date" name="sd_end_date" id="sd_end_date" value="<?php echo esc_attr( $end_date ); ?>" />
                <p class="description"><?php esc_html_e( 'Campaign ends on this date. Leave blank for ongoing campaigns.', 'starter-shelter' ); ?></p>
            </td>
        </tr>
        <?php
    }

    /**
     * Save handler for both create and edit. Receives the term ID after
     * WP core has already verified the term-edit form's nonce.
     *
     * @param int $term_id Term ID being created/edited.
     */
    public static function save_fields( int $term_id ): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        if ( array_key_exists( 'sd_goal', $_POST ) ) {
            $goal = max( 0.0, (float) wp_unslash( $_POST['sd_goal'] ) );
            update_term_meta( $term_id, '_sd_goal', $goal );
        }

        if ( array_key_exists( 'sd_end_date', $_POST ) ) {
            $end_date = self::sanitize_end_date( wp_unslash( $_POST['sd_end_date'] ) );
            if ( '' !== $end_date ) {
                update_term_meta( $term_id, '_sd_end_date', $end_date );
            } else {
                delete_term_meta( $term_id, '_sd_end_date' );
            }
        }

        if ( array_key_exists( 'sd_campaign_type', $_POST ) ) {
            $type = self::sanitize_campaign_type( wp_unslash( $_POST['sd_campaign_type'] ) );
            update_term_meta( $term_id, '_sd_campaign_type', $type );
        }

        if ( array_key_exists( 'sd_membership_tier_filter', $_POST ) ) {
            $tier = sanitize_key( wp_unslash( $_POST['sd_membership_tier_filter'] ) );
            if ( '' !== $tier ) {
                update_term_meta( $term_id, '_sd_membership_tier_filter', $tier );
            } else {
                delete_term_meta( $term_id, '_sd_membership_tier_filter' );
            }
        }
    }
}
