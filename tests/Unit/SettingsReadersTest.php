<?php
/**
 * Every setting is read by something, and everything read is a setting (#49).
 *
 * The settings screen had eight fields nothing read: Email From Name and
 * Address, Logo Moderation Notifications, four feature switches and the
 * fiscal year's start month. Changing them did nothing, and the screen gave
 * no sign of it. The other way round, the donors list read a
 * `donor_dashboard_page` setting that no screen offered, so its link never
 * appeared. And config/settings.json had five sections nothing read.
 *
 * @package Starter_Shelter
 */

declare( strict_types = 1 );

namespace Starter_Shelter\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class SettingsReadersTest extends TestCase {

	private const SETTINGS_FILE = 'includes/admin/class-settings.php';

	/**
	 * Stored keys that only the settings class itself reads, and why.
	 */
	private const READ_BY_SETTINGS = [
		'org_phone'   => 'Moved into Shelter Details by Settings::maybe_migrate_organization_to_profile() (3.0.0).',
		'org_address' => 'Moved into Shelter Details by Settings::maybe_migrate_organization_to_profile() (3.0.0).',
	];

	private string $root;

	protected function setUp(): void {
		$this->root = dirname( __DIR__, 2 ) . '/';
	}

	public function test_every_settings_field_is_read_outside_the_settings_screen(): void {
		// The fields registered by name, and every key with a default, so a
		// field registered in a loop (as the feature switches were) is seen.
		$fields = array_unique( array_merge( $this->fields(), $this->default_keys() ) );
		$this->assertGreaterThan( 8, count( $this->fields() ), 'The scan should find the settings fields.' );

		$read   = $this->keys_read() + array_fill_keys( array_keys( self::READ_BY_SETTINGS ), [ self::SETTINGS_FILE ] );
		$unread = array_values( array_diff( $fields, array_keys( $read ) ) );

		$this->assertSame( [], $unread, 'Nothing reads these settings, so changing them does nothing. Read each where its feature is, or remove it.' );
	}

	public function test_every_setting_read_is_one_the_plugin_has(): void {
		$known   = $this->default_keys();
		$unknown = [];
		foreach ( $this->keys_read() as $key => $where ) {
			if ( ! in_array( $key, $known, true ) ) {
				$unknown[] = $key . ' (read in ' . implode( ', ', array_unique( $where ) ) . ')';
			}
		}

		$this->assertSame( [], $unknown, 'These settings are read, but the plugin has no such setting, so they are always their fallback.' );
	}

	public function test_every_section_of_settings_json_is_read(): void {
		$sections = array_keys( (array) json_decode( (string) file_get_contents( $this->root . 'config/settings.json' ), true ) );
		$this->assertNotEmpty( $sections );

		$source = '';
		foreach ( $this->files() as $file ) {
			$source .= file_get_contents( $file );
		}
		$unread = array_values( array_filter(
			$sections,
			static fn( string $section ) => ! preg_match( '/get_item\(\s*\'settings\',\s*\'' . preg_quote( $section, '/' ) . '\'/', $source )
		) );

		$this->assertSame( [], $unread, 'Nothing reads these sections of config/settings.json.' );
	}

	/** @return string[] The field ids the settings screen registers. */
	private function fields(): array {
		preg_match_all( '/self::add_field\(\s*\'([a-z0-9_]+)\'/', (string) file_get_contents( $this->root . self::SETTINGS_FILE ), $m );
		return array_unique( $m[1] );
	}

	/** @return string[] The keys get_defaults() returns: the fields, plus the organisation fields kept for the receipts. */
	private function default_keys(): array {
		$source = (string) file_get_contents( $this->root . self::SETTINGS_FILE );
		preg_match( '/function get_defaults\(\).*?return \[(.*?)\];/s', $source, $body );
		preg_match_all( '/\'([a-z0-9_]+)\'\s*=>/', $body[1] ?? '', $m );
		return $m[1];
	}

	/**
	 * Keys read outside the settings screen, with where.
	 *
	 * @return array<string, string[]>
	 */
	private function keys_read(): array {
		$read = [];
		foreach ( $this->files() as $file ) {
			$relative = substr( $file, strlen( $this->root ) );
			if ( self::SETTINGS_FILE === $relative ) {
				continue;
			}
			$source = (string) file_get_contents( $file );
			$keys   = [];

			// Settings::get( 'x' ), and Settings::get( $a ? 'x' : 'y', … ).
			preg_match_all( '/\bSettings::get\(([^;]*)/', $source, $calls );
			foreach ( $calls[1] as $args ) {
				preg_match_all( '/\'([a-z0-9_]+)\'/', $args, $quoted );
				$keys = array_merge( $keys, $quoted[1] );
			}
			// Settings::is_feature_enabled( 'x' ) reads feature_x.
			preg_match_all( '/\bis_feature_enabled\(\s*\'([a-z0-9_]+)\'/', $source, $features );
			foreach ( $features[1] as $feature ) {
				$keys[] = 'feature_' . $feature;
			}
			// The raw option, where the plugin isn't loaded (uninstall).
			if ( str_contains( $source, "get_option( 'starter_shelter_options'" ) ) {
				preg_match_all( '/\$options\[\s*\'([a-z0-9_]+)\'\s*\]/', $source, $raw );
				$keys = array_merge( $keys, $raw[1] );
			}

			foreach ( $keys as $key ) {
				$read[ $key ][] = $relative;
			}
		}
		return $read;
	}

	/** @return string[] */
	private function files(): array {
		$files = [ $this->root . 'shelterkit-donations.php', $this->root . 'uninstall.php' ];
		foreach ( [ 'includes', 'blocks', 'templates' ] as $dir ) {
			$iterator = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $this->root . $dir, \FilesystemIterator::SKIP_DOTS ) );
			foreach ( $iterator as $file ) {
				$path = $file->getPathname();
				if ( str_ends_with( $path, '.php' ) && ! str_contains( $path, '/build/' ) ) {
					$files[] = $path;
				}
			}
		}
		return array_filter( $files, 'is_file' );
	}
}
