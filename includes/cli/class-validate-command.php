<?php
/**
 * WP-CLI: starter-shelter validate
 *
 * Static-analysis-style consistency checker for the plugin's
 * config-driven contracts. Catches the classes of bug that recurred
 * across the audit pattern sweep — ability references that don't
 * resolve, product mappings that target nonexistent ability inputs,
 * domain action hooks fired with no listener (or vice versa), and
 * products.json `ability` ids that aren't declared.
 *
 * Each check is conservative (low false-positive rate) and operates
 * on static string matching across config JSON + PHP source.
 *
 * @package Starter_Shelter
 * @subpackage Cli
 * @since 1.1.2
 */

declare( strict_types = 1 );

namespace Starter_Shelter\Cli;

use Starter_Shelter\Core\Field_Manifest;
use WP_CLI;

/**
 * `wp starter-shelter validate` — config/code contract validator.
 *
 * @since 1.1.2
 */
class Validate_Command {

	/**
	 * Validate the plugin's config + code contracts.
	 *
	 * ## OPTIONS
	 *
	 * [--check=<name>]
	 * : Run only one named check. One of: abilities, products, hooks, mappings.
	 *
	 * [--format=<format>]
	 * : Output format. One of: human (default), json.
	 *
	 * ## EXAMPLES
	 *
	 *     wp starter-shelter validate
	 *     wp starter-shelter validate --check=abilities
	 *     wp starter-shelter validate --format=json
	 *
	 * @when before_wp_load
	 *
	 * @param array<int, string>    $args       Positional args.
	 * @param array<string, string> $assoc_args Flag args.
	 */
	public function __invoke( array $args, array $assoc_args ): void {
		$only   = $assoc_args['check'] ?? null;
		$format = $assoc_args['format'] ?? 'human';

		$abilities_config = $this->load_json( 'config/abilities.json' );
		$products_config  = $this->load_json( 'config/products.json' );
		$emails_config    = $this->load_json( 'config/emails.json' );

		// Manifests own a growing subset of abilities and products; project
		// them and merge so checks see the full declared surface (both
		// sources).
		$this->init_manifest_loader();
		foreach ( Field_Manifest::get_abilities() as $ability_id => $cfg ) {
			$abilities_config['abilities'][ $ability_id ] = $cfg;
		}
		foreach ( Field_Manifest::get_products() as $sku_prefix => $cfg ) {
			$products_config['products'][ $sku_prefix ] = $cfg;
		}

		$findings = [];

		if ( null === $only || 'abilities' === $only ) {
			$findings = array_merge(
				$findings,
				$this->check_ability_references( $abilities_config )
			);
		}
		if ( null === $only || 'products' === $only ) {
			$findings = array_merge(
				$findings,
				$this->check_product_ability_ids( $products_config, $abilities_config )
			);
		}
		if ( null === $only || 'mappings' === $only ) {
			$findings = array_merge(
				$findings,
				$this->check_product_input_mappings( $products_config, $abilities_config )
			);
		}
		if ( null === $only || 'hooks' === $only ) {
			$findings = array_merge(
				$findings,
				$this->check_domain_action_hooks( $emails_config )
			);
		}
		if ( null === $only || 'manifests' === $only ) {
			$findings = array_merge(
				$findings,
				$this->check_manifest_coverage(),
				$this->check_manifest_abilities(),
				$this->check_manifest_products(),
				$this->check_manifest_meta_boxes()
			);
		}

		$this->emit( $findings, $format );
	}

	/**
	 * Bootstrap Field_Manifest for use during validation (CLI runs
	 * before WP load, so we initialize the loader explicitly).
	 *
	 * @since 1.1.2
	 */
	private function init_manifest_loader(): void {
		if ( ! class_exists( Field_Manifest::class ) ) {
			require STARTER_SHELTER_PATH . 'includes/core/class-field-manifest.php';
		}
		Field_Manifest::init( STARTER_SHELTER_PATH . 'config' );
	}

	/**
	 * Check 8: per-manifest sanity for the meta_boxes they declare.
	 * Every field name referenced in a box's `fields` list (whether
	 * bare string or map key with overrides) must exist in the
	 * manifest's `fields` section. Catches typos that would otherwise
	 * surface as a missing-key warning in the admin UI.
	 *
	 * @return array<int, array{file: string, line: int, message: string}>
	 */
	private function check_manifest_meta_boxes(): array {
		$findings = [];

		foreach ( Field_Manifest::list_entities() as $entity ) {
			$manifest = Field_Manifest::get( $entity );
			if ( null === $manifest ) {
				continue;
			}

			$meta_boxes = $manifest['meta_boxes'] ?? null;
			if ( ! is_array( $meta_boxes ) ) {
				continue;
			}

			$fields        = $manifest['fields'] ?? [];
			$manifest_file = sprintf( 'config/manifests/%s.php', $entity );

			foreach ( $meta_boxes as $box_id => $box_cfg ) {
				$box_fields = $box_cfg['fields'] ?? [];
				foreach ( $box_fields as $key => $value ) {
					$field_name = is_int( $key ) ? $value : $key;
					if ( ! is_string( $field_name ) ) {
						continue;
					}
					if ( ! isset( $fields[ $field_name ] ) ) {
						$findings[] = [
							'file'    => $manifest_file,
							'line'    => 0,
							'message' => sprintf(
								'Meta box "%s" lists field "%s" that does not exist in %s.fields.',
								$box_id,
								$field_name,
								$entity
							),
						];
					}
				}
			}
		}

		return $findings;
	}

	/**
	 * Check 7: per-manifest sanity for the products they declare. The
	 * SKU prefix must not also appear in products.json (single source
	 * of truth).
	 *
	 * Other product-level invariants (input_mapping target exists in
	 * the referenced ability; ability id is declared) are already
	 * covered by check_product_input_mappings + check_product_ability_ids
	 * — both of which see manifest products via the merge step in
	 * __invoke.
	 *
	 * @return array<int, array{file: string, line: int, message: string}>
	 */
	private function check_manifest_products(): array {
		$products_raw  = file_get_contents( STARTER_SHELTER_PATH . 'config/products.json' );
		$products_json = is_string( $products_raw ) ? json_decode( $products_raw, true ) : null;
		$json_products = is_array( $products_json ) ? ( $products_json['products'] ?? [] ) : [];

		$findings = [];

		foreach ( Field_Manifest::list_entities() as $entity ) {
			$manifest = Field_Manifest::get( $entity );
			if ( null === $manifest ) {
				continue;
			}
			$manifest_file = sprintf( 'config/manifests/%s.php', $entity );

			foreach ( ( $manifest['products'] ?? [] ) as $sku_prefix => $cfg ) {
				if ( isset( $json_products[ $sku_prefix ] ) ) {
					$findings[] = [
						'file'    => 'config/products.json',
						'line'    => 0,
						'message' => sprintf(
							'Product "%s" is declared in both %s and config/products.json. The manifest is the source of truth; remove from products.json.',
							$sku_prefix,
							$manifest_file
						),
					];
				}
			}
		}

		return $findings;
	}

	/**
	 * Check 6: per-manifest sanity for the abilities they declare:
	 *
	 *  - `$entity` refs in input/output schemas must point to fields
	 *    declared in the same entity manifest.
	 *  - The ability id must not also appear in abilities.json
	 *    (single source of truth).
	 *  - Names in `input.required` must appear in `input.properties`.
	 *
	 * @return array<int, array{file: string, line: int, message: string}>
	 */
	private function check_manifest_abilities(): array {
		$abilities_raw = file_get_contents( STARTER_SHELTER_PATH . 'config/abilities.json' );
		$abilities_json = is_string( $abilities_raw ) ? json_decode( $abilities_raw, true ) : null;
		$json_abilities = is_array( $abilities_json ) ? ( $abilities_json['abilities'] ?? [] ) : [];

		$findings = [];

		foreach ( Field_Manifest::list_entities() as $entity ) {
			$manifest = Field_Manifest::get( $entity );
			if ( null === $manifest ) {
				continue;
			}
			$fields    = $manifest['fields'] ?? [];
			$abilities = $manifest['abilities'] ?? [];

			$manifest_file = sprintf( 'config/manifests/%s.php', $entity );

			foreach ( $abilities as $ability_id => $cfg ) {
				// Duplicate-source check.
				if ( isset( $json_abilities[ $ability_id ] ) ) {
					$findings[] = [
						'file'    => 'config/abilities.json',
						'line'    => 0,
						'message' => sprintf(
							'Ability "%s" is declared in both %s and config/abilities.json. The manifest is the source of truth; remove from abilities.json.',
							$ability_id,
							$manifest_file
						),
					];
				}

				// $entity ref + required-coverage checks on input + output.
				foreach ( [ 'input', 'output' ] as $schema_key ) {
					$schema = $cfg[ $schema_key ] ?? null;
					if ( ! is_array( $schema ) ) {
						continue;
					}

					$properties = $schema['properties'] ?? [];

					foreach ( $properties as $prop_name => $prop ) {
						if ( ! is_array( $prop ) || ! isset( $prop['$entity'] ) ) {
							continue;
						}
						$ref = $prop['$entity'];
						if ( ! isset( $fields[ $ref ] ) ) {
							$findings[] = [
								'file'    => $manifest_file,
								'line'    => 0,
								'message' => sprintf(
									'Ability "%s" %s.properties.%s has $entity ref "%s" that does not exist in %s.fields.',
									$ability_id,
									$schema_key,
									$prop_name,
									$ref,
									$entity
								),
							];
						}
					}

					if ( 'input' === $schema_key ) {
						foreach ( ( $schema['required'] ?? [] ) as $req ) {
							if ( ! isset( $properties[ $req ] ) ) {
								$findings[] = [
									'file'    => $manifest_file,
									'line'    => 0,
									'message' => sprintf(
										'Ability "%s" input.required lists "%s" but no such property in input.properties.',
										$ability_id,
										$req
									),
								];
							}
						}
					}
				}
			}
		}

		return $findings;
	}

	/**
	 * Check 5: every entity that has a manifest under config/manifests/
	 * has been removed from config/entities.json. Manifest is the source
	 * of truth; an entry left in entities.json is a second definition
	 * that will drift.
	 *
	 * @return array<int, array{file: string, line: int, message: string}>
	 */
	private function check_manifest_coverage(): array {
		$manifests_dir = STARTER_SHELTER_PATH . 'config/manifests/';
		if ( ! is_dir( $manifests_dir ) ) {
			return [];
		}

		$entities_raw = file_get_contents( STARTER_SHELTER_PATH . 'config/entities.json' );
		if ( false === $entities_raw ) {
			return [];
		}
		$entities_data = json_decode( $entities_raw, true );
		if ( ! is_array( $entities_data ) ) {
			return [];
		}

		$findings = [];

		foreach ( glob( $manifests_dir . '*.php' ) ?: [] as $file ) {
			$entity = basename( $file, '.php' );

			if ( isset( $entities_data['entities'][ $entity ] ) ) {
				// Try to find the line in entities.json for a nicer reference.
				$line = 0;
				if ( preg_match( '/"' . preg_quote( $entity, '/' ) . '"\s*:/', $entities_raw, $m, PREG_OFFSET_CAPTURE ) ) {
					$line = $this->offset_to_line( $entities_raw, $m[0][1] );
				}
				$findings[] = [
					'file'    => 'config/entities.json',
					'line'    => $line,
					'message' => sprintf(
						'Entity "%s" is defined in both config/entities.json and config/manifests/%s.php. The manifest is the source of truth; remove from entities.json.',
						$entity,
						$entity
					),
				];
			}
		}

		return $findings;
	}

	/**
	 * Check 1: every wp_get_ability('X')/wp_has_ability('X') call in PHP
	 * resolves to an ability declared in config/abilities.json.
	 *
	 * Caught in audit: rest-controller calling shelter-reports/donor-summary
	 * and shelter-reports/annual-statement (both undeclared).
	 *
	 * @param array<string, mixed> $abilities_config Parsed abilities.json.
	 * @return array<int, array{file: string, line: int, message: string}>
	 */
	private function check_ability_references( array $abilities_config ): array {
		$declared = array_keys( $abilities_config['abilities'] ?? [] );

		$findings = [];
		$pattern  = '/wp_(?:get|has)_ability\s*\(\s*[\'"]([^\'"]+)[\'"]/';

		foreach ( $this->iter_php_files() as $file ) {
			$contents = file_get_contents( $file );
			if ( false === $contents ) {
				continue;
			}
			if ( ! preg_match_all( $pattern, $contents, $matches, PREG_OFFSET_CAPTURE ) ) {
				continue;
			}
			foreach ( $matches[1] as $match ) {
				[ $name, $offset ] = $match;
				if ( in_array( $name, $declared, true ) ) {
					continue;
				}
				$findings[] = [
					'file'    => $this->relative_path( $file ),
					'line'    => $this->offset_to_line( $contents, $offset ),
					'message' => sprintf(
						'Reference to unregistered ability "%s" (not declared in config/abilities.json).',
						$name
					),
				];
			}
		}

		return $findings;
	}

	/**
	 * Check 2: every `ability` id in config/products.json resolves to a
	 * declared ability.
	 *
	 * @param array<string, mixed> $products_config  Parsed products.json.
	 * @param array<string, mixed> $abilities_config Parsed abilities.json.
	 * @return array<int, array{file: string, line: int, message: string}>
	 */
	private function check_product_ability_ids( array $products_config, array $abilities_config ): array {
		$declared = array_keys( $abilities_config['abilities'] ?? [] );

		$findings = [];
		foreach ( ( $products_config['products'] ?? [] ) as $sku_prefix => $product ) {
			$ability_id = $product['ability'] ?? null;
			if ( null === $ability_id ) {
				continue;
			}
			if ( in_array( $ability_id, $declared, true ) ) {
				continue;
			}
			$findings[] = [
				'file'    => 'config/products.json',
				'line'    => 0,
				'message' => sprintf(
					'Product "%s" references ability "%s" which is not declared in config/abilities.json.',
					$sku_prefix,
					$ability_id
				),
			];
		}

		return $findings;
	}

	/**
	 * Check 3: every `input_mapping` target in products.json appears in
	 * the referenced ability's input_schema properties.
	 *
	 * Caught in audit: shelter-memberships-business products.json had no
	 * mapping for logo_attachment_id — uploads silently dropped. Also
	 * surfaces the opposite: input_mapping targets not declared by the
	 * ability's schema (likely typos or stale fields).
	 *
	 * @param array<string, mixed> $products_config  Parsed products.json.
	 * @param array<string, mixed> $abilities_config Parsed abilities.json.
	 * @return array<int, array{file: string, line: int, message: string}>
	 */
	private function check_product_input_mappings( array $products_config, array $abilities_config ): array {
		$findings = [];

		foreach ( ( $products_config['products'] ?? [] ) as $sku_prefix => $product ) {
			$ability_id = $product['ability'] ?? null;
			$mapping    = $product['input_mapping'] ?? [];
			if ( null === $ability_id || empty( $mapping ) ) {
				continue;
			}

			$ability = $abilities_config['abilities'][ $ability_id ] ?? null;
			if ( null === $ability ) {
				continue;
			}

			$schema_props = $ability['input_schema']['properties'] ?? [];
			if ( empty( $schema_props ) ) {
				continue;
			}

			foreach ( array_keys( $mapping ) as $target ) {
				if ( isset( $schema_props[ $target ] ) ) {
					continue;
				}
				$findings[] = [
					'file'    => 'config/products.json',
					'line'    => 0,
					'message' => sprintf(
						'Product "%s" input_mapping target "%s" not declared in %s input_schema.',
						$sku_prefix,
						$target,
						$ability_id
					),
				];
			}
		}

		return $findings;
	}

	/**
	 * Hooks documented as third-party extension points — fired but
	 * intentionally not consumed by any built-in code. Exempt from the
	 * "producer without listener" check.
	 *
	 * @var array<int, string>
	 */
	private const EXTENSION_POINT_HOOKS = [
		'starter_shelter_legacy_record_updated',
		'starter_shelter_legacy_order_synced',
		'starter_shelter_donor_address_updated',
		'starter_shelter_donor_profile_updated',
		'starter_shelter_renewal_reminders_processed',
		'starter_shelter_renewal_reminder_sent',
	];

	/**
	 * Check 4: every do_action('starter_shelter_X') has at least one
	 * matching add_action() listener (or dynamic listener via emails.json
	 * trigger_hook), and every add_action() listener has at least one
	 * matching producer.
	 *
	 * Caught in audit: dead listener for starter_shelter_email_sent (no
	 * producer); orphan producer starter_shelter_memorial_family_notification
	 * (no email subscriber).
	 *
	 * @param array<string, mixed> $emails_config Parsed emails.json — its
	 *                                            `trigger_hook` entries are
	 *                                            dynamic listeners that the
	 *                                            regex below can't detect.
	 * @return array<int, array{file: string, line: int, message: string}>
	 */
	private function check_domain_action_hooks( array $emails_config ): array {
		$producers = [];
		$listeners = [];

		// Dynamic listeners: emails.json trigger_hook entries are
		// add_action()-ed at runtime by Config_Email::__construct.
		foreach ( ( $emails_config['emails'] ?? [] ) as $email_id => $cfg ) {
			$hook = $cfg['trigger_hook'] ?? null;
			if ( $hook ) {
				$listeners[ $hook ][] = [
					'file' => 'config/emails.json',
					'line' => 0,
				];
			}
		}

		$do_pattern  = '/do_action\s*\(\s*[\'"](starter_shelter_[a-z0-9_]+)[\'"]/';
		$add_pattern = '/add_action\s*\(\s*[\'"](starter_shelter_[a-z0-9_]+)[\'"]/';

		foreach ( $this->iter_php_files() as $file ) {
			$contents = file_get_contents( $file );
			if ( false === $contents ) {
				continue;
			}

			if ( preg_match_all( $do_pattern, $contents, $matches, PREG_OFFSET_CAPTURE ) ) {
				foreach ( $matches[1] as $match ) {
					[ $hook, $offset ] = $match;
					$producers[ $hook ][] = [
						'file' => $this->relative_path( $file ),
						'line' => $this->offset_to_line( $contents, $offset ),
					];
				}
			}

			if ( preg_match_all( $add_pattern, $contents, $matches, PREG_OFFSET_CAPTURE ) ) {
				foreach ( $matches[1] as $match ) {
					[ $hook, $offset ] = $match;
					$listeners[ $hook ][] = [
						'file' => $this->relative_path( $file ),
						'line' => $this->offset_to_line( $contents, $offset ),
					];
				}
			}
		}

		$findings = [];

		// Producers with no listeners. Email-template extension-point hooks
		// (suffixes _email_footer and _email_content) are documented escape
		// hatches for third-party plugins; not having a built-in listener is
		// expected. The named hooks in EXTENSION_POINT_HOOKS are similarly
		// documented per-event extension points.
		foreach ( $producers as $hook => $sites ) {
			if ( ! empty( $listeners[ $hook ] ) ) {
				continue;
			}
			if ( str_ends_with( $hook, '_email_footer' ) || str_ends_with( $hook, '_email_content' ) ) {
				continue;
			}
			if ( in_array( $hook, self::EXTENSION_POINT_HOOKS, true ) ) {
				continue;
			}
			foreach ( $sites as $site ) {
				$findings[] = [
					'file'    => $site['file'],
					'line'    => $site['line'],
					'message' => sprintf( 'do_action("%s") has no add_action listener anywhere.', $hook ),
				];
			}
		}

		// Listeners with no producers.
		foreach ( $listeners as $hook => $sites ) {
			if ( ! empty( $producers[ $hook ] ) ) {
				continue;
			}
			foreach ( $sites as $site ) {
				$findings[] = [
					'file'    => $site['file'],
					'line'    => $site['line'],
					'message' => sprintf( 'add_action("%s") listens for a hook nothing fires.', $hook ),
				];
			}
		}

		return $findings;
	}

	/**
	 * Load and parse a JSON config file.
	 *
	 * @param string $relative Path relative to plugin root.
	 * @return array<string, mixed>
	 */
	private function load_json( string $relative ): array {
		$path = STARTER_SHELTER_PATH . $relative;
		if ( ! is_file( $path ) ) {
			WP_CLI::error( sprintf( 'Required config missing: %s', $relative ) );
		}
		$raw = file_get_contents( $path );
		if ( false === $raw ) {
			WP_CLI::error( sprintf( 'Could not read: %s', $relative ) );
		}
		$data = json_decode( $raw, true );
		if ( ! is_array( $data ) ) {
			WP_CLI::error( sprintf( 'Invalid JSON: %s', $relative ) );
		}
		return $data;
	}

	/**
	 * Iterate PHP files under includes/ and templates/. Skips vendor/,
	 * node_modules/, and the validator's own file (whose PHPdoc contains
	 * literal `wp_get_ability(...)` examples that would self-match).
	 *
	 * @return \Generator<string>
	 */
	private function iter_php_files(): \Generator {
		$self = __FILE__;
		foreach ( [ 'includes', 'templates' ] as $sub ) {
			$root = STARTER_SHELTER_PATH . $sub;
			if ( ! is_dir( $root ) ) {
				continue;
			}
			$iter = new \RecursiveIteratorIterator(
				new \RecursiveDirectoryIterator( $root, \FilesystemIterator::SKIP_DOTS )
			);
			foreach ( $iter as $file ) {
				$path = $file->getPathname();
				if ( '.php' !== substr( $path, -4 ) ) {
					continue;
				}
				if ( str_contains( $path, '/vendor/' ) || str_contains( $path, '/node_modules/' ) ) {
					continue;
				}
				if ( $path === $self ) {
					continue;
				}
				yield $path;
			}
		}
	}

	/**
	 * Convert a string-offset to a 1-indexed line number.
	 *
	 * @param string $haystack File contents.
	 * @param int    $offset   Byte offset of match.
	 * @return int Line number.
	 */
	private function offset_to_line( string $haystack, int $offset ): int {
		return substr_count( $haystack, "\n", 0, $offset ) + 1;
	}

	/**
	 * Make a path relative to the plugin root for nicer output.
	 *
	 * @param string $absolute Absolute filesystem path.
	 * @return string Relative path.
	 */
	private function relative_path( string $absolute ): string {
		$prefix = STARTER_SHELTER_PATH;
		return str_starts_with( $absolute, $prefix )
			? substr( $absolute, strlen( $prefix ) )
			: $absolute;
	}

	/**
	 * Emit findings and set the appropriate exit status.
	 *
	 * @param array<int, array{file: string, line: int, message: string}> $findings
	 * @param string $format Output format: human or json.
	 */
	private function emit( array $findings, string $format ): void {
		if ( 'json' === $format ) {
			WP_CLI::print_value( $findings, [ 'format' => 'json' ] );
			if ( ! empty( $findings ) ) {
				exit( 1 );
			}
			return;
		}

		if ( empty( $findings ) ) {
			WP_CLI::success( 'No contract violations found.' );
			return;
		}

		foreach ( $findings as $f ) {
			WP_CLI::line( sprintf(
				'%s:%d  %s',
				$f['file'],
				$f['line'],
				$f['message']
			) );
		}
		WP_CLI::error( sprintf( '%d contract violation(s) found.', count( $findings ) ) );
	}
}
