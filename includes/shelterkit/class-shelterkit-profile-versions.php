<?php
/**
 * Which copy of the shelter profile wins.
 *
 * SHARED FILE — identical in every ShelterKit plugin except for the text
 * domain, which MUST be the host plugin's own slug. WordPress.org's Plugin
 * Check treats a foreign domain as an error, not a warning, so a copy carrying
 * a sibling's domain cannot pass review; and `wp i18n make-pot` extracts by
 * domain, so a foreign one leaves these labels out of every POT and
 * untranslatable everywhere. Change nothing else when copying. Edit it here, then
 * copy it across. Do not namespace it and do not rename it: the whole mechanism
 * is that several plugins can carry the same class and exactly one of them
 * defines it.
 *
 * The pattern is Action Scheduler's. Each plugin registers its version and the
 * path to its copy; on plugins_loaded the highest version present is the one
 * loaded. That way a shelter running Pets 1.2 alongside an older Events still
 * gets the newer profile screen, and neither plugin depends on the other being
 * installed at all.
 *
 * @package ShelterKit
 * @version 1.0.0
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'ShelterKit_Profile_Versions' ) ) {

	class ShelterKit_Profile_Versions {

		/** @var array<string, string> version => absolute path to that copy. */
		private static array $versions = array();

		private static bool $loaded = false;

		/**
		 * Announce this plugin's copy. Safe to call from any number of plugins.
		 *
		 * @param string $version Semantic version of the copy.
		 * @param string $file    Absolute path to class-shelterkit-profile.php.
		 */
		public static function register( string $version, string $file ): void {
			self::$versions[ $version ] = $file;
		}

		/**
		 * Load the highest registered copy, once.
		 */
		public static function load(): void {
			if ( self::$loaded || class_exists( 'ShelterKit_Profile' ) ) {
				return;
			}

			$winner = self::winner();

			if ( is_string( $winner ) && is_readable( $winner ) ) {
				require_once $winner;
				self::$loaded = true;
			}
		}

		/**
		 * Which copy would be loaded. Separated from load() so the choice can be
		 * asserted without the side effect of actually requiring a file — the
		 * class can only be defined once per process, so a test cannot exercise
		 * load() twice.
		 *
		 * @return string|null Absolute path, or null when nothing is registered.
		 */
		public static function winner(): ?string {
			if ( ! self::$versions ) {
				return null;
			}

			$versions = self::$versions;
			uksort( $versions, 'version_compare' );

			$winner = end( $versions );

			return is_string( $winner ) ? $winner : null;
		}

		/**
		 * The version that won, for support questions and for tests.
		 */
		public static function active_version(): string {
			return defined( 'ShelterKit_Profile::VERSION' ) || class_exists( 'ShelterKit_Profile' )
				? (string) constant( 'ShelterKit_Profile::VERSION' )
				: '';
		}

		/**
		 * Every registered copy, for diagnosing which plugins carry one.
		 *
		 * @return array<string, string>
		 */
		public static function registered(): array {
			return self::$versions;
		}
	}

	// Priority 0: before anything that might want to read the profile, and
	// after every plugin file has run its own register() call.
	add_action( 'plugins_loaded', array( 'ShelterKit_Profile_Versions', 'load' ), 0 );
}
