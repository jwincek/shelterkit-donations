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
			if ( isset( $manifest[ $key ] ) ) {
				$section[ $key ] = $manifest[ $key ];
			}
		}

		return $section;
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

		foreach ( [ 'required', 'oneOf', 'anyOf', 'allOf' ] as $key ) {
			if ( isset( $schema[ $key ] ) ) {
				$out[ $key ] = $schema[ $key ];
			}
		}

		$props = [];
		foreach ( $schema['properties'] ?? [] as $name => $prop ) {
			$props[ $name ] = self::resolve_property( $prop, $entity_fields );
		}
		$out['properties'] = $props;

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

		return array_merge( $base, $overrides );
	}

	/**
	 * List entity names that have a manifest on disk.
	 *
	 * @since 1.1.2
	 *
	 * @return string[]
	 */
	public static function list_entities(): array {
		if ( null === self::$manifests_path || ! is_dir( self::$manifests_path ) ) {
			return [];
		}

		$entities = [];
		foreach ( glob( self::$manifests_path . '*.php' ) ?: [] as $file ) {
			$entities[] = basename( $file, '.php' );
		}

		sort( $entities );
		return $entities;
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
