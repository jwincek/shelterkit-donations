<?php
/**
 * Product Sync Checker - Validates WooCommerce products against plugin config.
 *
 * Compares product attributes, variations, prices, and meta against
 * tiers.json, settings.json, and products.json to detect drift.
 *
 * @package Starter_Shelter
 * @subpackage Admin
 * @since 2.1.0
 */

declare( strict_types = 1 );

namespace Starter_Shelter\Admin;

use Starter_Shelter\Core\Config;

/**
 * Validates product-config alignment and surfaces issues.
 *
 * @since 2.1.0
 */
class Product_Sync_Checker {

	/**
	 * Expected product definitions keyed by SKU.
	 *
	 * @var array
	 */
	private static array $product_map = [
		'shelter-donations' => [
			'option_key'     => 'sd_donation_product_id',
			'attribute_name' => 'Preferred Allocation',
			'attribute_slug' => 'preferred-allocation',
			'config_source'  => 'allocations',
		],
		'shelter-memberships' => [
			'option_key'     => 'sd_membership_product_id',
			'attribute_name' => 'Membership Level',
			'attribute_slug' => 'membership-level',
			'config_source'  => 'tiers_individual',
		],
		'shelter-memberships-business' => [
			'option_key'     => 'sd_business_membership_product_id',
			'attribute_name' => 'Membership Level',
			'attribute_slug' => 'membership-level',
			'config_source'  => 'tiers_business',
		],
		'shelter-donations-in-memoriam' => [
			'option_key'     => 'sd_memorial_product_id',
			'attribute_name' => 'In Memoriam Type',
			'attribute_slug' => 'in-memoriam-type',
			'config_source'  => 'memorial_types',
		],
	];

	/**
	 * Run all sync checks.
	 *
	 * @since 2.1.0
	 *
	 * @return array{
	 *     status: string,
	 *     products: array,
	 *     issues: array,
	 *     summary: string
	 * }
	 */
	public static function check(): array {
		$results = [
			'status'   => 'ok',
			'products' => [],
			'issues'   => [],
			'summary'  => '',
		];

		foreach ( self::$product_map as $sku => $definition ) {
			$product_result = self::check_product( $sku, $definition );
			$results['products'][ $sku ] = $product_result;

			if ( ! empty( $product_result['issues'] ) ) {
				$results['issues'] = array_merge( $results['issues'], $product_result['issues'] );
			}
		}

		// Check taxonomy registration.
		$taxonomy_issues = self::check_taxonomies();
		$results['issues'] = array_merge( $results['issues'], $taxonomy_issues );

		// Set overall status.
		$error_count   = count( array_filter( $results['issues'], fn( $i ) => $i['severity'] === 'error' ) );
		$warning_count = count( array_filter( $results['issues'], fn( $i ) => $i['severity'] === 'warning' ) );
		$info_count    = count( array_filter( $results['issues'], fn( $i ) => $i['severity'] === 'info' ) );

		if ( $error_count > 0 ) {
			$results['status'] = 'error';
		} elseif ( $warning_count > 0 ) {
			$results['status'] = 'warning';
		}

		$results['summary'] = sprintf(
			/* translators: 1: error count, 2: warning count, 3: info count */
			__( '%1$d errors, %2$d warnings, %3$d info', 'starter-shelter' ),
			$error_count,
			$warning_count,
			$info_count
		);

		return $results;
	}

	/**
	 * Check a single product against its config.
	 *
	 * @since 2.1.0
	 *
	 * @param string $sku        Product SKU.
	 * @param array  $definition Expected product definition.
	 * @return array Check results for this product.
	 */
	private static function check_product( string $sku, array $definition ): array {
		$result = [
			'sku'        => $sku,
			'product_id' => 0,
			'status'     => 'ok',
			'issues'     => [],
		];

		// 1. Check option key stores a valid product ID.
		$product_id = (int) get_option( $definition['option_key'], 0 );

		if ( ! $product_id ) {
			$result['issues'][] = self::issue(
				'error',
				$sku,
				sprintf( 'Option %s is not set. Product may not have been created.', $definition['option_key'] )
			);
			$result['status'] = 'error';
			return $result;
		}

		$result['product_id'] = $product_id;

		// 2. Check product exists and is the right type.
		$product = wc_get_product( $product_id );

		if ( ! $product ) {
			$result['issues'][] = self::issue(
				'error',
				$sku,
				sprintf( 'Product ID %d does not exist.', $product_id )
			);
			$result['status'] = 'error';
			return $result;
		}

		if ( ! $product->is_type( 'variable' ) ) {
			$result['issues'][] = self::issue(
				'error',
				$sku,
				sprintf( 'Product is type "%s", expected "variable".', $product->get_type() )
			);
		}

		// Check SKU matches.
		if ( $product->get_sku() !== $sku ) {
			$result['issues'][] = self::issue(
				'warning',
				$sku,
				sprintf( 'Product SKU is "%s", expected "%s".', $product->get_sku(), $sku )
			);
		}

		// 3. Check product settings.
		if ( ! $product->is_virtual() ) {
			$result['issues'][] = self::issue( 'warning', $sku, 'Product is not virtual.' );
		}
		if ( $product->get_tax_status() !== 'none' ) {
			$result['issues'][] = self::issue( 'warning', $sku, 'Product tax status is not "none".' );
		}

		// 4. Check attribute.
		$attributes = $product->get_attributes();
		$expected_slug = $definition['attribute_slug'];
		$found_attribute = null;

		foreach ( $attributes as $attr_key => $attribute ) {
			$slug = sanitize_title( $attr_key );
			if ( $slug === $expected_slug || $attr_key === $expected_slug ) {
				$found_attribute = $attribute;
				break;
			}
		}

		if ( ! $found_attribute ) {
			$result['issues'][] = self::issue(
				'error',
				$sku,
				sprintf( 'Missing attribute "%s".', $definition['attribute_name'] )
			);
			$result['status'] = 'error';
			return $result;
		}

		// 5. Check variations against config.
		$expected_terms = self::get_expected_terms( $definition['config_source'] );
		$variations = $product->get_available_variations();

		// Check variation count.
		if ( count( $variations ) !== count( $expected_terms ) ) {
			$result['issues'][] = self::issue(
				'warning',
				$sku,
				sprintf(
					'Has %d variations, config expects %d.',
					count( $variations ),
					count( $expected_terms )
				)
			);
		}

		// Check each expected term has a variation with correct price.
		// Match flexibly: variations may have price suffixes ("Single Membership - $10")
		// or different labels ("Person" vs "In Memory of a Person") from config.
		$variation_list = [];
		foreach ( $variations as $variation ) {
			foreach ( $variation['attributes'] as $attr_name => $attr_val ) {
				$variation_list[] = [
					'id'    => $variation['variation_id'],
					'value' => trim( $attr_val ),
					'slug'  => sanitize_title( $attr_val ),
					'price' => (float) ( $variation['display_price'] ?? 0 ),
				];
			}
		}

		$matched_variation_ids = [];

		foreach ( $expected_terms as $term ) {
			$match = self::find_matching_variation( $term, $variation_list );

			if ( ! $match ) {
				$result['issues'][] = self::issue(
					'warning',
					$sku,
					sprintf( 'No variation closely matches config entry "%s" (slug: %s).', $term['label'], $term['slug'] )
				);
			} else {
				$matched_variation_ids[] = $match['id'];

				if ( isset( $term['price'] ) && abs( $match['price'] - $term['price'] ) > 0.01 ) {
					$result['issues'][] = self::issue(
						'warning',
						$sku,
						sprintf(
							'Variation "%s" price is $%s, config expects $%s.',
							$match['value'],
							number_format( $match['price'], 2 ),
							number_format( $term['price'], 2 )
						)
					);
				}
			}
		}

		// Check for orphan variations (in product but not matched to any config entry).
		foreach ( $variation_list as $v ) {
			if ( ! in_array( $v['id'], $matched_variation_ids, true ) ) {
				$result['issues'][] = self::issue(
					'info',
					$sku,
					sprintf( 'Variation "%s" was not matched to a config entry.', $v['value'] )
				);
			}
		}

		// 6. Check product image.
		if ( ! $product->get_image_id() ) {
			$result['issues'][] = self::issue( 'warning', $sku, 'Product has no image set.' );
		}

		if ( ! empty( $result['issues'] ) ) {
			$has_errors = ! empty( array_filter( $result['issues'], fn( $i ) => $i['severity'] === 'error' ) );
			$result['status'] = $has_errors ? 'error' : 'warning';
		}

		return $result;
	}

	/**
	 * Get expected variation terms from config.
	 *
	 * @since 2.1.0
	 *
	 * @param string $source Config source key.
	 * @return array Array of [ 'label' => '', 'slug' => '', 'price' => 0 ].
	 */
	private static function get_expected_terms( string $source ): array {
		switch ( $source ) {
			case 'allocations':
				$allocations = Config::get_item( 'settings', 'allocations', [] );
				return array_map(
					fn( $key, $label ) => [ 'label' => $label, 'slug' => $key, 'price' => 10 ],
					array_keys( $allocations ),
					array_values( $allocations )
				);

			case 'tiers_individual':
				$all_tiers = Config::get_item( 'tiers', 'tiers', [] );
				$tiers = $all_tiers['individual'] ?? [];
				return array_map(
					fn( $slug, $tier ) => [
						'label' => $tier['label'] ?? $tier['name'] ?? ucfirst( $slug ),
						'slug'  => $slug,
						'price' => (float) ( $tier['amount'] ?? $tier['price'] ?? 0 ),
					],
					array_keys( $tiers ),
					array_values( $tiers )
				);

			case 'tiers_business':
				$all_tiers = Config::get_item( 'tiers', 'tiers', [] );
				$tiers = $all_tiers['business'] ?? [];
				return array_map(
					fn( $slug, $tier ) => [
						'label' => $tier['label'] ?? $tier['name'] ?? ucfirst( $slug ),
						'slug'  => $slug,
						'price' => (float) ( $tier['amount'] ?? $tier['price'] ?? 0 ),
					],
					array_keys( $tiers ),
					array_values( $tiers )
				);

			case 'memorial_types':
				$types = Config::get_item( 'settings', 'memorial_types', [] );
				return array_map(
					fn( $key, $label ) => [ 'label' => $label, 'slug' => $key, 'price' => 10 ],
					array_keys( $types ),
					array_values( $types )
				);

			default:
				return [];
		}
	}

	/**
	 * Check custom taxonomy registration.
	 *
	 * @since 2.1.0
	 *
	 * @return array Issues found.
	 */
	private static function check_taxonomies(): array {
		$issues = [];

		$expected = [
			'sd_campaign'       => [ 'sd_donation' ],
			'sd_memorial_year'  => [ 'sd_memorial' ],
		];

		foreach ( $expected as $taxonomy => $post_types ) {
			if ( ! taxonomy_exists( $taxonomy ) ) {
				$issues[] = self::issue( 'error', 'taxonomy', sprintf( 'Taxonomy "%s" is not registered.', $taxonomy ) );
				continue;
			}

			$tax_object = get_taxonomy( $taxonomy );
			foreach ( $post_types as $pt ) {
				if ( ! in_array( $pt, (array) $tax_object->object_type, true ) ) {
					$issues[] = self::issue(
						'warning',
						'taxonomy',
						sprintf( 'Taxonomy "%s" not attached to post type "%s".', $taxonomy, $pt )
					);
				}
			}
		}

		// Check CPTs exist.
		$expected_cpts = [ 'sd_donation', 'sd_membership', 'sd_memorial', 'sd_donor' ];
		foreach ( $expected_cpts as $cpt ) {
			if ( ! post_type_exists( $cpt ) ) {
				$issues[] = self::issue( 'error', 'cpt', sprintf( 'Post type "%s" is not registered.', $cpt ) );
			}
		}

		return $issues;
	}

	/**
	 * Find a variation that matches an expected config term.
	 *
	 * Uses progressively looser matching:
	 * 1. Exact label match (case-insensitive)
	 * 2. Exact slug match
	 * 3. Variation slug starts with config slug (handles price suffixes)
	 * 4. Config slug found within variation slug (handles "person" in "in-memoriam-person")
	 * 5. Variation value contains config label as substring
	 *
	 * @since 2.1.0
	 *
	 * @param array $term           Expected term with 'label' and 'slug'.
	 * @param array $variation_list All variation data.
	 * @return array|null Matched variation or null.
	 */
	private static function find_matching_variation( array $term, array $variation_list ): ?array {
		$term_label_lower = strtolower( $term['label'] );
		$term_slug        = $term['slug'];

		foreach ( $variation_list as $v ) {
			$v_lower = strtolower( $v['value'] );
			$v_slug  = $v['slug'];

			// Exact label match.
			if ( $v_lower === $term_label_lower ) {
				return $v;
			}

			// Exact slug match.
			if ( $v_slug === $term_slug ) {
				return $v;
			}
		}

		// Looser matching passes.
		foreach ( $variation_list as $v ) {
			$v_lower = strtolower( $v['value'] );
			$v_slug  = $v['slug'];

			// Variation slug starts with config slug (e.g. "single-membership-10" starts with "single").
			if ( str_starts_with( $v_slug, $term_slug ) ) {
				return $v;
			}

			// Config slug found within variation slug.
			if ( str_contains( $v_slug, $term_slug ) ) {
				return $v;
			}

			// Variation value contains config label.
			if ( str_contains( $v_lower, $term_label_lower ) ) {
				return $v;
			}

			// Config label contains variation value (e.g. config "In Memory of a Person" contains variation "Person").
			if ( str_contains( $term_label_lower, $v_lower ) ) {
				return $v;
			}
		}

		return null;
	}

	/**
	 * Create an issue array.
	 *
	 * @since 2.1.0
	 *
	 * @param string $severity error|warning|info.
	 * @param string $context  Product SKU or component.
	 * @param string $message  Human-readable message.
	 * @return array Issue data.
	 */
	private static function issue( string $severity, string $context, string $message ): array {
		return [
			'severity' => $severity,
			'context'  => $context,
			'message'  => $message,
		];
	}

	/**
	 * Display sync check results as an admin notice.
	 *
	 * @since 2.1.0
	 */
	public static function maybe_show_admin_notice(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		// Only run on shelter admin pages.
		$screen = get_current_screen();
		if ( ! $screen || strpos( $screen->id, 'starter-shelter' ) === false ) {
			return;
		}

		// Cache results for 1 hour.
		$results = get_transient( 'sd_product_sync_check' );
		if ( false === $results ) {
			if ( ! function_exists( 'wc_get_product' ) ) {
				return;
			}
			$results = self::check();
			set_transient( 'sd_product_sync_check', $results, HOUR_IN_SECONDS );
		}

		if ( 'ok' === $results['status'] ) {
			return;
		}

		$class = 'error' === $results['status'] ? 'notice-error' : 'notice-warning';
		$count = count( $results['issues'] );
		?>
		<div class="notice <?php echo esc_attr( $class ); ?> is-dismissible">
			<p>
				<strong><?php esc_html_e( 'Starter Shelter — Product Sync:', 'starter-shelter' ); ?></strong>
				<?php echo esc_html( $results['summary'] ); ?>
			</p>
			<details>
				<summary><?php printf( esc_html__( 'View %d issue(s)', 'starter-shelter' ), $count ); ?></summary>
				<ul style="margin-left: 1.5em;">
					<?php foreach ( $results['issues'] as $issue ) : ?>
						<li>
							<strong>[<?php echo esc_html( $issue['severity'] ); ?>]</strong>
							<code><?php echo esc_html( $issue['context'] ); ?></code>:
							<?php echo esc_html( $issue['message'] ); ?>
						</li>
					<?php endforeach; ?>
				</ul>
			</details>
			<p>
				<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin.php?page=starter-shelter-settings&action=recheck-sync' ), 'sd_recheck_sync' ) ); ?>" class="button button-small">
					<?php esc_html_e( 'Re-check Now', 'starter-shelter' ); ?>
				</a>
			</p>
		</div>
		<?php
	}

	/**
	 * Handle the re-check action.
	 *
	 * @since 2.1.0
	 */
	public static function handle_recheck(): void {
		if ( ! isset( $_GET['action'] ) || 'recheck-sync' !== $_GET['action'] ) {
			return;
		}
		if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( $_GET['_wpnonce'], 'sd_recheck_sync' ) ) {
			return;
		}

		delete_transient( 'sd_product_sync_check' );
		wp_safe_redirect( remove_query_arg( [ 'action', '_wpnonce' ] ) );
		exit;
	}

	/**
	 * Clear the cached sync check (e.g. after product changes).
	 *
	 * @since 2.1.0
	 */
	public static function invalidate_cache(): void {
		delete_transient( 'sd_product_sync_check' );
	}

	/**
	 * Initialize hooks.
	 *
	 * @since 2.1.0
	 */
	public static function init(): void {
		add_action( 'admin_notices', [ self::class, 'maybe_show_admin_notice' ] );
		add_action( 'admin_init', [ self::class, 'handle_recheck' ] );

		// Invalidate cache when products or config might change.
		add_action( 'woocommerce_update_product', [ self::class, 'invalidate_cache' ] );
		add_action( 'woocommerce_save_product_variation', [ self::class, 'invalidate_cache' ] );
	}
}
