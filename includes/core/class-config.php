<?php
/**
 * Config loader with $ref resolution.
 *
 * @package Starter_Shelter
 * @since 1.0.0
 */

declare( strict_types = 1 );

namespace Starter_Shelter\Core;

/**
 * Loads and caches configuration from JSON files with $ref resolution.
 *
 * @since 1.0.0
 */
class Config {

    /**
     * Cache of loaded configurations.
     *
     * @var array<string, array>
     */
    private static array $cache = [];

    /**
     * Path to config directory.
     *
     * @var string|null
     */
    private static ?string $config_path = null;

    /**
     * Initialize the config loader with path.
     *
     * @since 1.0.0
     *
     * @param string $path Path to config directory.
     */
    public static function init( string $path ): void {
        self::$config_path = trailingslashit( $path );

        // Initialize the field manifest loader in lockstep — Config::get('entities')
        // merges manifest sections, so the two paths must stay paired.
        Field_Manifest::init( $path );
    }

    /**
     * Get a config file by name.
     *
     * @since 1.0.0
     *
     * @param string $name Config file name (without .json extension).
     * @return array The parsed config data.
     */
    /**
     * Config keys that support admin overrides from the options table.
     *
     * Each entry maps a config file name to an array of top-level keys
     * that can be overridden. The admin override is stored as a WordPress
     * option named 'sd_config_{name}_{key}'.
     *
     * @var array<string, string[]>
     */
    private static array $overridable = [
        'settings' => [ 'allocations', 'memorial_types', 'pet_species', 'donor_levels' ],
        'tiers'    => [ 'tiers' ],
    ];

    public static function get( string $name ): array {
        if ( isset( self::$cache[ $name ] ) ) {
            return self::$cache[ $name ];
        }

        $file = self::$config_path . $name . '.json';
        if ( ! file_exists( $file ) ) {
            return [];
        }

        $contents = file_get_contents( $file );
        if ( false === $contents ) {
            return [];
        }

        $data = json_decode( $contents, true );
        if ( ! is_array( $data ) ) {
            return [];
        }

        // Merge in field manifests for the entities config so per-entity
        // PHP manifests at config/manifests/<entity>.php can replace or
        // augment entries that would otherwise live in entities.json.
        if ( 'entities' === $name ) {
            $data = self::merge_field_manifests( $data );
        }

        // Merge in manifest-owned abilities. Same pattern: manifest owns
        // the source; abilities.json gets thinner as entities migrate.
        if ( 'abilities' === $name ) {
            $data = self::merge_manifest_abilities( $data );
        }

        // Merge in manifest-owned products (cart-to-ability input_mapping
        // per product SKU prefix). Same pattern.
        if ( 'products' === $name ) {
            $data = self::merge_manifest_products( $data );
        }

        // Merge in manifest-owned emails (WooCommerce email definitions
        // with placeholder paths into hydrated entities). Same pattern.
        if ( 'emails' === $name ) {
            $data = self::merge_manifest_emails( $data );
        }

        // Resolve $ref references recursively. Runs AFTER manifest merges
        // so refs contributed by manifests (e.g., sd_memorial's
        // notify_family pointing to schemas/notify-family.json) are
        // resolved alongside refs already in the JSON.
        $data = self::resolve_refs( $data );

        // Apply admin overrides from the options table.
        $data = self::apply_overrides( $name, $data );

        self::$cache[ $name ] = $data;
        return $data;
    }

    /**
     * Merge Field_Manifest entity sections into the entities config.
     *
     * Each manifest replaces (not deep-merges) the corresponding entity
     * section. Drift between an entities.json entry and a manifest is
     * surfaced by `wp starter-shelter validate`, not silently resolved.
     *
     * @since 1.1.2
     *
     * @param array $data Parsed entities.json data.
     * @return array Data with manifest sections merged in.
     */
    private static function merge_field_manifests( array $data ): array {
        $entities = $data['entities'] ?? [];

        foreach ( Field_Manifest::list_entities() as $entity ) {
            $section = Field_Manifest::get_entity_section( $entity );
            if ( null !== $section ) {
                $entities[ $entity ] = $section;
            }
        }

        $data['entities'] = $entities;
        return $data;
    }

    /**
     * Merge manifest-owned ability declarations into the abilities config.
     *
     * Each manifest ability replaces (not deep-merges) the corresponding
     * abilities.json entry. The validator catches drift if both sources
     * declare the same ability.
     *
     * @since 1.1.2
     *
     * @param array $data Parsed abilities.json data.
     * @return array Data with manifest abilities merged in.
     */
    private static function merge_manifest_abilities( array $data ): array {
        $abilities = $data['abilities'] ?? [];

        foreach ( Field_Manifest::get_abilities() as $ability_id => $cfg ) {
            $abilities[ $ability_id ] = $cfg;
        }

        $data['abilities'] = $abilities;
        return $data;
    }

    /**
     * Merge manifest-owned product declarations into the products config.
     *
     * Each manifest product replaces (not deep-merges) the corresponding
     * products.json entry. The validator catches drift if both sources
     * declare the same SKU prefix.
     *
     * @since 1.1.2
     *
     * @param array $data Parsed products.json data.
     * @return array Data with manifest products merged in.
     */
    private static function merge_manifest_products( array $data ): array {
        $products = $data['products'] ?? [];

        foreach ( Field_Manifest::get_products() as $sku_prefix => $cfg ) {
            $products[ $sku_prefix ] = $cfg;
        }

        $data['products'] = $products;
        return $data;
    }

    /**
     * Merge manifest-owned email declarations into the emails config.
     *
     * Each manifest email replaces the corresponding emails.json entry.
     * Validator catches drift if both sources declare the same email id.
     *
     * @since 1.1.2
     *
     * @param array $data Parsed emails.json data.
     * @return array Data with manifest emails merged in.
     */
    private static function merge_manifest_emails( array $data ): array {
        $emails = $data['emails'] ?? [];

        foreach ( Field_Manifest::get_emails() as $email_id => $cfg ) {
            $emails[ $email_id ] = $cfg;
        }

        $data['emails'] = $emails;
        return $data;
    }

    /**
     * Apply admin overrides from the options table.
     *
     * JSON config provides defaults. Admin-edited values (stored as
     * 'sd_config_{name}_{key}' options) take precedence when present.
     *
     * @since 2.1.0
     *
     * @param string $name Config file name.
     * @param array  $data Config data from JSON.
     * @return array Config with overrides applied.
     */
    private static function apply_overrides( string $name, array $data ): array {
        $keys = self::$overridable[ $name ] ?? [];

        foreach ( $keys as $key ) {
            $option = get_option( 'sd_config_' . $name . '_' . $key );

            if ( false !== $option && is_array( $option ) ) {
                $data[ $key ] = $option;
            }
        }

        return $data;
    }

    /**
     * Save an admin override for a config key.
     *
     * @since 2.1.0
     *
     * @param string $name  Config file name.
     * @param string $key   Top-level key to override.
     * @param array  $value The override value.
     * @return bool True if saved, false if not an overridable key.
     */
    public static function save_override( string $name, string $key, array $value ): bool {
        $keys = self::$overridable[ $name ] ?? [];

        if ( ! in_array( $key, $keys, true ) ) {
            return false;
        }

        update_option( 'sd_config_' . $name . '_' . $key, $value );
        self::clear_cache( $name );

        return true;
    }

    /**
     * Delete an admin override, reverting to JSON defaults.
     *
     * @since 2.1.0
     *
     * @param string $name Config file name.
     * @param string $key  Top-level key to revert.
     */
    public static function delete_override( string $name, string $key ): void {
        delete_option( 'sd_config_' . $name . '_' . $key );
        self::clear_cache( $name );
    }

    /**
     * Check if a config key has an admin override.
     *
     * @since 2.1.0
     *
     * @param string $name Config file name.
     * @param string $key  Top-level key.
     * @return bool True if override exists.
     */
    public static function has_override( string $name, string $key ): bool {
        return false !== get_option( 'sd_config_' . $name . '_' . $key );
    }

    /**
     * Get a specific item from a config file.
     *
     * @since 1.0.0
     *
     * @param string $name    Config file name.
     * @param string $key     Key to retrieve.
     * @param mixed  $default Default value if key not found.
     * @return mixed The config value or default.
     */
    public static function get_item( string $name, string $key, $default = null ) {
        $config = self::get( $name );
        return $config[ $key ] ?? $default;
    }

    /**
     * Get a nested item using dot notation.
     *
     * @since 1.0.0
     *
     * @param string $name    Config file name.
     * @param string $path    Dot-notation path (e.g., 'entities.sd_donation.fields').
     * @param mixed  $default Default value if path not found.
     * @return mixed The config value or default.
     */
    public static function get_path( string $name, string $path, $default = null ) {
        $config = self::get( $name );
        $keys = explode( '.', $path );

        foreach ( $keys as $key ) {
            if ( ! is_array( $config ) || ! isset( $config[ $key ] ) ) {
                return $default;
            }
            $config = $config[ $key ];
        }

        return $config;
    }

    /**
     * Resolve $ref references recursively.
     *
     * @since 1.0.0
     *
     * @param array $data Data to process.
     * @return array Data with $refs resolved.
     */
    private static function resolve_refs( array $data ): array {
        foreach ( $data as $key => $value ) {
            if ( is_array( $value ) ) {
                // Check if this is a $ref reference.
                if ( isset( $value['$ref'] ) && 1 === count( $value ) ) {
                    $resolved = self::load_ref( $value['$ref'] );
                    if ( null !== $resolved ) {
                        $data[ $key ] = $resolved;
                    }
                } else {
                    // Recursively resolve nested refs.
                    $data[ $key ] = self::resolve_refs( $value );
                }
            }
        }

        return $data;
    }

    /**
     * Load a referenced schema file.
     *
     * @since 1.0.0
     *
     * @param string $ref Reference path (relative to config dir).
     * @return array|null The loaded schema or null on failure.
     */
    private static function load_ref( string $ref ): ?array {
        $file = self::$config_path . $ref;
        if ( ! file_exists( $file ) ) {
            return null;
        }

        $contents = file_get_contents( $file );
        if ( false === $contents ) {
            return null;
        }

        $data = json_decode( $contents, true );
        if ( ! is_array( $data ) ) {
            return null;
        }

        // Recursively resolve any nested refs in the loaded schema.
        return self::resolve_refs( $data );
    }

    /**
     * Clear the config cache.
     *
     * @since 1.0.0
     *
     * @param string|null $name Optional specific config to clear.
     */
    public static function clear_cache( ?string $name = null ): void {
        if ( null === $name ) {
            self::$cache = [];
        } else {
            unset( self::$cache[ $name ] );
        }
    }

    /**
     * Get the config path.
     *
     * @since 1.0.0
     *
     * @return string|null The config path.
     */
    public static function get_config_path(): ?string {
        return self::$config_path;
    }
}
