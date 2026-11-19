<?php
/**
 * Nothing shows a stored date by reading it as UTC (#62).
 *
 * `wp_date( $format, strtotime( $stored ) )` reads a date the plugin stored
 * in the site's local time as UTC, then shows it in the site's zone: hours
 * early, or the day before. Use Helpers\local_date() / local_timestamp().
 *
 * strtotime() of an offset from now ("+30 days", "-6 months") is a real
 * moment, so wp_date() shows it correctly; those are allowed.
 *
 * @package Starter_Shelter
 */

declare( strict_types = 1 );

namespace Starter_Shelter\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class LocalDatesGuardTest extends TestCase {

	public function test_no_stored_date_is_read_as_utc_for_display(): void {
		$root  = dirname( __DIR__, 2 ) . '/';
		$found = [];
		foreach ( [ 'includes', 'blocks', 'templates' ] as $dir ) {
			$files = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $root . $dir, \FilesystemIterator::SKIP_DOTS ) );
			foreach ( $files as $file ) {
				$path = (string) $file;
				if ( ! str_ends_with( $path, '.php' ) ) {
					continue;
				}
				$source = (string) file_get_contents( $path );
				preg_match_all( '/\bwp_date\([^;]*?\bstrtotime\(\s*([^)]*)\)/', $source, $m, PREG_OFFSET_CAPTURE );
				foreach ( $m[1] as [ $argument, $offset ] ) {
					// An offset from now is a real moment.
					if ( preg_match( '/^[\'"][+-]/', trim( $argument ) ) ) {
						continue;
					}
					$found[] = substr( $path, strlen( $root ) ) . ':' . ( substr_count( substr( $source, 0, $offset ), "\n" ) + 1 ) . ' strtotime(' . trim( $argument ) . ')';
				}
			}
		}

		$this->assertSame( [], $found, 'These read a stored local date as UTC. Use Helpers\local_date() or local_timestamp().' );
	}

	public function test_no_timestamp_is_shifted_by_the_zone_offset(): void {
		$root  = dirname( __DIR__, 2 ) . '/';
		$found = [];
		foreach ( [ 'includes', 'blocks', 'templates' ] as $dir ) {
			$files = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $root . $dir, \FilesystemIterator::SKIP_DOTS ) );
			foreach ( $files as $file ) {
				$path = (string) $file;
				if ( ! str_ends_with( $path, '.php' ) ) {
					continue;
				}
				$source = self::code( (string) file_get_contents( $path ) );
				// mysql2date( 'U' | 'G' ) and current_time( 'timestamp' ) return a
				// timestamp plus the zone's offset; wp_date() applies it again.
				preg_match_all( "/\\b(mysql2date\\(\\s*'[UG]'|current_time\\(\\s*'(?:timestamp|U)')/", $source, $m, PREG_OFFSET_CAPTURE );
				foreach ( $m[1] as [ $call, $offset ] ) {
					$found[] = substr( $path, strlen( $root ) ) . ':' . ( substr_count( substr( $source, 0, $offset ), "\n" ) + 1 ) . ' ' . $call;
				}
			}
		}

		$this->assertSame( [], $found, 'These return a timestamp shifted by the zone\'s offset. Use Helpers\local_timestamp() or time().' );
	}

	/**
	 * The source without its comments, which may quote the old calls; line
	 * numbers are kept.
	 *
	 * @param string $source PHP source.
	 */
	private static function code( string $source ): string {
		$out = '';
		foreach ( token_get_all( $source ) as $token ) {
			if ( is_array( $token ) && in_array( $token[0], [ T_COMMENT, T_DOC_COMMENT ], true ) ) {
				$out .= str_repeat( "\n", substr_count( $token[1], "\n" ) );
				continue;
			}
			$out .= is_array( $token ) ? $token[1] : $token;
		}
		return $out;
	}
}
