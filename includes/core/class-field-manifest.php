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
