<?php
/**
 * The donation fund list ("allocations"), and the one place every layer reads it.
 *
 * @package Starter_Shelter
 */

declare( strict_types = 1 );

namespace Starter_Shelter\Core;

/**
 * Canonical list of the funds a donor can direct a gift to.
 *
 * The list is the Donation Allocations setting: Config's `settings.allocations`,
 * which is the admin's saved override when there is one and
 * `config/settings.json` otherwise. Before this class the ability's input
 * enum, the activator's variations, the form and the recorded value each
 * kept their own list, and a gift to a fund missing from any one of them was
 * paid for and never recorded (#21).
 *
 * - **Active** funds are offered on the form and accepted for new gifts.
 * - **Retired** funds were removed from the form but are still valid: past
 *   gifts keep their fund, reports keep its label, and reprocessing an old
 *   order still succeeds. A fund is retired, never deleted.
 * - **Aliases** map an old slug to its canonical one (shipped, not editable).
 *
 * Slugs are identifiers stored on every donation (`_skdon_allocation`), so the
 * shipped defaults are frozen; see CONTRIBUTING.
 */
final class Allocations {

    /**
     * Meta key linking a donation-product variation to its fund slug.
     *
     * Variations used to be matched by turning their label into a slug, which
     * broke whenever a label was worded differently from the setting.
     *
     * @var string
     */
    public const VARIATION_META = '_skdon_allocation';

    /**
     * Option recording that the one-time migration has run.
     *
     * @var string
     */
    private const VERSION_OPTION = 'skdon_allocations_version';

    /**
     * Current migration version.
     *
     * @var string
     */
    private const VERSION = '1';

    /**
     * Slug of the donation product's variation attribute.
     *
     * @var string
     */
    private const ATTRIBUTE = 'preferred-allocation';

    /**
     * Regular price of a variation this class creates. The donor's own amount
     * replaces it in the cart, so the value only matters to WooCommerce.
     *
     * @var string
     */
    private const VARIATION_PRICE = '10';

    /**
     * Funds offered on the form and accepted for new gifts.
     *
     * @return array<string, string> slug => label.
     */
    public static function active(): array {
        return self::canonical_keys( (array) Config::get_item( 'settings', 'allocations', [] ) );
    }

    /**
     * Funds no longer offered but still valid for existing records.
     *
     * @return array<string, string> slug => label.
     */
    public static function retired(): array {
        $retired = self::canonical_keys( (array) Config::get_item( 'settings', 'retired_allocations', [] ) );

        return array_diff_key( $retired, self::active() );
    }

    /**
     * Every fund a record may carry: active first, then retired.
     *
     * @return array<string, string> slug => label.
     */
    public static function known(): array {
        return self::active() + self::retired();
    }

    /**
     * Whether a slug is a fund new gifts may go to.
     *
     * @param string $slug Canonical slug.
     * @return bool
     */
    public static function is_active( string $slug ): bool {
        return isset( self::active()[ $slug ] );
    }

    /**
     * Old slug => canonical slug.
     *
     * @return array<string, string>
     */
    public static function aliases(): array {
        return (array) Config::get_item( 'settings', 'allocation_aliases', [] );
    }

    /**
     * The canonical slug for anything that names a fund, or '' if none does.
     *
     * Accepts a slug, an alias, or a label as WooCommerce stores it on a
     * variation ("Spay / Neuter Clinic", "Facility Improvements",
     * "General Fund - $10").
     *
     * @param string $value Submitted, recorded or display value.
     * @return string Canonical slug, or '' when the value names no known fund.
     */
    public static function canonical( string $value ): string {
        $value = strtolower( trim( $value ) );
        $value = (string) preg_replace( '/\s*-\s*\$[\d,.]+$/', '', $value );
        $slug  = sanitize_title( $value );

        if ( '' === $slug ) {
            return '';
        }

        $slug  = self::aliases()[ $slug ] ?? $slug;
        $known = self::known();

        if ( isset( $known[ $slug ] ) ) {
            return $slug;
        }

        foreach ( $known as $known_slug => $label ) {
            if ( sanitize_title( $label ) === $slug ) {
                return $known_slug;
            }
        }

        return '';
    }

    /**
     * Display label for a slug; a humanised slug for one no longer known.
     *
     * @param string $slug Fund slug.
     * @return string
     */
    public static function label( string $slug ): string {
        return self::known()[ $slug ] ?? ucwords( str_replace( [ '-', '_' ], ' ', $slug ) );
    }

    /**
     * Save the active list from the settings screen.
     *
     * A fund missing from the submission is retired, not dropped, so every
     * donation already recorded against it stays valid. Because a saved
     * fund's slug is read-only in the form, a changed slug arrives as one
     * fund removed and another added: the old one is retired and the new one
     * starts empty. Nothing is ever renamed underneath existing records.
     *
     * @param array<string, string> $submitted slug => label, in display order.
     * @param string[]              $restore   Retired slugs to make active again.
     * @return bool False when nothing would be left on the form.
     */
    public static function save( array $submitted, array $restore = [] ): bool {
        $previous = self::active();
        $retired  = self::retired();
        $aliases  = self::aliases();
        $active   = [];

        foreach ( $submitted as $slug => $label ) {
            $slug  = $aliases[ (string) $slug ] ?? (string) $slug;
            $label = trim( (string) $label );

            if ( '' === $slug || '' === $label || isset( $active[ $slug ] ) ) {
                continue;
            }

            $active[ $slug ] = $label;
        }

        foreach ( $restore as $slug ) {
            if ( isset( $retired[ $slug ] ) && ! isset( $active[ $slug ] ) ) {
                $active[ $slug ] = $retired[ $slug ];
            }
        }

        if ( ! $active ) {
            return false;
        }

        foreach ( $previous as $slug => $label ) {
            if ( ! isset( $active[ $slug ] ) ) {
                $retired[ $slug ] = $label;
            }
        }

        $old_retired = self::retired();
        Config::save_override( 'settings', 'allocations', $active );
        Config::save_override( 'settings', 'retired_allocations', array_diff_key( $retired, $active ) );

        /**
         * Fires after the fund list is saved: funds added, retired, restored or renamed.
         *
         * @param array<string,string> $previous    Active funds before, slug => label.
         * @param array<string,string> $active      Active funds now.
         * @param array<string,string> $old_retired Retired funds before.
         * @param array<string,string> $retired     Retired funds now.
         */
        do_action( 'starter_shelter_funds_saved', $previous, $active, $old_retired, array_diff_key( $retired, $active ) );

        self::sync_variations();

        return true;
    }

    /**
     * Give every active fund a donation-product variation, linked by slug.
     *
     * Existing variations are linked once by matching their label (or an
     * alias) and then carry the slug in VARIATION_META. A fund with no
     * variation gets one. Variations for retired or unknown funds are left
     * alone: past orders point at them.
     *
     * @return string[] What changed, for the sync checker's repair report.
     */
    public static function sync_variations(): array {
        if ( ! function_exists( 'wc_get_product' ) ) {
            return [];
        }

        $product = wc_get_product( (int) get_option( 'skdon_donation_product_id', 0 ) );
        if ( ! $product || ! $product->is_type( 'variable' ) ) {
            return [];
        }

        $details = [];
        $linked  = [];

        foreach ( $product->get_children() as $variation_id ) {
            $variation = wc_get_product( $variation_id );
            if ( ! $variation ) {
                continue;
            }

            $slug = (string) $variation->get_meta( self::VARIATION_META );
            if ( '' === $slug ) {
                $label = self::attribute_value( $variation );
                $slug  = self::canonical( $label );
                if ( '' === $slug ) {
                    continue;
                }
                $variation->update_meta_data( self::VARIATION_META, $slug );
                $variation->save();
                $details[] = sprintf( 'linked variation "%s" to fund %s.', $label, $slug );
            }

            $linked[ $slug ] = $linked[ $slug ] ?? $variation_id;
        }

        $added = [];
        foreach ( self::active() as $slug => $label ) {
            if ( isset( $linked[ $slug ] ) ) {
                continue;
            }

            $variation = new \WC_Product_Variation();
            $variation->set_parent_id( $product->get_id() );
            $variation->set_status( 'publish' );
            $variation->set_regular_price( self::VARIATION_PRICE );
            $variation->set_virtual( true );
            $variation->set_manage_stock( false );
            $variation->set_stock_status( 'instock' );
            $variation->set_attributes( [ self::ATTRIBUTE => $label ] );
            $variation->update_meta_data( self::VARIATION_META, $slug );
            $variation->save();

            $added[]   = $label;
            $details[] = sprintf( 'created variation "%s" for fund %s.', $label, $slug );
        }

        if ( $added ) {
            // A variation's value must be one of the parent attribute's options
            // for WooCommerce to sell it.
            $attributes = $product->get_attributes();
            foreach ( $attributes as $key => $attribute ) {
                if ( self::ATTRIBUTE === sanitize_title( (string) $key ) ) {
                    $attribute->set_options( array_values( array_unique( array_merge( $attribute->get_options(), $added ) ) ) );
                }
            }
            $product->set_attributes( $attributes );
            $product->save();
            \WC_Product_Variable::sync( $product->get_id() );
        }

        return $details;
    }

    /**
     * The donation-product variation for a fund, or 0.
     *
     * @param \WC_Product $product Donation product (variable).
     * @param string      $slug    Canonical fund slug.
     * @return int Variation ID.
     */
    public static function find_variation_id( \WC_Product $product, string $slug ): int {
        if ( '' === $slug ) {
            return 0;
        }

        $by_label = 0;
        foreach ( $product->get_children() as $variation_id ) {
            $variation = wc_get_product( $variation_id );
            if ( ! $variation || ! $variation->is_purchasable() ) {
                continue;
            }

            $linked = (string) $variation->get_meta( self::VARIATION_META );
            if ( $slug === $linked ) {
                return (int) $variation_id;
            }

            if ( '' === $linked && ! $by_label && self::canonical( self::attribute_value( $variation ) ) === $slug ) {
                $by_label = (int) $variation_id;
            }
        }

        return $by_label;
    }

    /**
     * One-time upgrade for sites that had the list before it was canonical.
     *
     * 1. A saved list keyed by an alias (`spay-neuter`) is rewritten to the
     *    canonical slug, keeping the shelter's own label.
     * 2. A fund already recorded on donations but missing from the list is
     *    added as retired, so its history stays valid and labelled.
     * 3. Variations are linked to their funds, and missing ones created.
     *
     * Runs on admin_init. It waits for WooCommerce, so that step 3 can run.
     */
    public static function maybe_migrate(): void {
        if ( self::VERSION === get_option( self::VERSION_OPTION ) || ! function_exists( 'wc_get_product' ) ) {
            return;
        }

        foreach ( [ 'allocations', 'retired_allocations' ] as $key ) {
            if ( ! Config::has_override( 'settings', $key ) ) {
                continue;
            }
            $stored    = (array) Config::get_item( 'settings', $key, [] );
            $canonical = self::canonical_keys( $stored );
            if ( $canonical !== $stored ) {
                Config::save_override( 'settings', $key, $canonical );
            }
        }

        global $wpdb;
        $recorded = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT DISTINCT pm.meta_value FROM {$wpdb->postmeta} pm
                 INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
                 WHERE pm.meta_key = %s AND p.post_type = %s",
                '_skdon_allocation',
                'skdon_donation'
            )
        );

        $retired = self::retired();
        $added   = false;
        foreach ( $recorded as $value ) {
            $slug = sanitize_key( (string) $value );
            $slug = self::aliases()[ $slug ] ?? $slug;
            if ( '' === $slug || '' !== self::canonical( $slug ) ) {
                continue;
            }
            $retired[ $slug ] = self::label( $slug );
            $added            = true;
        }
        if ( $added ) {
            Config::save_override( 'settings', 'retired_allocations', $retired );
        }

        self::sync_variations();

        update_option( self::VERSION_OPTION, self::VERSION );
    }

    /**
     * Rewrite alias keys to canonical slugs, keeping the first label seen.
     *
     * @param array<string, string> $map slug => label.
     * @return array<string, string>
     */
    private static function canonical_keys( array $map ): array {
        $aliases = self::aliases();
        $out     = [];

        foreach ( $map as $slug => $label ) {
            $slug = $aliases[ (string) $slug ] ?? (string) $slug;
            if ( '' !== $slug && ! isset( $out[ $slug ] ) ) {
                $out[ $slug ] = (string) $label;
            }
        }

        return $out;
    }

    /**
     * The fund label a variation carries in its attribute.
     *
     * @param \WC_Product $variation Variation.
     * @return string
     */
    private static function attribute_value( \WC_Product $variation ): string {
        $attributes = $variation->get_variation_attributes();
        $value      = $attributes[ 'attribute_' . self::ATTRIBUTE ] ?? reset( $attributes );

        return is_string( $value ) ? $value : '';
    }
}
