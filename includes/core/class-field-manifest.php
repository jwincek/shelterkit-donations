<?php
/**
 * Field Manifest loader.
 *
 * Loads PHP-array manifests from `config/manifests/<entity>.php` and
 * exposes accessors that produce shapes compatible with the legacy
 * JSON config layout. Lets the manifest progressively replace the
 * sources of truth that currently live in entities.json,
 * abilities.json, products.json, emails.json, etc.
 *
 * @package Starter_Shelter
 * @subpackage Core
 * @since 1.1.2
 */

declare( strict_types = 1 );

namespace Starter_Shelter\Core;

/**
 * Loads field manifests and projects them into per-config shapes.
 *
 * @since 1.1.2
 */
class Field_Manifest {

	/**
	 * Cache of loaded manifests keyed by entity name.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private static array $cache = [];

	/**
	 * Path to manifests directory. Set by init().
	 *
	 * @var string|null
	 */
	private static ?string $manifests_path = null;

	/**
	 * Initialize with a path to the manifests directory.
	 *
	 * @since 1.1.2
	 *
	 * @param string $config_path Path to the plugin's config directory.
	 */
	public static function init( string $config_path ): void {
		self::$manifests_path = trailingslashit( $config_path ) . 'manifests/';
	}

	/**
	 * Get the raw manifest array for an entity, or null if none exists.
	 *
	 * @since 1.1.2
	 *
	 * @param string $entity Entity name (e.g., 'sd_membership').
	 * @return array<string, mixed>|null
	 */
	public static function get( string $entity ): ?array {
		if ( array_key_exists( $entity, self::$cache ) ) {
			return self::$cache[ $entity ];
		}

		if ( null === self::$manifests_path ) {
			return self::$cache[ $entity ] = null;
		}

		$file = self::$manifests_path . $entity . '.php';
		if ( ! is_file( $file ) ) {
			return self::$cache[ $entity ] = null;
		}

		$data = include $file;
		if ( ! is_array( $data ) ) {
			return self::$cache[ $entity ] = null;
		}

		return self::$cache[ $entity ] = $data;
	}

	/**
	 * Get the entities.json-shaped section for an entity, or null if
	 * no manifest exists.
	 *
	 * Output is the same shape as `entities.json['entities'][$entity]`:
	 * `{ meta_prefix, fields, computed, relations }`.
	 *
	 * @since 1.1.2
	 *
	 * @param string $entity Entity name.
	 * @return array<string, mixed>|null
	 */
	public static function get_entity_section( string $entity ): ?array {
		$manifest = self::get( $entity );
		if ( null === $manifest ) {
			return null;
		}

		$section = [];
		foreach ( [ 'meta_prefix', 'fields', 'computed', 'relations' ] as $key ) {
			if ( ! isset( $manifest[ $key ] ) ) {
				continue;
			}
			if ( 'fields' === $key ) {
				// Strip UI-only `form` sub-blocks — those are surface
				// metadata for meta-boxes / checkout-fields projectors,
				// not entity-schema attributes.
				$section['fields'] = array_map( [ self::class, 'strip_internal_keys' ], $manifest['fields'] );
			} else {
				$section[ $key ] = $manifest[ $key ];
			}
		}

		return $section;
	}

	/**
	 * Strip manifest-internal keys from an entity-field definition
	 * before it appears in the merged entities.json:
	 *
	 *  - `form`: UI metadata for meta-boxes / checkout-fields projectors.
	 *  - `properties`: nested-object shape used by the validator's
	 *    placeholder-path walker; not consumed by Entity_Hydrator.
	 *
	 * @since 1.1.2
	 *
	 * @param array<string, mixed> $field Field definition.
	 * @return array<string, mixed>
	 */
	private static function strip_internal_keys( array $field ): array {
		unset( $field['form'], $field['properties'] );
		return $field;
	}

	/**
	 * Strip entity-only keys when an entity field is being projected
	 * into a JSON Schema (ability input/output property). In addition
	 * to the keys stripped by `strip_internal_keys()`, also drop
	 * `show_in_rest` (entity-storage hint that isn't a JSON Schema attr).
	 *
	 * @since 1.1.2
	 *
	 * @param array<string, mixed> $field Field definition.
	 * @return array<string, mixed>
	 */
	private static function strip_storage_keys( array $field ): array {
		unset( $field['form'], $field['properties'], $field['show_in_rest'] );
		return $field;
	}

	/**
	 * Collect ability declarations across all manifests, projected into
	 * the abilities.json shape (`{ "<ability-id>": {...config...} }`).
	 *
	 * Each ability's input_schema/output_schema is resolved by walking
	 * the manifest-author shape (with `$entity` refs and ability-local
	 * fields) into a flat JSON Schema. Refs that point to unknown
	 * entity fields are emitted with the override-only data; the
	 * dangling ref is surfaced separately by `wp starter-shelter validate`.
	 *
	 * @since 1.1.2
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function get_abilities(): array {
		$out = [];

		foreach ( self::list_entities() as $entity ) {
			$manifest = self::get( $entity );
			if ( null === $manifest ) {
				continue;
			}

			$fields    = $manifest['fields'] ?? [];
			$abilities = $manifest['abilities'] ?? [];

			foreach ( $abilities as $ability_id => $cfg ) {
				$out[ $ability_id ] = self::project_ability( $cfg, $fields );
			}
		}

		return $out;
	}

	/**
	 * Project one ability declaration into the abilities.json shape.
	 *
	 * @since 1.1.2
	 *
	 * @param array<string, mixed> $cfg          Manifest-author ability config.
	 * @param array<string, mixed> $entity_fields The entity's `fields` map.
	 * @return array<string, mixed>
	 */
	private static function project_ability( array $cfg, array $entity_fields ): array {
		$out = [];

		// Top-level fields pass through unchanged.
		foreach ( [ 'label', 'description', 'category', 'callback', 'permission', 'meta' ] as $key ) {
			if ( isset( $cfg[ $key ] ) ) {
				$out[ $key ] = $cfg[ $key ];
			}
		}

		// Default `category` from the ability id prefix if not declared
		// (e.g., 'shelter-memberships/create' → 'shelter-memberships').
		// This is a convenience for manifests; abilities.json typically
		// declares category explicitly. The merge step (see Config) sets
		// it from the manifest's entity context anyway.

		// input_schema / output_schema.
		foreach ( [ 'input' => 'input_schema', 'output' => 'output_schema' ] as $src => $dst ) {
			if ( ! isset( $cfg[ $src ] ) ) {
				continue;
			}
			$out[ $dst ] = self::project_schema( $cfg[ $src ], $entity_fields );
		}

		return $out;
	}

	/**
	 * Project a single schema block (input or output) by resolving
	 * `$entity` refs in its `properties` map.
	 *
	 * @since 1.1.2
	 *
	 * @param array<string, mixed> $schema        Manifest-author schema block.
	 * @param array<string, mixed> $entity_fields The entity's `fields` map.
	 * @return array<string, mixed> JSON Schema object.
	 */
	private static function project_schema( array $schema, array $entity_fields ): array {
		$out = [ 'type' => 'object' ];

		foreach ( [ 'description', 'required', 'oneOf', 'anyOf', 'allOf' ] as $key ) {
			if ( isset( $schema[ $key ] ) ) {
				$out[ $key ] = $schema[ $key ];
			}
		}

		// Only emit a `properties` block when one was declared — some
		// schemas (e.g., shelter-donations/get output) are description-only.
		if ( isset( $schema['properties'] ) ) {
			$props = [];
			foreach ( $schema['properties'] as $name => $prop ) {
				$props[ $name ] = self::resolve_property( $prop, $entity_fields );
			}
			$out['properties'] = $props;
		}

		return $out;
	}

	/**
	 * Resolve a single property: if it carries an `$entity` key, merge
	 * the referenced entity field's shape with sibling overrides.
	 *
	 * If the ref points to an unknown field, the override-only data is
	 * returned (validator surfaces the dangling ref separately).
	 *
	 * @since 1.1.2
	 *
	 * @param array<string, mixed> $prop          Manifest-author property.
	 * @param array<string, mixed> $entity_fields The entity's `fields` map.
	 * @return array<string, mixed>
	 */
	private static function resolve_property( array $prop, array $entity_fields ): array {
		if ( ! isset( $prop['$entity'] ) ) {
			return $prop;
		}

		$ref_name  = $prop['$entity'];
		$overrides = $prop;
		unset( $overrides['$entity'] );

		$base = $entity_fields[ $ref_name ] ?? [];
		$base = self::strip_storage_keys( $base );

		return array_merge( $base, $overrides );
	}

	/**
	 * Collect email declarations across all manifests, projected into
	 * the emails.json shape (`{ "<email-id>": {...config...} }`).
	 *
	 * Emails pass through verbatim — the same shape Config_Email
	 * consumes today. Per-email placeholder paths are validated by
	 * `wp starter-shelter validate --check=manifests` against the
	 * referenced entities' field/computed/object-properties trees.
	 *
	 * @since 1.1.2
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function get_emails(): array {
		$out = [];

		foreach ( self::list_entities() as $entity ) {
			$manifest = self::get( $entity );
			if ( null === $manifest ) {
				continue;
			}

			foreach ( $manifest['emails'] ?? [] as $email_id => $cfg ) {
				$out[ $email_id ] = $cfg;
			}
		}

		return $out;
	}

	/**
	 * Collect product declarations across all manifests, projected into
	 * the products.json shape (`{ "<sku-prefix>": {...config...} }`).
	 *
	 * Manifest product entries are passed through verbatim — unlike
	 * abilities, the product mapping config has no `$entity` ref
	 * convention (the values are mapping rules, not field shapes).
	 *
	 * @since 1.1.2
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function get_products(): array {
		$out = [];

		foreach ( self::list_entities() as $entity ) {
			$manifest = self::get( $entity );
			if ( null === $manifest ) {
				continue;
			}

			foreach ( $manifest['products'] ?? [] as $sku_prefix => $cfg ) {
				$out[ $sku_prefix ] = $cfg;
			}
		}

		return $out;
	}

	/**
	 * Collect checkout-field declarations across all manifests,
	 * projected into the flat shape `Checkout_Fields::load_field_definitions()`
	 * produces. Each entry's UI shape combines:
	 *
	 *  - `fields.<name>.form` (intrinsic: label, input_type → type)
	 *  - `checkout_fields.<name>` overlay (placeholder, required,
	 *    priority, class, product_types, conditional, options)
	 *  - `meta_key` derived from `meta_prefix + field_name`
	 *
	 * Labels and placeholders are i18n'd at projection time when
	 * WordPress is loaded; CLI-time validation works with plain strings.
	 *
	 * @since 1.1.2
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function get_checkout_fields(): array {
		$out = [];

		// Iterates all manifests (entities + shared) so the `_shared`
		// manifest's checkout fields are projected alongside entity-owned
		// ones.
		foreach ( self::list_all_manifests() as $name ) {
			$manifest = self::get( $name );
			if ( null === $manifest ) {
				continue;
			}

			$checkout_fields = $manifest['checkout_fields'] ?? null;
			if ( ! is_array( $checkout_fields ) ) {
				continue;
			}

			$fields      = $manifest['fields'] ?? [];
			$meta_prefix = $manifest['meta_prefix'] ?? '_';

			foreach ( $checkout_fields as $field_name => $overlay ) {
				$out[ $field_name ] = self::project_checkout_field(
					$field_name,
					$fields[ $field_name ]['form'] ?? [],
					is_array( $overlay ) ? $overlay : [],
					$meta_prefix
				);
			}
		}

		return $out;
	}

	/**
	 * Project one checkout field into the legacy config shape.
	 *
	 * Overlay attrs override the field's intrinsic `form` shape — the
	 * same field can render differently per surface (e.g., sd_donation
	 * dedication is a textarea in meta-boxes but a text input at
	 * checkout). Entries without a matching entity field are allowed
	 * if the overlay supplies its own input_type and label (e.g.,
	 * campaign_id, which lives as a taxonomy relation rather than a
	 * meta field).
	 *
	 * @since 1.1.2
	 *
	 * @param string               $field_name  Field name (entity field or self-contained).
	 * @param array<string, mixed> $form        The field's intrinsic form shape (may be empty).
	 * @param array<string, mixed> $overlay     The checkout_fields overlay.
	 * @param string               $meta_prefix Entity meta prefix (for meta_key derivation).
	 * @return array<string, mixed>
	 */
	private static function project_checkout_field( string $field_name, array $form, array $overlay, string $meta_prefix ): array {
		$merged = array_merge( $form, $overlay );

		$out = [];
		if ( isset( $merged['input_type'] ) ) {
			$out['type'] = $merged['input_type'];
		}
		foreach ( [ 'label', 'placeholder', 'description' ] as $key ) {
			if ( isset( $merged[ $key ] ) ) {
				$out[ $key ] = self::translate( $merged[ $key ] );
			}
		}
		foreach ( [ 'required', 'priority', 'class', 'options', 'product_types', 'conditional' ] as $key ) {
			if ( isset( $merged[ $key ] ) ) {
				$out[ $key ] = $merged[ $key ];
			}
		}

		// meta_key is derived; Cart_Handler and friends store under this key.
		$out['meta_key'] = $meta_prefix . $field_name;

		return $out;
	}

	/**
	 * Collect meta-box declarations across all manifests, projected
	 * into the shape `Meta_Boxes::get_meta_box_config()` expects
	 * (keyed by post type).
	 *
	 * Each meta_box.fields entry is either a bare field name (use the
	 * field's intrinsic `form` shape) or a `name => overrides` map
	 * (merge overrides over the form shape).
	 *
	 * Labels are wrapped in `__()` if WordPress is loaded so they
	 * remain translatable at the existing call sites; CLI-time
	 * validation works with plain strings.
	 *
	 * @since 1.1.2
	 *
	 * @return array<string, array{boxes: array<string, array<string, mixed>>}>
	 */
	public static function get_meta_boxes(): array {
		$out = [];

		foreach ( self::list_entities() as $entity ) {
			$manifest = self::get( $entity );
			if ( null === $manifest ) {
				continue;
			}

			$meta_boxes = $manifest['meta_boxes'] ?? null;
			if ( ! is_array( $meta_boxes ) ) {
				continue;
			}

			$fields = $manifest['fields'] ?? [];

			$boxes = [];
			foreach ( $meta_boxes as $box_id => $box_cfg ) {
				$boxes[ $box_id ] = self::project_meta_box( $box_cfg, $fields );
			}

			$out[ $entity ] = [ 'boxes' => $boxes ];
		}

		return $out;
	}

	/**
	 * Project one meta-box declaration into the legacy config shape.
	 *
	 * @since 1.1.2
	 *
	 * @param array<string, mixed> $box_cfg Manifest-author box config.
	 * @param array<string, mixed> $fields  The entity's `fields` map.
	 * @return array<string, mixed>
	 */
	private static function project_meta_box( array $box_cfg, array $fields ): array {
		$out = [];

		foreach ( [ 'title', 'context', 'priority', 'show_when', 'composite_save' ] as $key ) {
			if ( ! isset( $box_cfg[ $key ] ) ) {
				continue;
			}
			$out[ $key ] = ( 'title' === $key ) ? self::translate( $box_cfg[ $key ] ) : $box_cfg[ $key ];
		}

		$projected_fields = [];
		foreach ( $box_cfg['fields'] ?? [] as $key => $value ) {
			// Bare string entry: `'donor_id'` → keyed by integer index,
			// value is the field name.
			// Map entry: `'amount' => [ 'label' => 'Amount Paid' ]` → keyed
			// by field name, value is the override map.
			if ( is_int( $key ) ) {
				$field_name = $value;
				$overrides  = [];
			} else {
				$field_name = $key;
				$overrides  = is_array( $value ) ? $value : [];
			}

			$projected_fields[ $field_name ] = self::project_form_field(
				$field_name,
				$fields[ $field_name ]['form'] ?? [],
				$overrides
			);
		}
		$out['fields'] = $projected_fields;

		return $out;
	}

	/**
	 * Project one field's form shape into the legacy meta-box field
	 * config (keys: type, label, plus pass-through attributes).
	 *
	 * @since 1.1.2
	 *
	 * @param string               $field_name Entity field name (for diagnostics).
	 * @param array<string, mixed> $form       The field's intrinsic form shape.
	 * @param array<string, mixed> $overrides  Per-box overrides.
	 * @return array<string, mixed>
	 */
	private static function project_form_field( string $field_name, array $form, array $overrides ): array {
		$merged = array_merge( $form, $overrides );

		$out = [];
		if ( isset( $merged['input_type'] ) ) {
			$out['type'] = $merged['input_type'];
		}
		if ( isset( $merged['label'] ) ) {
			$out['label'] = self::translate( $merged['label'] );
		}

		// Pass-through attributes consumed by Meta_Boxes::render_field.
		foreach ( [ 'required', 'readonly', 'rows', 'options', 'post_type', 'default', 'description', 'show_when' ] as $key ) {
			if ( isset( $merged[ $key ] ) ) {
				$out[ $key ] = ( 'description' === $key ) ? self::translate( $merged[ $key ] ) : $merged[ $key ];
			}
		}

		return $out;
	}

	/**
	 * i18n a label string if WordPress is loaded; otherwise pass through.
	 *
	 * @param string $text Plain label string from the manifest.
	 * @return string
	 */
	private static function translate( string $text ): string {
		return function_exists( '__' ) ? __( $text, 'starter-shelter' ) : $text;
	}

	/**
	 * List entity names that have a manifest on disk.
	 *
	 * Filters out manifests whose names start with an underscore —
	 * those are non-entity manifests (`_shared.php`) that contribute
	 * fields/checkout entries shared across entities but shouldn't
	 * be promoted to entities.json.
	 *
	 * @since 1.1.2
	 *
	 * @return string[]
	 */
	public static function list_entities(): array {
		return array_values( array_filter(
			self::list_all_manifests(),
			fn( string $name ) => ! str_starts_with( $name, '_' )
		) );
	}

	/**
	 * List every manifest file on disk, including non-entity manifests
	 * (underscore-prefixed names like `_shared`). Share-aware accessors
	 * iterate this wider list so shared fields/checkout entries flow
	 * into the merged config.
	 *
	 * @since 1.1.2
	 *
	 * @return string[]
	 */
	public static function list_all_manifests(): array {
		if ( null === self::$manifests_path || ! is_dir( self::$manifests_path ) ) {
			return [];
		}

		$names = [];
		foreach ( glob( self::$manifests_path . '*.php' ) ?: [] as $file ) {
			$names[] = basename( $file, '.php' );
		}

		sort( $names );
		return $names;
	}

	/**
	 * Clear the manifest cache (test helper).
	 *
	 * @since 1.1.2
	 */
	public static function clear_cache(): void {
		self::$cache = [];
	}
}
